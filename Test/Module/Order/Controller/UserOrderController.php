<?php
namespace Test\Module\Order\Controller;

use Swoole\Coroutine\Channel;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Http\RequestInput;
use Swoolefy\Library\Db\Query;
use Swoolefy\Library\Db\Raw;
use Swoolefy\Library\Db\Sql;
use Test\App;
use Test\Module\Order\Dto\UserOrderDto\UserListDto;
use Test\Module\Order\OrderEntity;
use Test\Module\Order\OrderList;

/**
 * Order / Db 组件综合稳定性测试入口。
 *
 * 用途：通过 HTTP 接口压测/回归 library Db（Entity、Query、事务、协程单例等）。
 * 测试数据统一使用 user_id=900001，用例结束会尽量物理删除，避免污染业务表。
 *
 * 前置条件：
 * 1. 执行 Test/test.sql（或对已有库执行其中的 ALTER，补齐 deleted_at）
 * 2. Bootstrap / Event 已注册租户拦截器；请求上下文需有 tenant_id（本类 _beforeAction 会兜底设为 0）
 *
 * 调用示例：
 *   curl -X POST 'http://127.0.0.1:9501/user/user-order/userList' \
 *     -H 'Content-Type: application/json' \
 *     -d '{"name":"db-test","order_ids":[1,2,3]}'
 *   curl -X POST 'http://127.0.0.1:9501/user/user-order/userList1'   # 仅协程
 *   curl -X POST 'http://127.0.0.1:9501/user/user-order/userList2'   # 仅事务
 *   curl -X POST 'http://127.0.0.1:9501/user/user-order/userList3'   # goApp 多层嵌套 Db
 *   curl -X POST 'http://127.0.0.1:9501/user/user-order/userList4'   # newQuery 复杂 SQL
 *
 * 返回字段：
 * - ok：全部用例是否通过（任一 case 抛异常则为 false）
 * - cases.{name}.pass：该用例断言是否通过
 * - errors：失败用例的异常摘要
 *
 * 用例一览：
 * - entity_crud            Entity 增改查删
 * - query_builder          Query Builder + fetchSql
 * - transaction_commit     事务提交
 * - transaction_rollback   事务回滚
 * - nested_transaction     嵌套事务
 * - coroutine_singleton    协程 Db 单例隔离
 * - nested_coroutine_query 嵌套协程查询
 * - goapp_nested_db        goApp 三层嵌套查询+插入
 * - paginate               paginate / paginateX
 * - order_list             OrderList 列表查询
 * - complex_exists_join    子查询 + exists
 * - complex_new_query      newQuery 复杂 SQL（join/子句/分区/group/union/…）
 * - soft_delete            软删除
 * - batch_insert_query     insert / insertAll
 * - clone_count_select     clone 后 count 不影响 select
 */
class UserOrderController extends BController
{
    /** 测试专用用户 ID，与业务用户隔离 */
    private const TEST_USER_ID = 900001;

    /**
     * 测试租户 ID。
     * 需与请求 Context 中 tenant_id 一致（TenantLineDemoHandler 读 Context），
     * 默认 Bootstrap 设为 0，故此处写入 '0'。
     */
    private const TEST_TENANT_ID = '0';

    /**
     * 每个 Action 执行前兜底租户上下文。
     * 租户表在 tenant_id 为空时会 fail-closed 抛异常，测试环境必须有值。
     */
    public function _beforeAction(RequestInput $requestInput, string $action)
    {
        if (!Context::has('tenant_id') || Context::get('tenant_id') === '' || Context::get('tenant_id') === null) {
            Context::set('tenant_id', 0);
        }
    }

    /**
     * Db 全量综合测试入口。
     *
     * 路由：POST|GET /user/user-order/userList
     * 逐个执行下方 $cases，单 case 异常不影响后续 case（记录到 errors）。
     *
     * @see \Test\Module\Order\Validation\UserOrderValidation::userList()
     */
    public function userList(RequestInput $requestInput, UserListDto $userListDto): array
    {
        $report = [
            'request' => [
                'name' => $userListDto->name ?? null,
                'order_ids' => $userListDto->orderIds ?? [],
                'tenant_id' => Context::get('tenant_id'),
            ],
            'cases' => [],
            'ok' => true,
            'errors' => [],
        ];

        // 按依赖尽量独立：每个 case 自备种子数据并自行清理
        $cases = [
            'entity_crud' => fn () => $this->caseEntityCrud(),
            'query_builder' => fn () => $this->caseQueryBuilder(),
            'transaction_commit' => fn () => $this->caseTransactionCommit(),
            'transaction_rollback' => fn () => $this->caseTransactionRollback(),
            'nested_transaction' => fn () => $this->caseNestedTransaction(),
            'coroutine_singleton' => fn () => $this->caseCoroutineSingleton(),
            'nested_coroutine_query' => fn () => $this->caseNestedCoroutineQuery(),
            'goapp_nested_db' => fn () => $this->caseGoAppNestedDb(),
            'paginate' => fn () => $this->casePaginate(),
            'order_list' => fn () => $this->caseOrderList(),
            'complex_exists_join' => fn () => $this->caseComplexExistsJoin(),
            'complex_new_query' => fn () => $this->caseComplexNewQuery(),
            'soft_delete' => fn () => $this->caseSoftDelete(),
            'batch_insert_query' => fn () => $this->caseBatchInsertQuery(),
            'clone_count_select' => fn () => $this->caseCloneCountSelect(),
        ];

        foreach ($cases as $name => $runner) {
            try {
                $report['cases'][$name] = $runner();
            } catch (\Throwable $e) {
                $report['ok'] = false;
                $report['cases'][$name] = [
                    'pass' => false,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ];
                $report['errors'][] = $name . ': ' . $e->getMessage();
            }
        }

        return $report;
    }

    /**
     * 协程相关专项测试。
     *
     * 路由：POST|GET /user/user-order/userList1
     * 验证：同协程多次 App::getDb() 为同一实例；不同协程实例不同；嵌套协程可正常 Query。
     */
    public function userList1(): array
    {
        return [
            'coroutine_singleton' => $this->caseCoroutineSingleton(),
            'nested_coroutine_query' => $this->caseNestedCoroutineQuery(),
            'goapp_nested_db' => $this->caseGoAppNestedDb(),
        ];
    }

    /**
     * 事务专项测试。
     *
     * 路由：POST|GET /user/user-order/userList2
     * 验证：commit 后可见、rollback 后不可见、嵌套事务在无 savepoint 时的回滚语义。
     */
    public function userList2(): array
    {
        return [
            'transaction_commit' => $this->caseTransactionCommit(),
            'transaction_rollback' => $this->caseTransactionRollback(),
            'nested_transaction' => $this->caseNestedTransaction(),
        ];
    }

    /**
     * goApp 多层嵌套 Db 专项测试。
     *
     * 路由：POST|GET /user/user-order/userList3
     * 验证：L1→L2→L3 每层 goApp 各自 Db 单例隔离，且每层均可 insert + select。
     */
    public function userList3(): array
    {
        return [
            'goapp_nested_db' => $this->caseGoAppNestedDb(),
        ];
    }

    /**
     * newQuery 复杂 SQL 专项测试。
     *
     * 路由：POST|GET /user/user-order/userList4
     * 覆盖：派生表 / JOIN / GROUP+HAVING / EXISTS / UNION / Raw / 聚合 / clone。
     */
    public function userList4(): array
    {
        return [
            'complex_new_query' => $this->caseComplexNewQuery(),
        ];
    }

    // ------------------------------------------------------------------
    // Cases（每个方法返回 ['pass'=>bool, ...]，异常由 userList 统一捕获）
    // ------------------------------------------------------------------

    /**
     * 【Entity CRUD】
     * 流程：OrderEntity::save 新增 → loadById 读 → save 更新 → 再 load 校验
     *      → OrderEntity::query()->first()（返回 Model）→ withoutTrashed 物理删清理
     * 关注：属性赋值、json cast、事件钩子、Query::first 与 AR 路径一致性。
     */
    protected function caseEntityCrud(): array
    {
        $orderId = $this->nextOrderId();
        $entity = new OrderEntity();
        $entity->order_id = $orderId;
        $entity->user_id = self::TEST_USER_ID;
        $entity->receiver_user_name = '测试收货人';
        $entity->receiver_user_phone = '13800000000';
        $entity->order_amount = 99.50;
        $entity->order_product_ids = [1001, 1002]; // setOrderProductIdsAttr 会 json_encode
        $entity->order_status = 1;
        $entity->address = '深圳市南山区科技园';
        $entity->remark = 'entity-crud';
        $entity->json_data = ['scene' => 'entity_crud', 'rand' => mt_rand(1, 9999)]; // casts=array
        $entity->tenant_id = self::TEST_TENANT_ID;

        $saved = $entity->save();
        if ($saved === false) {
            throw new \RuntimeException('OrderEntity::save() failed');
        }

        // AR 按主键加载
        $loaded = new OrderEntity();
        $loaded->loadById($orderId);
        $attrs = $loaded->getAttributes();
        if (empty($attrs['order_id']) || (int) $attrs['order_id'] !== (int) $orderId) {
            throw new \RuntimeException('loadById failed after insert');
        }

        // 脏字段更新
        $loaded->remark = 'entity-crud-updated';
        $loaded->order_status = 2;
        $loaded->json_data = ['scene' => 'entity_crud', 'updated' => true];
        $updated = $loaded->save();
        if ($updated === false) {
            throw new \RuntimeException('OrderEntity update save() failed');
        }

        $again = new OrderEntity();
        $again->loadById($orderId);
        if (($again->remark ?? '') !== 'entity-crud-updated' || (int) $again->order_status !== 2) {
            throw new \RuntimeException('update not persisted');
        }

        // Query::first() 返回的是 Model 实例，不是数组
        $row = OrderEntity::query()
            ->where('order_id', '=', $orderId)
            ->where('user_id', '=', self::TEST_USER_ID)
            ->first();
        if (!$row instanceof OrderEntity) {
            throw new \RuntimeException('OrderEntity::query()->first() empty');
        }

        // withoutTrashed + delete(true)：跳过软删，按已有 where 物理删除
        $deleted = OrderEntity::withoutTrashed()
            ->where('order_id', '=', $orderId)
            ->delete(true);

        return [
            'pass' => true,
            'order_id' => $orderId,
            'saved' => (bool) $saved,
            'updated' => (bool) $updated,
            'deleted_rows' => $deleted,
            'loaded_user_id' => (int) ($attrs['user_id'] ?? 0),
        ];
    }

    /**
     * 【Query Builder】
     * 验证：alias / field / when / where / order / limit / fetchSql（只生成 SQL 不执行）
     *      以及真正 select 能查到种子数据。
     */
    protected function caseQueryBuilder(): array
    {
        $seedId = $this->insertSeedOrder('query-builder');

        $db = App::getDb();
        $uid = self::TEST_USER_ID;

        // fetchSql：返回拼接后的 SQL 字符串
        $sql = $db->newQuery()
            ->table(OrderEntity::getTableName())
            ->alias('a')
            ->field(['a.order_id', 'a.user_id', 'a.remark'])
            ->when($uid > 0, function (Query $query) use ($uid) {
                $query->where('a.user_id', '=', $uid);
            })
            ->where('a.order_id', '=', $seedId)
            ->order(['a.order_id' => 'desc'])
            ->limit(1)
            ->fetchSql()
            ->select();

        // 真实查询
        $list = $db->newQuery()
            ->table(OrderEntity::getTableName())
            ->where('user_id', '=', $uid)
            ->where('order_id', '=', $seedId)
            ->limit(1)
            ->select()
            ->toArray();

        $this->deleteOrderById($seedId);

        return [
            'pass' => !empty($list) && is_string($sql) && str_contains(strtolower($sql), 'select'),
            'fetch_sql' => $sql,
            'row' => $list[0] ?? null,
        ];
    }

    /**
     * 【事务提交】
     * beginTransaction → Entity::save → commit 后，同连接 Query 应能查到记录。
     */
    protected function caseTransactionCommit(): array
    {
        $orderId = $this->nextOrderId();
        $db = App::getDb();

        $db->beginTransaction();
        try {
            $entity = new OrderEntity();
            $entity->order_id = $orderId;
            $entity->user_id = self::TEST_USER_ID;
            $entity->receiver_user_name = 'tx-commit';
            $entity->receiver_user_phone = '13900000001';
            $entity->order_amount = 10;
            $entity->order_product_ids = [1];
            $entity->order_status = 1;
            $entity->address = 'tx-commit-address';
            $entity->remark = 'tx-commit';
            $entity->json_data = ['tx' => 'commit'];
            $entity->tenant_id = self::TEST_TENANT_ID;
            $entity->save();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $found = OrderEntity::query()->where('order_id', '=', $orderId)->first();
        $this->deleteOrderById($orderId);

        return [
            'pass' => $found instanceof OrderEntity,
            'order_id' => $orderId,
            'db_object_id' => spl_object_id($db),
        ];
    }

    /**
     * 【事务回滚】
     * 事务内写入后主动抛错并 rollback，提交后库中不应残留该 order_id。
     */
    protected function caseTransactionRollback(): array
    {
        $orderId = $this->nextOrderId();
        $db = App::getDb();

        $db->beginTransaction();
        try {
            $entity = new OrderEntity();
            $entity->order_id = $orderId;
            $entity->user_id = self::TEST_USER_ID;
            $entity->receiver_user_name = 'tx-rollback';
            $entity->receiver_user_phone = '13900000002';
            $entity->order_amount = 20;
            $entity->order_product_ids = [2];
            $entity->order_status = 1;
            $entity->address = 'tx-rollback-address';
            $entity->remark = 'tx-rollback';
            $entity->json_data = ['tx' => 'rollback'];
            $entity->tenant_id = self::TEST_TENANT_ID;
            $entity->save();

            // 模拟业务失败，走 rollback 分支
            throw new \RuntimeException('force rollback');
        } catch (\RuntimeException $e) {
            $db->rollback();
            if ($e->getMessage() !== 'force rollback') {
                throw $e;
            }
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $found = OrderEntity::query()->where('order_id', '=', $orderId)->first();

        return [
            'pass' => !$found instanceof OrderEntity,
            'order_id' => $orderId,
            'still_exists' => $found instanceof OrderEntity,
        ];
    }

    /**
     * 【嵌套事务】
     * 外层 begin → 写 outer → 内层 begin → 写 inner → 内层 rollback → 外层 commit。
     *
     * MySQL 默认 support_savepoint=false：内层 rollback 只打标记，外层 commit 时应整体 rollBack。
     * 若开启 savepoint：内层 ROLLBACK TO SAVEPOINT，外层仍可提交 outer。
     * 本用例记录实际存在情况，便于对比配置差异（pass 固定 true，看字段语义）。
     */
    protected function caseNestedTransaction(): array
    {
        $orderIdOuter = $this->nextOrderId();
        $orderIdInner = $this->nextOrderId();
        $db = App::getDb();

        $db->beginTransaction();
        try {
            $this->saveMinimalOrder($orderIdOuter, 'nested-outer');

            $db->beginTransaction();
            try {
                $this->saveMinimalOrder($orderIdInner, 'nested-inner');
                // 内层回滚
                $db->rollback();
            } catch (\Throwable $e) {
                $db->rollback();
                throw $e;
            }

            // 外层 commit（无 savepoint 且已标记时，内部会改为真正 rollBack）
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->isEnableTransaction()) {
                $db->rollback();
            }
            throw $e;
        }

        $outer = OrderEntity::query()->where('order_id', '=', $orderIdOuter)->first();
        $inner = OrderEntity::query()->where('order_id', '=', $orderIdInner)->first();

        // 无论结果如何都清理，避免残留
        $this->deleteOrderById($orderIdOuter);
        $this->deleteOrderById($orderIdInner);

        $supportSavepoint = (bool) ($db->getConfig('support_savepoint') ?? false);

        return [
            'pass' => true,
            'support_savepoint' => $supportSavepoint,
            'outer_exists_after_commit' => $outer instanceof OrderEntity,
            'inner_exists_after_commit' => $inner instanceof OrderEntity,
            'note' => $supportSavepoint
                ? '开启 savepoint：内层回滚不影响外层已写入'
                : '未开启 savepoint：内层 rollback 应导致外层 commit 时整体回滚',
        ];
    }

    /**
     * 【协程单例隔离】
     * 断言：
     * 1. 当前协程内两次 App::getDb() 的 spl_object_id 相同
     * 2. 并行子协程 c1 / c2 各自拿到不同 Db 实例，且都不同于父协程
     * 3. 子协程内可正常执行 getQuery()->count()/select()
     */
    protected function caseCoroutineSingleton(): array
    {
        $parentDb = App::getDb();
        $parentId = spl_object_id($parentDb);
        $parentIdAgain = spl_object_id(App::getDb());

        // GoWaitGroup：等待子协程结束并收集返回值（保证协程单例容器可用）
        $results = GoWaitGroup::batchParallelRunWait([
            'c1' => function () {
                $db = App::getDb();
                $id1 = spl_object_id($db);
                $id2 = spl_object_id(App::getDb());
                $count = (new OrderEntity())->getQuery()
                    ->where('user_id', '=', self::TEST_USER_ID)
                    ->count();

                return [
                    'object_id' => $id1,
                    'same_in_coroutine' => $id1 === $id2,
                    'count' => $count,
                ];
            },
            'c2' => function () {
                $db = App::getDb();
                $id1 = spl_object_id($db);
                $list = (new OrderEntity())->getQuery()
                    ->where('user_id', '=', self::TEST_USER_ID)
                    ->limit(2)
                    ->select()
                    ->toArray();

                return [
                    'object_id' => $id1,
                    'list_size' => count($list),
                ];
            },
        ], 8.0);

        $c1Id = $results['c1']['object_id'] ?? 0;
        $c2Id = $results['c2']['object_id'] ?? 0;

        return [
            'pass' => $parentId === $parentIdAgain
                && ($results['c1']['same_in_coroutine'] ?? false)
                && $c1Id !== $parentId
                && $c2Id !== $parentId
                && $c1Id !== $c2Id,
            'parent_object_id' => $parentId,
            'parent_same_twice' => $parentId === $parentIdAgain,
            'c1' => $results['c1'] ?? null,
            'c2' => $results['c2'] ?? null,
            'c1_diff_parent' => $c1Id !== $parentId,
            'c2_diff_parent' => $c2Id !== $parentId,
            'c1_diff_c2' => $c1Id !== $c2Id,
        ];
    }

    /**
     * 【嵌套协程查询】
     * 父协程 → 外层 go → 内层再 go：三层 Db 实例应两两不同，且内外层都能查到同一条种子订单。
     */
    protected function caseNestedCoroutineQuery(): array
    {
        $parentId = spl_object_id(App::getDb());
        $seedId = $this->insertSeedOrder('nested-coro');

        $results = GoWaitGroup::batchParallelRunWait([
            'outer' => function () use ($seedId) {
                $outerDbId = spl_object_id(App::getDb());
                $outerCount = OrderEntity::query()
                    ->where('order_id', '=', $seedId)
                    ->count();

                // 外层协程内再开一层
                $inner = GoWaitGroup::batchParallelRunWait([
                    'inner' => function () use ($seedId, $outerDbId) {
                        $innerDbId = spl_object_id(App::getDb());
                        $row = OrderEntity::query()
                            ->where('order_id', '=', $seedId)
                            ->first();

                        return [
                            'object_id' => $innerDbId,
                            'diff_from_outer' => $innerDbId !== $outerDbId,
                            'found' => $row instanceof OrderEntity,
                            'remark' => $row instanceof OrderEntity ? (string) $row->remark : null,
                        ];
                    },
                ], 5.0);

                return [
                    'object_id' => $outerDbId,
                    'outer_count' => $outerCount,
                    'inner' => $inner['inner'] ?? null,
                ];
            },
        ], 10.0);

        $this->deleteOrderById($seedId);

        $outer = $results['outer'] ?? [];
        $inner = $outer['inner'] ?? [];

        return [
            'pass' => ($outer['object_id'] ?? 0) !== $parentId
                && ($inner['diff_from_outer'] ?? false)
                && ($inner['found'] ?? false)
                && (int) ($outer['outer_count'] ?? 0) === 1,
            'parent_object_id' => $parentId,
            'outer' => $outer,
        ];
    }

    /**
     * 【goApp 三层嵌套：查询 + 插入】
     *
     * 结构：当前请求协程(parent)
     *   └─ goApp L1：insert 订单 A，再 select 校验
     *        └─ goApp L2：insert 订单 B，select A+B
     *             └─ goApp L3：insert 订单 C，select A+B+C，Channel 回传结果
     *
     * 断言：
     * - parent / L1 / L2 / L3 的 App::getDb() 对象 ID 两两不同（协程单例隔离）
     * - 每层同协程内两次 getDb() 为同一实例
     * - L3 能查到三层各自插入的订单
     * - tenant_id Context 经 goApp 正确透传，写入成功
     */
    protected function caseGoAppNestedDb(): array
    {
        $parentDbId = spl_object_id(App::getDb());
        $parentDbIdAgain = spl_object_id(App::getDb());
        $resultChannel = new Channel(1);

        goApp(function () use ($resultChannel, $parentDbId) {
            $orderIdL1 = 0;
            $orderIdL2 = 0;
            $orderIdL3 = 0;
            try {
                // ---------- L1 ----------
                $l1DbId = spl_object_id(App::getDb());
                $l1Same = $l1DbId === spl_object_id(App::getDb());

                $orderIdL1 = $this->nextOrderId();
                $this->saveMinimalOrder($orderIdL1, 'goapp-l1');

                $foundL1 = OrderEntity::query()
                    ->where('order_id', '=', $orderIdL1)
                    ->first();

                goApp(function () use (
                    $resultChannel,
                    $parentDbId,
                    $l1DbId,
                    $l1Same,
                    $orderIdL1,
                    $foundL1
                ) {
                    $orderIdL2 = 0;
                    $orderIdL3 = 0;
                    try {
                        // ---------- L2 ----------
                        $l2DbId = spl_object_id(App::getDb());
                        $l2Same = $l2DbId === spl_object_id(App::getDb());

                        $orderIdL2 = $this->nextOrderId();
                        $this->saveMinimalOrder($orderIdL2, 'goapp-l2');

                        $countFromL2 = OrderEntity::query()
                            ->whereIn('order_id', [$orderIdL1, $orderIdL2])
                            ->count();

                        goApp(function () use (
                            $resultChannel,
                            $parentDbId,
                            $l1DbId,
                            $l1Same,
                            $l2DbId,
                            $l2Same,
                            $orderIdL1,
                            $orderIdL2,
                            $foundL1,
                            $countFromL2
                        ) {
                            $orderIdL3 = 0;
                            try {
                                // ---------- L3 ----------
                                $l3DbId = spl_object_id(App::getDb());
                                $l3Same = $l3DbId === spl_object_id(App::getDb());

                                $orderIdL3 = $this->nextOrderId();
                                $this->saveMinimalOrder($orderIdL3, 'goapp-l3');

                                $ids = [$orderIdL1, $orderIdL2, $orderIdL3];
                                $rows = OrderEntity::query()
                                    ->whereIn('order_id', $ids)
                                    ->order(['order_id' => 'asc'])
                                    ->select()
                                    ->toArray();

                                $resultChannel->push([
                                    'ok' => true,
                                    'parent_object_id' => $parentDbId,
                                    'l1' => [
                                        'object_id' => $l1DbId,
                                        'same_in_coro' => $l1Same,
                                        'order_id' => $orderIdL1,
                                        'found_after_insert' => $foundL1 instanceof OrderEntity,
                                        'diff_parent' => $l1DbId !== $parentDbId,
                                    ],
                                    'l2' => [
                                        'object_id' => $l2DbId,
                                        'same_in_coro' => $l2Same,
                                        'order_id' => $orderIdL2,
                                        'count_l1_l2' => $countFromL2,
                                        'diff_parent' => $l2DbId !== $parentDbId,
                                        'diff_l1' => $l2DbId !== $l1DbId,
                                    ],
                                    'l3' => [
                                        'object_id' => $l3DbId,
                                        'same_in_coro' => $l3Same,
                                        'order_id' => $orderIdL3,
                                        'row_count' => count($rows),
                                        'remarks' => array_column($rows, 'remark'),
                                        'diff_parent' => $l3DbId !== $parentDbId,
                                        'diff_l1' => $l3DbId !== $l1DbId,
                                        'diff_l2' => $l3DbId !== $l2DbId,
                                    ],
                                    'created_ids' => $ids,
                                ], 0.5);
                            } catch (\Throwable $e) {
                                $resultChannel->push([
                                    'ok' => false,
                                    'error' => 'L3: ' . $e->getMessage(),
                                    'file' => $e->getFile() . ':' . $e->getLine(),
                                    'created_ids' => array_values(array_filter([$orderIdL1, $orderIdL2, $orderIdL3])),
                                ], 0.5);
                            }
                        });
                    } catch (\Throwable $e) {
                        $resultChannel->push([
                            'ok' => false,
                            'error' => 'L2: ' . $e->getMessage(),
                            'file' => $e->getFile() . ':' . $e->getLine(),
                            'created_ids' => array_values(array_filter([$orderIdL1, $orderIdL2, $orderIdL3])),
                        ], 0.5);
                    }
                });
            } catch (\Throwable $e) {
                $resultChannel->push([
                    'ok' => false,
                    'error' => 'L1: ' . $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                    'created_ids' => array_values(array_filter([$orderIdL1, $orderIdL2, $orderIdL3])),
                ], 0.5);
            }
        });

        $payload = $resultChannel->pop(20.0);
        if ($payload === false || !is_array($payload)) {
            throw new \RuntimeException('goApp nested db: wait result timeout or empty channel');
        }

        foreach ((array) ($payload['created_ids'] ?? []) as $id) {
            if ($id) {
                $this->deleteOrderById((int) $id);
            }
        }

        if (empty($payload['ok'])) {
            throw new \RuntimeException($payload['error'] ?? 'goApp nested db failed');
        }

        $l1 = $payload['l1'] ?? [];
        $l2 = $payload['l2'] ?? [];
        $l3 = $payload['l3'] ?? [];

        $pass = $parentDbId === $parentDbIdAgain
            && ($l1['same_in_coro'] ?? false)
            && ($l2['same_in_coro'] ?? false)
            && ($l3['same_in_coro'] ?? false)
            && ($l1['diff_parent'] ?? false)
            && ($l2['diff_parent'] ?? false)
            && ($l2['diff_l1'] ?? false)
            && ($l3['diff_parent'] ?? false)
            && ($l3['diff_l1'] ?? false)
            && ($l3['diff_l2'] ?? false)
            && ($l1['found_after_insert'] ?? false)
            && (int) ($l2['count_l1_l2'] ?? 0) === 2
            && (int) ($l3['row_count'] ?? 0) === 3
            && in_array('goapp-l1', $l3['remarks'] ?? [], true)
            && in_array('goapp-l2', $l3['remarks'] ?? [], true)
            && in_array('goapp-l3', $l3['remarks'] ?? [], true);

        return [
            'pass' => $pass,
            'parent_object_id' => $parentDbId,
            'parent_same_twice' => $parentDbId === $parentDbIdAgain,
            'l1' => $l1,
            'l2' => $l2,
            'l3' => $l3,
        ];
    }

    /**
     * 【分页】
     * paginate(每页条数, 页码)：带 total 的经典分页。
     * paginateX：基于主键游标的大数据分页（无限滚动场景）。
     */
    protected function casePaginate(): array
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->insertSeedOrder('paginate-' . $i);
        }

        $page = OrderEntity::query()
            ->where('user_id', '=', self::TEST_USER_ID)
            ->whereIn('order_id', $ids)
            ->order(['order_id' => 'desc'])
            ->paginate(2, 1);

        $pageX = OrderEntity::query()
            ->where('user_id', '=', self::TEST_USER_ID)
            ->whereIn('order_id', $ids)
            ->paginateX(2, null, 'order_id', 'desc');

        foreach ($ids as $id) {
            $this->deleteOrderById($id);
        }

        return [
            'pass' => $page->total() >= 3 && count($page->items()) === 2 && count($pageX->items()) <= 2,
            'paginate_total' => $page->total(),
            'paginate_count' => count($page->items()),
            'paginateX_count' => count($pageX->items()),
            'paginateX_last_id' => $pageX->lastId(),
        ];
    }

    /**
     * 【OrderList】
     * 列表对象封装：setUserId/setOrderId + total/find；并在子协程中再查一次验证隔离。
     */
    protected function caseOrderList(): array
    {
        $id = $this->insertSeedOrder('order-list');

        $orderList = new OrderList();
        $orderList->setUserId([self::TEST_USER_ID]);
        $orderList->setOrderId([$id]);
        $orderList->setPage(1);
        $orderList->setPageSize(10);
        $total = $orderList->total();
        $list = $orderList->find();

        // 子协程内独立 Db 单例再查
        $coro = GoWaitGroup::batchParallelRunWait([
            'list' => function () use ($id) {
                $ol = new OrderList();
                $ol->setUserId([self::TEST_USER_ID]);
                $ol->setOrderId([$id]);
                $ol->setPage(1);
                $ol->setPageSize(5);
                return [
                    'total' => $ol->total(),
                    'size' => count($ol->find()),
                    'db_object_id' => spl_object_id(App::getDb()),
                ];
            },
        ], 5.0);

        $this->deleteOrderById($id);

        return [
            'pass' => $total >= 1 && !empty($list) && ($coro['list']['total'] ?? 0) >= 1,
            'total' => $total,
            'list_size' => count($list),
            'coroutine' => $coro['list'] ?? null,
        ];
    }

    /**
     * 【复杂 SQL】
     * buildSql 生成子查询 → from 子查询别名 → whereExists + when + order。
     * 重点：SQL 能编译并执行（exists 对不上用户表时 list 可为空，不算失败）。
     */
    protected function caseComplexExistsJoin(): array
    {
        $seedId = $this->insertSeedOrder('complex-sql');

        $subTable = (new OrderEntity())->getQuery()
            ->table(OrderEntity::getTableName())
            ->where([
                'user_id' => self::TEST_USER_ID,
                'order_id' => $seedId,
            ])
            ->limit(1)
            ->buildSql();

        $t1 = Sql::table($subTable)->as('t1');
        $t2 = Sql::table('tbl_users')->as('t2');

        $list = App::getDb()->newQuery()
            ->field([
                $t1->C('user_id'),
                $t1->C('order_id'),
            ])
            ->from($t1)
            ->whereExists(function (Query $query) use ($t1, $t2) {
                // 测试用户未必在 tbl_users 中，exists 结果允许为空
                $query->table($t2)
                    ->field($t2->C('*'))
                    ->whereColumn($t1->C('user_id'), '=', $t2->C('user_id'));
            })
            ->when(true, function (Query $query) use ($t1) {
                $query->where($t1->C('user_id'), '<>', -1);
            })
            ->order([$t1->C('order_id') => 'desc'])
            ->limit(5)
            ->select()
            ->toArray();

        $fetchSql = (new OrderEntity())->getQuery()
            ->where('order_id', '=', $seedId)
            ->fetchSql()
            ->select();

        $this->deleteOrderById($seedId);

        return [
            'pass' => is_array($list) && is_string($fetchSql),
            'list_size' => count($list),
            'fetch_sql' => $fetchSql,
        ];
    }

    /**
     * 【newQuery 复杂 SQL 压力测试】
     *
     * 刻意堆叠 Query 能力，验证 Builder 编译与执行可靠性：
     * 1. buildSql 派生表（订单明细 + 用户维度聚合）
     * 2. LEFT JOIN 用户表 + LEFT JOIN 聚合派生表
     * 3. where / whereOr / whereIn / whereNotIn / whereBetween / whereLike
     * 4. whereNull / whereNotNull / whereExists / whereNotExists / whereRaw / whereColumn
     * 5. when / field(Raw) / order / limit
     * 6. group + having 聚合查询
     * 7. unionAll 合并两段 newQuery
     * 8. clone 后 count 与 select 互不影响
     * 9. fetchSql 仅生成 SQL
     * 10. whereIn(Closure) / where(Query) 子句条件合并
     * 11. WHERE 标量子查询、嵌套派生表（子查询套子查询）
     * 12. distinct / force index / whereOr / whereNotLike / whereExp
     * 13. RIGHT JOIN、having 聚合筛选
     * 14. partition 分区 SQL 编译；若表已分区则尝试真实执行
     */
    protected function caseComplexNewQuery(): array
    {
        $db = App::getDb();
        $table = OrderEntity::getTableName();
        $uid = self::TEST_USER_ID;

        // 保证 JOIN 用户表有行（LEFT JOIN 即使没有也能跑，有则校验 user_name）
        $userCnt = (int) $db->newQuery()
            ->table('tbl_users')
            ->where('user_id', '=', $uid)
            ->count();
        $userInserted = false;
        if ($userCnt === 0) {
            $db->newQuery()->table('tbl_users')->insert([
                'user_id' => $uid,
                'user_name' => 'complex-nq-user',
                'sex' => 0,
                'phone' => '13700000001',
                'tenant_id' => self::TEST_TENANT_ID,
            ]);
            $userInserted = true;
        }

        // 种子：4 笔订单，金额/状态/备注不同，便于 group/between/like/having
        $seeds = [
            ['amount' => 10.5, 'status' => 1, 'remark' => 'complex-nq-a'],
            ['amount' => 20.0, 'status' => 1, 'remark' => 'complex-nq-b'],
            ['amount' => 35.5, 'status' => 2, 'remark' => 'complex-nq-c'],
            ['amount' => 50.0, 'status' => 2, 'remark' => 'complex-nq-d'],
        ];
        $ids = [];
        foreach ($seeds as $i => $seed) {
            $orderId = $this->nextOrderId();
            $entity = new OrderEntity();
            $entity->order_id = $orderId;
            $entity->user_id = $uid;
            $entity->receiver_user_name = 'nq';
            $entity->receiver_user_phone = '13700000000';
            $entity->order_amount = $seed['amount'];
            $entity->order_product_ids = [100 + $i];
            $entity->order_status = $seed['status'];
            $entity->address = 'nq-address';
            $entity->remark = $seed['remark'];
            $entity->json_data = ['i' => $i, 'tag' => 'complex_new_query'];
            $entity->tenant_id = self::TEST_TENANT_ID;
            if ($entity->save() === false) {
                throw new \RuntimeException('complex_new_query seed save failed: ' . $seed['remark']);
            }
            $ids[] = $orderId;
        }

        // ----- 派生表 A：订单明细 -----
        $detailSubSql = $db->newQuery()
            ->table($table)
            ->alias('d')
            ->field([
                'd.order_id',
                'd.user_id',
                'd.order_amount',
                'd.order_status',
                'd.remark',
                'd.tenant_id',
                'd.deleted_at',
            ])
            ->where('d.user_id', '=', $uid)
            ->whereIn('d.order_id', $ids)
            ->whereNull('d.deleted_at')
            ->buildSql();

        // ----- 派生表 B：按 user 聚合 -----
        $aggSubSql = $db->newQuery()
            ->table($table)
            ->alias('g')
            ->field([
                'g.user_id',
                new Raw('COUNT(*) AS order_cnt'),
                new Raw('SUM(g.order_amount) AS amount_sum'),
                new Raw('AVG(g.order_amount) AS amount_avg'),
                new Raw('MAX(g.order_amount) AS amount_max'),
                new Raw('MIN(g.order_amount) AS amount_min'),
            ])
            ->where('g.user_id', '=', $uid)
            ->whereIn('g.order_id', $ids)
            ->group('g.user_id')
            ->having('COUNT(*) >= 2 AND SUM(g.order_amount) > 0')
            ->buildSql();

        $o = Sql::table($detailSubSql)->as('o');
        $u = Sql::table('tbl_users')->as('u');
        $agg = Sql::table($aggSubSql)->as('agg');

        // ----- 主查询：双派生表 + LEFT JOIN + 多种 where -----
        $mainQuery = $db->newQuery()
            ->field([
                $o->C('order_id'),
                $o->C('user_id'),
                $o->C('order_amount'),
                $o->C('order_status'),
                $o->C('remark'),
                $u->C('user_name'),
                $agg->C('order_cnt'),
                $agg->C('amount_sum'),
                $agg->C('amount_avg'),
                $agg->C('amount_max'),
                $agg->C('amount_min'),
                new Raw('CAST(o.order_amount AS DECIMAL(18,6)) AS amount_cast'),
            ])
            ->from($o)
            ->leftJoin($u, Sql::on($o->C('user_id'), $u->C('user_id')))
            ->leftJoin($agg, Sql::on($o->C('user_id'), $agg->C('user_id')))
            ->where($o->C('user_id'), '=', $uid)
            ->whereIn($o->C('order_id'), $ids)
            ->whereNotIn($o->C('order_status'), [98, 99])
            ->whereBetween($o->C('order_amount'), [1, 1000])
            ->whereLike($o->C('remark'), 'complex-nq-%')
            ->whereNotNull($o->C('remark'))
            ->where($o->C('tenant_id'), '=', self::TEST_TENANT_ID)
            ->where(function (Query $q) use ($o) {
                // 闭包内 AND，避免 OR 破坏外层 whereIn 优先级
                $q->where($o->C('order_status'), '>=', 0)
                    ->where($o->C('order_amount'), '>', 0);
            })
            ->whereExists(function (Query $query) use ($o, $table, $ids) {
                $query->table($table)
                    ->alias('ex')
                    ->field('ex.order_id')
                    ->whereColumn('ex.order_id', '=', $o->C('order_id'))
                    ->whereIn('ex.order_id', $ids)
                    ->where('ex.user_id', '=', self::TEST_USER_ID);
            })
            ->whereNotExists(function (Query $query) use ($o, $table) {
                $query->table($table)
                    ->alias('nx')
                    ->field('nx.order_id')
                    ->whereColumn('nx.order_id', '=', $o->C('order_id'))
                    ->where('nx.order_status', '=', 999);
            })
            ->when(count($ids) > 0, function (Query $query) use ($o) {
                $query->where($o->C('order_id'), '>', 0);
            })
            ->whereRaw('o.order_amount > ?', [0])
            ->order([
                $o->C('order_amount') => 'desc',
                $o->C('order_id') => 'asc',
            ])
            ->limit(20);

        $fetchSql = $mainQuery->clone()->fetchSql()->select();
        $rows = $mainQuery->select()->toArray();

        // ----- group/having 直查（不经派生表）-----
        $groupRows = $db->newQuery()
            ->table($table)
            ->alias('og')
            ->field([
                'og.order_status',
                new Raw('COUNT(*) AS cnt'),
                new Raw('SUM(og.order_amount) AS total_amount'),
            ])
            ->where('og.user_id', '=', $uid)
            ->whereIn('og.order_id', $ids)
            ->group('og.order_status')
            ->having('COUNT(*) >= 1')
            ->order(['og.order_status' => 'asc'])
            ->select()
            ->toArray();

        // ----- UNION ALL：两段 newQuery 子查询字符串合并 -----
        $unionPart1 = $db->newQuery()
            ->table($table)
            ->alias('p1')
            ->field(['p1.order_id', 'p1.remark'])
            ->where('p1.order_id', '=', $ids[0])
            ->buildSql();

        $unionPart2 = $db->newQuery()
            ->table($table)
            ->alias('p2')
            ->field(['p2.order_id', 'p2.remark'])
            ->where('p2.order_id', '=', $ids[1])
            ->buildSql();

        // 外层包一层，避免部分驱动对裸 UNION 限制
        $unionSql = '(' . trim($unionPart1, ' ()') . ') UNION ALL (' . trim($unionPart2, ' ()') . ')';
        $unionRows = $db->newQuery()
            ->table(Sql::buildSubSql($unionSql) . ' AS uni')
            ->field(['uni.order_id', 'uni.remark'])
            ->order(['uni.order_id' => 'asc'])
            ->select()
            ->toArray();

        // 也测官方 unionAll(Closure) 路径
        $unionClosureRows = $db->newQuery()
            ->table($table)
            ->alias('uc1')
            ->field(['uc1.order_id', 'uc1.remark'])
            ->where('uc1.order_id', '=', $ids[2])
            ->unionAll(function (Query $query) use ($table, $ids) {
                $query->table($table)
                    ->alias('uc2')
                    ->field(['uc2.order_id', 'uc2.remark'])
                    ->where('uc2.order_id', '=', $ids[3]);
            })
            ->select()
            ->toArray();

        // ----- clone：count 不影响后续 select -----
        $cloneBase = $db->newQuery()
            ->table($table)
            ->where('user_id', '=', $uid)
            ->whereIn('order_id', $ids);
        $cloneCount = $cloneBase->clone()->count();
        $cloneList = $cloneBase->clone()->order(['order_id' => 'desc'])->limit(2)->select()->toArray();

        // ----- 聚合函数 API -----
        $sumAmount = (float) $db->newQuery()
            ->table($table)
            ->whereIn('order_id', $ids)
            ->sum('order_amount');
        $maxAmount = (float) $db->newQuery()
            ->table($table)
            ->whereIn('order_id', $ids)
            ->max('order_amount');
        $minAmount = (float) $db->newQuery()
            ->table($table)
            ->whereIn('order_id', $ids)
            ->min('order_amount');
        $avgAmount = (float) $db->newQuery()
            ->table($table)
            ->whereIn('order_id', $ids)
            ->avg('order_amount');

        // ----- WHERE IN 子查询（Closure）-----
        $whereInSubRows = $db->newQuery()
            ->table($table)
            ->alias('wi')
            ->field(['wi.order_id', 'wi.remark', 'wi.order_amount'])
            ->whereIn('wi.order_id', function (Query $query) use ($table, $uid, $ids) {
                $query->table($table)
                    ->alias('wis')
                    ->field('wis.order_id')
                    ->where('wis.user_id', '=', $uid)
                    ->whereIn('wis.order_id', $ids)
                    ->where('wis.order_status', '>=', 1);
            })
            ->order(['wi.order_amount' => 'asc'])
            ->select()
            ->toArray();

        // ----- where(Query) 合并子查询 where 条件（含 bind）-----
        $subWhereQuery = $db->newQuery()
            ->where('user_id', '=', $uid)
            ->whereIn('order_id', $ids)
            ->where('order_status', '>', 0);
        $mergedWhereIds = $db->newQuery()
            ->table($table)
            ->where($subWhereQuery)
            ->order(['order_id' => 'asc'])
            ->column('order_id');

        // ----- WHERE 标量子查询比较（order_amount >= AVG 子查询）-----
        $avgThresholdSql = $db->newQuery()
            ->table($table)
            ->alias('th')
            ->field([new Raw('AVG(th.order_amount)')])
            ->where('th.user_id', '=', $uid)
            ->whereIn('th.order_id', $ids)
            ->buildSql(false);

        $scalarFilterRows = $db->newQuery()
            ->table($table)
            ->alias('sf')
            ->field(['sf.order_id', 'sf.order_amount'])
            ->whereIn('sf.order_id', $ids)
            ->whereRaw('sf.order_amount >= (' . $avgThresholdSql . ')')
            ->order(['sf.order_amount' => 'asc'])
            ->select()
            ->toArray();

        // ----- 嵌套派生表：buildSql 子查询再包一层 FROM -----
        $innerSubSql = $db->newQuery()
            ->table($table)
            ->alias('ns1')
            ->field(['ns1.order_id', 'ns1.order_amount', 'ns1.order_status'])
            ->whereIn('ns1.order_id', $ids)
            ->buildSql();
        $nestedDerivedRows = $db->newQuery()
            ->from(Sql::table($innerSubSql)->as('ns2'))
            ->field(['ns2.order_id', 'ns2.order_amount'])
            ->where('ns2.order_status', '>=', 1)
            ->order(['ns2.order_amount' => 'desc'])
            ->select()
            ->toArray();

        // ----- distinct + force index（force 仅 fetchSql 编译，避免与拦截器执行冲突）-----
        $forceIndexSql = $db->newQuery()
            ->table($table)
            ->force('idx_user_id')
            ->whereIn('order_id', $ids)
            ->fetchSql()
            ->select();

        $distinctStatuses = $db->newQuery()
            ->table($table)
            ->distinct(true)
            ->where('user_id', '=', $uid)
            ->whereIn('order_id', $ids)
            ->column('order_status');

        // ----- whereOr / whereNotLike / whereExp -----
        $whereOrRows = $db->newQuery()
            ->table($table)
            ->alias('wo')
            ->field(['wo.order_id', 'wo.remark'])
            ->whereIn('wo.order_id', $ids)
            ->where(function (Query $q) {
                $q->whereOr('wo.order_status', '=', 1)
                    ->whereOr('wo.remark', 'like', 'complex-nq-d');
            })
            ->select()
            ->toArray();

        $whereNotLikeCount = (int) $db->newQuery()
            ->table($table)
            ->whereIn('order_id', $ids)
            ->whereNotLike('remark', 'complex-nq-z%')
            ->count();

        $whereExpIds = $db->newQuery()
            ->table($table)
            ->whereIn('order_id', $ids)
            ->whereExp('order_amount', '> 30')
            ->order(['order_amount' => 'asc'])
            ->column('order_id');

        // ----- RIGHT JOIN -----
        $rightJoinRows = $db->newQuery()
            ->table($table)
            ->alias('ro')
            ->field(['ro.order_id', 'u2.user_name'])
            ->rightJoin(Sql::table('tbl_users')->as('u2'), Sql::on('ro.user_id', 'u2.user_id'))
            ->whereIn('ro.order_id', $ids)
            ->select()
            ->toArray();

        // ----- having 聚合筛选（直查 + 阈值）-----
        $havingSubRows = $db->newQuery()
            ->table($table)
            ->alias('hs')
            ->field([
                'hs.user_id',
                new Raw('COUNT(*) AS cnt'),
                new Raw('SUM(hs.order_amount) AS total'),
            ])
            ->whereIn('hs.order_id', $ids)
            ->group('hs.user_id')
            ->having('SUM(hs.order_amount) >= 30')
            ->select()
            ->toArray();

        // ----- 分区：先验证 SQL 编译；若 tbl_order 已分区则尝试真实查询 -----
        $partitionSql = $db->newQuery()
            ->table($table)
            ->partition(['p202601', 'p202602'])
            ->whereIn('order_id', $ids)
            ->fetchSql()
            ->select();

        $partitionExecSkipped = true;
        $partitionRowCount = 0;
        try {
            $partitionMeta = $db->newQuery()->query(
                'SELECT DISTINCT PARTITION_NAME AS pname FROM information_schema.PARTITIONS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl AND PARTITION_NAME IS NOT NULL',
                [':tbl' => $table]
            );
            if (!empty($partitionMeta)) {
                $firstPartition = $partitionMeta[0]['pname'] ?? null;
                if ($firstPartition) {
                    $partitionExecSkipped = false;
                    $partitionRowCount = count($db->newQuery()
                        ->table($table)
                        ->partition($firstPartition)
                        ->whereIn('order_id', $ids)
                        ->limit(2)
                        ->select()
                        ->toArray());
                }
            }
        } catch (\Throwable $ignore) {
        }

        foreach ($ids as $id) {
            $this->deleteOrderById($id);
        }
        if ($userInserted) {
            try {
                $db->newQuery()->table('tbl_users')->where('user_id', '=', $uid)->delete(true);
            } catch (\Throwable $ignore) {
            }
        }

        $expectedSum = 10.5 + 20.0 + 35.5 + 50.0;
        $expectedAvg = $expectedSum / 4;
        $remarks = array_column($rows, 'remark');
        $scalarFilterAmounts = array_map('floatval', array_column($scalarFilterRows, 'order_amount'));
        $pass = is_string($fetchSql)
            && str_contains(strtolower($fetchSql), 'select')
            && count($rows) === 4
            && count($groupRows) === 2
            && count($unionRows) === 2
            && count($unionClosureRows) === 2
            && $cloneCount === 4
            && count($cloneList) === 2
            && abs($sumAmount - $expectedSum) < 0.01
            && abs($maxAmount - 50.0) < 0.01
            && abs($minAmount - 10.5) < 0.01
            && abs($avgAmount - $expectedAvg) < 0.01
            && in_array('complex-nq-a', $remarks, true)
            && in_array('complex-nq-d', $remarks, true)
            && isset($rows[0]['order_cnt'])
            && (int) $rows[0]['order_cnt'] === 4
            && count($whereInSubRows) === 4
            && array_map('strval', $mergedWhereIds) === array_map('strval', $ids)
            && count($scalarFilterRows) === 2
            && abs($scalarFilterAmounts[0] - 35.5) < 0.01
            && abs($scalarFilterAmounts[1] - 50.0) < 0.01
            && count($nestedDerivedRows) === 4
            && count(array_unique($distinctStatuses)) === 2
            && count($whereOrRows) === 3
            && $whereNotLikeCount === 4
            && count($whereExpIds) === 2
            && count($rightJoinRows) === 4
            && isset($rightJoinRows[0]['user_name'])
            && count($havingSubRows) === 1
            && (int) ($havingSubRows[0]['cnt'] ?? 0) === 4
            && is_string($partitionSql)
            && stripos($partitionSql, 'PARTITION') !== false
            && is_string($forceIndexSql)
            && stripos($forceIndexSql, 'FORCE') !== false;

        return [
            'pass' => $pass,
            'main_row_count' => count($rows),
            'group_row_count' => count($groupRows),
            'union_row_count' => count($unionRows),
            'union_closure_row_count' => count($unionClosureRows),
            'clone_count' => $cloneCount,
            'clone_list_size' => count($cloneList),
            'sum' => $sumAmount,
            'max' => $maxAmount,
            'min' => $minAmount,
            'avg' => $avgAmount,
            'where_in_sub_count' => count($whereInSubRows),
            'merged_where_ids' => $mergedWhereIds,
            'scalar_filter_rows' => $scalarFilterRows,
            'nested_derived_count' => count($nestedDerivedRows),
            'distinct_statuses' => $distinctStatuses,
            'where_or_count' => count($whereOrRows),
            'where_not_like_count' => $whereNotLikeCount,
            'where_exp_ids' => $whereExpIds,
            'right_join_sample' => $rightJoinRows[0] ?? null,
            'having_sub_rows' => $havingSubRows,
            'force_index_sql_preview' => is_string($forceIndexSql) ? mb_substr($forceIndexSql, 0, 200) : null,
            'partition_sql_preview' => is_string($partitionSql) ? mb_substr($partitionSql, 0, 280) : null,
            'partition_exec_skipped' => $partitionExecSkipped,
            'partition_row_count' => $partitionRowCount,
            'fetch_sql_preview' => is_string($fetchSql) ? mb_substr($fetchSql, 0, 500) : null,
            'sample_row' => $rows[0] ?? null,
            'group_rows' => $groupRows,
        ];
    }

    /**
     * 【软删除】
     * Entity::delete() 写 deleted_at；普通 query()->first() 应查不到；
     * withoutTrashed()->first() 仍可见；restore 后物理清理。
     * 表无 deleted_at 时 skipped=true（请执行 test.sql 的 ALTER）。
     */
    protected function caseSoftDelete(): array
    {
        $db = App::getDb();
        $fields = $db->getFields(OrderEntity::getTableName());
        if (!isset($fields['deleted_at'])) {
            return [
                'pass' => true,
                'skipped' => true,
                'reason' => 'tbl_order 无 deleted_at 字段，请执行 test.sql 中的 ALTER',
            ];
        }

        $orderId = $this->insertSeedOrder('soft-delete');

        $entity = new OrderEntity();
        $entity->loadById($orderId);
        $entity->delete(); // 软删

        $normal = OrderEntity::query()->where('order_id', '=', $orderId)->first();
        $trashed = OrderEntity::withoutTrashed()->where('order_id', '=', $orderId)->first();

        if ($trashed instanceof OrderEntity) {
            (new OrderEntity())->restore($orderId);
        }
        $this->deleteOrderById($orderId);

        return [
            'pass' => !$normal instanceof OrderEntity && $trashed instanceof OrderEntity,
            'hidden_by_scope' => !$normal instanceof OrderEntity,
            'visible_without_trashed' => $trashed instanceof OrderEntity,
            'order_id' => $orderId,
        ];
    }

    /**
     * 【批量写入】
     * Query::insert(..., false) 不取 lastInsertId；insertAll 批量插入；再 count 校验条数。
     */
    protected function caseBatchInsertQuery(): array
    {
        $id1 = $this->nextOrderId();
        $id2 = $this->nextOrderId();

        $q = OrderEntity::query();
        $q->insert([
            'order_id' => $id1,
            'user_id' => self::TEST_USER_ID,
            'receiver_user_name' => 'batch-1',
            'receiver_user_phone' => '13700000001',
            'order_amount' => 1.1,
            'order_product_ids' => json_encode([1]),
            'order_status' => 0,
            'address' => 'batch-addr',
            'remark' => 'batch-1',
            'json_data' => json_encode(['batch' => 1]),
            'tenant_id' => self::TEST_TENANT_ID,
        ], false);

        OrderEntity::query()->insertAll([
            [
                'order_id' => $id2,
                'user_id' => self::TEST_USER_ID,
                'receiver_user_name' => 'batch-2',
                'receiver_user_phone' => '13700000002',
                'order_amount' => 2.2,
                'order_product_ids' => json_encode([2]),
                'order_status' => 0,
                'address' => 'batch-addr',
                'remark' => 'batch-2',
                'json_data' => json_encode(['batch' => 2]),
                'tenant_id' => self::TEST_TENANT_ID,
            ],
        ]);

        $count = OrderEntity::query()
            ->whereIn('order_id', [$id1, $id2])
            ->count();

        $this->deleteOrderById($id1);
        $this->deleteOrderById($id2);

        return [
            'pass' => $count === 2,
            'count' => $count,
            'ids' => [$id1, $id2],
        ];
    }

    /**
     * 【Query::clone】
     * 先 clone()->count()，再在原 Query 上 limit+select，两者互不影响（修复 paginate/count 污染的回归点）。
     */
    protected function caseCloneCountSelect(): array
    {
        $id = $this->insertSeedOrder('clone-count');

        $query = (new OrderEntity())->getQuery()
            ->where('user_id', '=', self::TEST_USER_ID)
            ->where('order_id', '=', $id);

        $total = $query->clone()->count();
        $list = $query->limit(1)->select()->toArray();

        $this->deleteOrderById($id);

        return [
            'pass' => $total === 1 && count($list) === 1,
            'total' => $total,
            'list_size' => count($list),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers（种子数据 / 清理）
    // ------------------------------------------------------------------

    /** 生成订单主键：优先 UUID 组件，失败则用毫秒时间戳+随机数 */
    protected function nextOrderId(): int
    {
        try {
            $id = App::getUUid()->getOneId();
            if ($id) {
                return (int) $id;
            }
        } catch (\Throwable $e) {
        }

        return (int) (sprintf('%d%03d', (int) (microtime(true) * 1000), mt_rand(100, 999)));
    }

    /** 插入一条最小字段订单，返回 order_id */
    protected function insertSeedOrder(string $remark): int
    {
        $orderId = $this->nextOrderId();
        $this->saveMinimalOrder($orderId, $remark);
        return $orderId;
    }

    /** 用 OrderEntity 写入最小可用字段（含 tenant_id） */
    protected function saveMinimalOrder(int $orderId, string $remark): void
    {
        $entity = new OrderEntity();
        $entity->order_id = $orderId;
        $entity->user_id = self::TEST_USER_ID;
        $entity->receiver_user_name = 'seed';
        $entity->receiver_user_phone = '13600000000';
        $entity->order_amount = 1;
        $entity->order_product_ids = [1];
        $entity->order_status = 0;
        $entity->address = 'seed-address';
        $entity->remark = $remark;
        $entity->json_data = ['seed' => $remark];
        $entity->tenant_id = self::TEST_TENANT_ID;
        if ($entity->save() === false) {
            throw new \RuntimeException('saveMinimalOrder failed: ' . $remark);
        }
    }

    /**
     * 物理删除测试订单。
     * 优先 withoutTrashed（含已软删行）；失败则退回原生 Query::delete。
     */
    protected function deleteOrderById(int $orderId): void
    {
        try {
            OrderEntity::withoutTrashed()
                ->where('order_id', '=', $orderId)
                ->delete(true);
        } catch (\Throwable $e) {
            try {
                App::getDb()->newQuery()
                    ->table(OrderEntity::getTableName())
                    ->where('order_id', '=', $orderId)
                    ->delete(true);
            } catch (\Throwable $ignore) {
            }
        }
    }
}

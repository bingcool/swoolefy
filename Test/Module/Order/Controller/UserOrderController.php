<?php
namespace Test\Module\Order\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\Coroutine\GoWaitGroup;
use Swoolefy\Http\RequestInput;
use Swoolefy\Library\Db\Query;
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
 * - paginate               paginate / paginateX
 * - order_list             OrderList 列表查询
 * - complex_exists_join    子查询 + exists
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
            'paginate' => fn () => $this->casePaginate(),
            'order_list' => fn () => $this->caseOrderList(),
            'complex_exists_join' => fn () => $this->caseComplexExistsJoin(),
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

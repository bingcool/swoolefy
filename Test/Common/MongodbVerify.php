<?php

namespace Test\Common;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\Operation\FindOneAndUpdate;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Core\Application;
use Swoolefy\Core\Dto\ContainerObjectDto;
use Swoolefy\Library\Mongodb\MongodbCollection;
use Swoolefy\Library\Mongodb\MongodbModel;
use Throwable;

/**
 * Mongodb 模块端到端验证。仅在 Test 服务 Worker 内运行，依赖 MONGO_URI / MONGO_DB。
 *
 * 每个场景使用独立 collection，结束后删除；场景失败只记录错误，不中断后续场景。
 */
final class MongodbVerify
{
    private const COLLECTION_PREFIX = 'swoolefy_verify_mongodb_';

    private const HEX_ID = '507f1f77bcf86cd799439011';

    private const HEX_ID_2 = '507f1f77bcf86cd799439012';

    private string $runId = '';

    public static function run(): array
    {
        return (new self())->execute();
    }

    private function execute(): array
    {
        $this->runId = date('His') . bin2hex(random_bytes(2));

        $scenarios = [
            'crud_and_id_normalization' => 'caseCrud',
            'component_instances' => 'caseComponentInstances',
            'child_coroutine_crud' => 'caseChildCoroutineCrud',
            'write_result_semantics' => 'caseWriteResults',
            'bulk_and_aggregate' => 'caseBulkAndAggregate',
        ];

        $cases = [];
        foreach ($scenarios as $name => $method) {
            $startedAt = microtime(true);
            try {
                $cases[] = [
                    'name'       => $name,
                    'pass'       => true,
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                    'detail'     => $this->{$method}(),
                ];
            } catch (Throwable $exception) {
                $cases[] = [
                    'name'       => $name,
                    'pass'       => false,
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                    'error'      => get_class($exception) . ': ' . $exception->getMessage(),
                ];
            }
        }

        return [
            'pass' => !in_array(false, array_column($cases, 'pass'), true),
            'run'  => $this->runId,
            'cases' => $cases,
        ];
    }

    private function caseCrud(): array
    {
        $name = $this->freshCollection('crud');
        try {
            $collection = $this->model()->collection($name);

            $inserted = $collection->insertOne([
                '_id'    => self::HEX_ID,
                'name'   => 'e2e',
                'nested' => ['enabled' => true],
            ]);
            $this->assertTrue(
                (string) $inserted->getInsertedId() === self::HEX_ID,
                'insertOne 未返回 ObjectId 主键'
            );

            $document = $collection->findOne(['_id' => self::HEX_ID]);
            $this->assertTrue(is_array($document), 'findOne 未返回数组');
            $this->assertTrue($document['_id'] === self::HEX_ID, '_id 未归一化为字符串');
            $this->assertTrue($document['nested'] === ['enabled' => true], '嵌套文档未解码为数组');

            $magicCollection = $this->model()->{$name};
            $this->assertTrue($magicCollection instanceof MongodbCollection, '__get 未返回 collection');
            $this->assertTrue($magicCollection->getName() === $name, '__get collection 名称不一致');

            $updated = $collection->updateOne(['_id' => self::HEX_ID], ['$set' => ['name' => 'updated']]);
            $this->assertTrue($updated->getModifiedCount() === 1, 'updateOne 未更新文档');

            $deleted = $collection->deleteOne(['_id' => self::HEX_ID]);
            $this->assertTrue($deleted->getDeletedCount() === 1, 'deleteOne 未删除文档');
            $this->assertTrue($collection->findOne(['_id' => self::HEX_ID]) === null, '删除后仍能查到文档');

            return ['collection' => $name];
        } finally {
            $this->drop($name);
        }
    }

    private function caseComponentInstances(): array
    {
        $model = $this->model();
        $again = $this->model();
        $this->assertTrue(
            spl_object_id($model) === spl_object_id($again),
            '同一协程内组件 get() 未返回同一实例'
        );

        $channel = new Channel(1);
        $cid = goApp(function ($event) use ($channel): void {
            try {
                $childModel = $this->model();
                $channel->push([
                    'object_id' => spl_object_id($childModel),
                    'cid'       => Coroutine::getCid(),
                ]);
            } catch (Throwable $exception) {
                $channel->push(['error' => get_class($exception) . ': ' . $exception->getMessage()]);
            }
        });
        Coroutine::join([$cid], 5.0);

        $child = $channel->pop(5.0);
        $this->assertTrue(is_array($child), '子协程未返回组件实例信息');
        $this->assertTrue(!isset($child['error']), '子协程获取组件失败: ' . ($child['error'] ?? ''));
        $this->assertTrue(
            $child['object_id'] !== spl_object_id($model),
            '子协程复用了父协程的 MongodbModel'
        );
        $this->assertTrue($child['cid'] > 0, '子协程 cid 无效');

        return [
            'parent_object_id' => spl_object_id($model),
            'child_object_id'  => $child['object_id'],
        ];
    }

    private function caseChildCoroutineCrud(): array
    {
        $name = $this->freshCollection('child');
        try {
            $channel = new Channel(1);
            $cid = goApp(function ($event) use ($name, $channel): void {
                try {
                    $collection = $this->model()->collection($name);
                    $inserted = $collection->insertOne(['_id' => self::HEX_ID_2, 'scope' => 'child']);
                    $document = $collection->findOne(['_id' => (string) $inserted->getInsertedId()]);

                    $channel->push([
                        'cid'   => Coroutine::getCid(),
                        'found' => $document,
                        'pong'  => $this->model()->ping(),
                    ]);
                } catch (Throwable $exception) {
                    $channel->push(['error' => get_class($exception) . ': ' . $exception->getMessage()]);
                }
            });
            Coroutine::join([$cid], 5.0);

            $result = $channel->pop(5.0);
            $this->assertTrue(is_array($result), '子协程未返回 CRUD 结果');
            $this->assertTrue(!isset($result['error']), '子协程 CRUD 失败: ' . ($result['error'] ?? ''));
            $this->assertTrue($result['cid'] > 0, '子协程 cid 无效');
            $this->assertTrue($result['pong'] === true, '子协程 ping 失败');
            $this->assertTrue(
                $result['found']['_id'] === self::HEX_ID_2,
                '子协程读出的 _id 未归一化'
            );
            $this->assertTrue($result['found']['scope'] === 'child', '子协程写入数据不正确');

            return ['collection' => $name, 'cid' => $result['cid']];
        } finally {
            $this->drop($name);
        }
    }

    private function caseWriteResults(): array
    {
        $name = $this->freshCollection('write');
        try {
            $collection = $this->model()->collection($name);
            $collection->insertOne(['_id' => self::HEX_ID, 'status' => 'new']);

            $unchanged = $collection->updateOne(
                ['_id' => self::HEX_ID],
                ['$set' => ['status' => 'new']]
            );
            $this->assertTrue($unchanged->getMatchedCount() === 1, '未命中时 matched 应为 1');
            $this->assertTrue($unchanged->getModifiedCount() === 0, '值未变化时 modified 应为 0');
            $this->assertTrue($unchanged->getUpsertedCount() === 0, '未 upsert 时 upserted 应为 0');

            $changed = $collection->updateOne(
                ['_id' => self::HEX_ID],
                ['$set' => ['status' => 'done']]
            );
            $this->assertTrue($changed->getModifiedCount() === 1, '真实更新未计入 modified');

            $upserted = $collection->updateOne(
                ['_id' => self::HEX_ID_2],
                ['$set' => ['status' => 'created']],
                ['upsert' => true]
            );
            $this->assertTrue($upserted->getUpsertedCount() === 1, 'upsert 未生效');

            $missingDelete = $collection->deleteOne(['_id' => 'ffffffffffffffffffffffff']);
            $this->assertTrue($missingDelete->getDeletedCount() === 0, '未命中删除应返回 0');

            $putBefore = $collection->findOneAndUpdate(
                ['_id' => self::HEX_ID],
                ['$inc' => ['count' => 1]]
            );
            $this->assertTrue(
                is_array($putBefore) && !array_key_exists('count', $putBefore),
                '默认 returnDocument 应返回更新前文档'
            );

            $after = $collection->findOneAndUpdate(
                ['_id' => self::HEX_ID],
                ['$inc' => ['count' => 1]],
                ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            $this->assertTrue(($after['count'] ?? null) === 2, 'RETURN_DOCUMENT_AFTER 未返回更新后文档');

            try {
                $collection->findOneAndUpdate(
                    ['_id' => self::HEX_ID],
                    ['$set' => ['count' => 1]],
                    ['returnDocument' => 'after']
                );
                throw new RuntimeException('字符串 returnDocument 未被拒绝');
            } catch (InvalidArgumentException) {
                // 预期异常
            }

            return [
                'matched'  => $unchanged->getMatchedCount(),
                'modified' => $changed->getModifiedCount(),
                'upserted' => $upserted->getUpsertedCount(),
            ];
        } finally {
            $this->drop($name);
        }
    }

    private function caseBulkAndAggregate(): array
    {
        $name = $this->freshCollection('bulk');
        try {
            $collection = $this->model()->collection($name);

            $bulk = $collection->bulkWrite([
                ['insertOne' => [['_id' => '111111111111111111111111', 'group' => 'a', 'n' => 1]]],
                ['insertOne' => [['_id' => '222222222222222222222222', 'group' => 'a', 'n' => 5]]],
                ['insertOne' => [['_id' => '333333333333333333333333', 'group' => 'b', 'n' => 7]]],
                ['insertOne' => [['_id' => '444444444444444444444444', 'group' => 'b', 'n' => 8]]],
                ['updateOne' => [['_id' => '111111111111111111111111'], ['$set' => ['n' => 2]]]],
                ['deleteMany' => [['n' => 7]]],
            ]);

            $this->assertTrue($bulk->getInsertedCount() === 4, 'bulkWrite inserted 计数错误');
            $this->assertTrue($bulk->getModifiedCount() === 1, 'bulkWrite modified 计数错误');
            $this->assertTrue($bulk->getDeletedCount() === 1, 'bulkWrite deleted 计数错误');

            $updated = $collection->findOne(['_id' => '111111111111111111111111']);
            $this->assertTrue($updated['n'] === 2, 'bulkWrite 过滤条件未归一化');
            $this->assertTrue($updated['_id'] === '111111111111111111111111', 'bulkWrite 读回 _id 未归一化');

            $rows = $collection->aggregate([
                ['$group' => ['_id' => '$group', 'total' => ['$sum' => 1]]],
                ['$sort' => ['_id' => 1]],
            ]);
            $this->assertTrue(
                $rows === [
                    ['_id' => 'a', 'total' => 2],
                    ['_id' => 'b', 'total' => 1],
                ],
                'aggregate 结果或分组 _id 被改写: ' . json_encode($rows)
            );

            $ids = $collection->distinct('_id');
            $this->assertTrue(count($ids) === 3, 'distinct 计数错误');
            $this->assertTrue($ids[0] instanceof ObjectId, 'distinct 不应把 ObjectId 转字符串');
            $this->assertTrue($collection->countDocuments([]) === 3, 'countDocuments 计数错误');

            return [
                'inserted' => $bulk->getInsertedCount(),
                'modified' => $bulk->getModifiedCount(),
                'deleted'  => $bulk->getDeletedCount(),
                'groups'   => count($rows),
            ];
        } finally {
            $this->drop($name);
        }
    }

    private function model(): MongodbModel
    {
        $object = Application::getApp()->get('mongodb');
        if ($object instanceof ContainerObjectDto) {
            $object = $object->getObject();
        }

        if (!$object instanceof MongodbModel) {
            throw new RuntimeException('mongodb 组件未返回 MongodbModel 实例');
        }

        return $object;
    }

    private function freshCollection(string $suffix): string
    {
        return self::COLLECTION_PREFIX . $suffix . '_' . $this->runId;
    }

    private function drop(string $name): void
    {
        try {
            $this->model()->collection($name)->raw()->drop();
        } catch (Throwable) {
            // 清理失败不覆盖场景结果
        }
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

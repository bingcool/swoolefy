<?php
namespace Test\WorkerDaemon\Datahub;

use Common\Library\Aliyun\Datahub\DatahubConfigDto;

class OrderProcessDatahub extends \Swoolefy\Worker\AbstractWorkerProcess
{
    public $shardId = '8';

    public $subId = "";
    public function init()
    {
    }

    public function run()
    {
        while (true) {
            try {
                $shardIds = [$this->shardId];

                $dto = new DatahubConfigDto();
                $dto->accessId  = '';
                $dto->accessKey = '';
                $dto->endpoint  = '';
                $dto->projectId = '';
                $dto->topicName = '';
                $orderHandle = new OrderProcessHandle($dto);

                $sessionInfo = $orderHandle->openSubscriptionSession($shardIds, $this->subId);

                $sequence = $sessionInfo['Offsets'][$this->shardId]['Sequence'];
                $version = $sessionInfo['Offsets'][$this->shardId]['Version'];
                $sessionId = $sessionInfo['Offsets'][$this->shardId]['SessionId'];

                $this->fmtWriteInfo("sequence:".$sequence);

                // 获取cursor,从最新的位点$sequence开始消费
                $cursorInfo = $orderHandle->getCursor($this->shardId, $sequence);

                // 获取cursor句柄字符
                $cursorId = $cursorInfo['Cursor'];

                // 获取topic信息
                $topicInfo = $orderHandle->getTopic();
                // 获取RecordSchema
                $recordSchema = json_decode($topicInfo['RecordSchema'], true);

                while (true) {
                    try {
                        $result = $orderHandle->consume($this->shardId, $cursorId, $recordSchema, 100);

                        if (isset($result['nextCursor'])) {
                            $cursorId = $result['nextCursor'];
                        }

                        // 没有数据
                        if (isset($result['recordCount']) && $result['recordCount'] == 0) {
                            $this->fmtWriteInfo('datahub没有数据');
                            sleep(10);
                            break;
                        }

                        // 记录binlog数据,用于数据对比是否变化
                        // $this->recordBinlog($result);

                        foreach ($result['records'] as $record) {
                            try {
                                $sequence = $record['sequence'] ?? 0;
                                $data = $record['data'];
                                // 新增插入的数据
                                if ($orderHandle->isInsertOperation($data)) {
                                    // binlog新增操作
                                    $this->fmtWriteInfo("新增数据");
                                } else if ($orderHandle->isUpdateOperation($data)) {
                                    // binlog更新操作
                                    $this->fmtWriteInfo("更新数据");
                                } else if ($orderHandle->isDeleteOperation($data)) { // 删除的数据
                                    // 业务是软删的，基本不会触发到这个物理删除operation
                                    $this->fmtWriteInfo("删除数据");
                                }
                            } catch (\Throwable $exception) {
                                $this->fmtWriteError("for循环处理异常：".$exception->getMessage());
                                // todo 记录异常信息入库,是否需要二次处理
                                sleep(5);
                            } finally {
                                // 业务处理完毕，自动提交
                                $orderHandle->commitSubscriptionOffset(
                                    $this->shardId,
                                    $this->subId,
                                    $sequence,
                                    $version,
                                    $sessionId
                                );
                            }
                        }
                    } catch (\Throwable $throwable) {
                        $this->fmtWriteError("内部while异常:" . $exception->getMessage());
                        sleep(5);
                        break;
                    }
                }
            } catch (\Throwable $exception) {
                $this->fmtWriteError("最外层while异常:" . $exception->getMessage());
                sleep(5);
            }
        }
    }
}

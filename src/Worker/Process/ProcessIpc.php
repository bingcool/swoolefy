<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Worker\Process;

use Swoolefy\Exception\WorkerException;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\Dto\MessageDtoWorker;
use Swoolefy\Worker\MainManager;

/**
 * Master ↔ Worker 管道 IPC。
 *
 * - Event::add(pipe) 读子进程消息；unserialize 仅允许 MessageDtoWorker（防止gadget）；
 * - 发给 master 的 CREATE/DESTROY/REBOOT 转 Supervisor，不在本类改进程表；
 * - 发给其它 Worker 的消息走 writeByMasterProxy（Master 只做转发）。
 */
final class ProcessIpc
{
    public function __construct(private MainManager $manager)
    {
    }

    /**
     * 监听一个或全部 Worker 的 pipe。
     *
     * `$currentProcess === null`：启动时 bind 全部已 start 的 worker；
     * 非 null：reboot / 动态 fork 后只给新进程加 watcher，避免对旧 pipe 重复 Event::add。
     */
    public function swooleEventAdd(?AbstractBaseWorker $currentProcess = null): void
    {
        $processWorkers = [];
        if (isset($currentProcess)) {
            $processName = $currentProcess->getProcessName();
            $processWorkerId = $currentProcess->getProcessWorkerId();
            $key = ProcessRegistry::key($processName);
            $processWorkers[$key][$processWorkerId] = $currentProcess;
        } else {
            $processWorkers = $this->manager->getRegistry()->allWorkers();
        }

        foreach ($processWorkers as $processes) {
            foreach ($processes as $process) {
                /** @var \Swoole\Process $swooleProcess */
                $swooleProcess = $process->getSwooleProcess();
                \Swoole\Event::add($swooleProcess->pipe, function ($pipe) use ($swooleProcess) {
                    $message = $swooleProcess->read(64 * 1024);
                    if (is_string($message)) {
                        // allowed_classes 白名单：禁止反序列化任意类
                        $messageDto = unserialize($message, ['allowed_classes' => [MessageDtoWorker::class]]);
                        if (!$messageDto instanceof MessageDtoWorker) {
                            $this->manager->logError("Accept message type error");
                            return;
                        }
                        $msg = $messageDto->data;
                        $fromProcessName = $messageDto->fromProcessName;
                        $fromProcessWorkerId = $messageDto->fromProcessWorkerId;
                        $toProcessName = $messageDto->toProcessName;
                        $toProcessWorkerId = $messageDto->toProcessWorkerId;
                    }

                    if (isset($msg) && isset($fromProcessName) && isset($fromProcessWorkerId) && isset($toProcessName) && isset($toProcessWorkerId)) {
                        try {
                            if ($toProcessName == $this->manager->getMasterWorkerName()) {
                                $action = $msg['action'] ?? '';
                                $processName = $msg['process_name'] ?? '';
                                $data = $msg['data'] ?? [];
                                $actionHandleFlag = false;
                                if ($action && $processName) {
                                    switch ($action) {
                                        case MainManager::CREATE_DYNAMIC_PROCESS_WORKER:
                                            $actionHandleFlag = true;
                                            $dynamicProcessName = $processName;
                                            $dynamicProcessNum = $data['dynamic_process_num'] ?? 1;
                                            if (is_callable($this->manager->onCreateDynamicProcess)) {
                                                $this->manager->onCreateDynamicProcess->call($this->manager, $dynamicProcessName, $dynamicProcessNum, $fromProcessName, $fromProcessWorkerId);
                                            } else {
                                                $this->manager->createDynamicProcess($dynamicProcessName, $dynamicProcessNum);
                                            }
                                            break;
                                        case MainManager::DESTROY_DYNAMIC_PROCESS_WORKER:
                                            $actionHandleFlag = true;
                                            $dynamicProcessName = $processName;
                                            $dynamicProcessNum = $data['dynamic_process_num'] ?? -1;
                                            if (is_callable($this->manager->onDestroyDynamicProcess)) {
                                                $this->manager->onDestroyDynamicProcess->call($this->manager, $dynamicProcessName, $dynamicProcessNum, $fromProcessName, $fromProcessWorkerId);
                                            } else {
                                                $this->manager->destroyDynamicProcess($dynamicProcessName);
                                            }
                                            break;
                                        case MainManager::REBOOT_PROCESS_WORKER:
                                            $actionHandleFlag = true;
                                            $pid = $data['worker_pid'];
                                            $this->manager->rebootWorker($pid);
                                            break;
                                        case AbstractBaseWorker::WORKERFY_PROCESS_STATUS_FLAG:
                                            $actionHandleFlag = true;
                                            $workerId = $data['worker_id'];
                                            $status = $data['status'] ?? [];
                                            $status['process_name'] = $processName;
                                            $status['worker_id'] = $workerId;
                                            $this->manager->getRegistry()->setRuntimeStatus($processName, $workerId, $status);
                                            break;
                                        default:
                                            break;
                                    }
                                }
                                if ($actionHandleFlag === false) {
                                    if (is_callable($this->manager->onPipeMsg)) {
                                        $this->manager->onPipeMsg->call($this->manager, $msg, $fromProcessName, $fromProcessWorkerId);
                                    } else {
                                        $this->writeByProcessName($fromProcessName, $msg, $fromProcessWorkerId);
                                    }
                                }
                            } else {
                                if (is_callable($this->manager->onProxyMsg)) {
                                    $this->manager->onProxyMsg->call($this->manager, $msg, $fromProcessName, $fromProcessWorkerId, $toProcessName, $toProcessWorkerId);
                                } else {
                                    $this->writeByMasterProxy($msg, $fromProcessName, $fromProcessWorkerId, $toProcessName, $toProcessWorkerId);
                                }
                            }
                        } catch (\Throwable $throwable) {
                            $this->manager->handleWorkerException($throwable);
                        }
                    }
                });
            }
        }
    }

    /**
     * Master 向指定 Worker 写管道（非代理）。不能写给自己，且 Master 必须已 start。
     *
     * `$processWorkerId < 0` 时 getProcessByName 返回该名下全部 worker，实现广播式单进程名投递。
     *
     * @param mixed $data
     * @return bool
     */
    public function writeByProcessName(string $processName, $data, int $processWorkerId = 0)
    {
        if ($this->manager->isMaster($processName)) {
            throw new WorkerException("Master process can not write msg to master process self");
        }

        if (!$this->manager->isRunning()) {
            throw new WorkerException("Master process is not start, you can not use writeByProcessName(), please checkout it");
        }

        $processWorkers = [];
        $process = $this->manager->getProcessByName($processName, $processWorkerId);
        if (is_object($process) && $process instanceof AbstractBaseWorker) {
            $processWorkers = [$processWorkerId => $process];
        } else if (is_array($process)) {
            $processWorkers = $process;
        }

        $messageDto = new MessageDtoWorker();
        $messageDto->fromProcessName = $this->manager->getMasterWorkerName();
        $messageDto->fromProcessWorkerId = $this->manager->getMasterWorkerId();
        $messageDto->data = $data;
        $messageDto->isProxy = false;
        $message = serialize($messageDto);
        foreach ($processWorkers as $process) {
            $process->getSwooleProcess()->write($message);
        }
    }

    /**
     * Worker A → Master → Worker B 的代理转发，isProxy=true，from 保持原始发送方。
     *
     * @param mixed $data
     * @return bool
     */
    public function writeByMasterProxy(
        $data,
        string $fromProcessName,
        int $fromProcessWorkerId,
        string $toProcessName,
        int $toProcessWorkerId
    ) {
        if ($this->manager->isMaster($toProcessName)) {
            return false;
        }

        $processWorkers = [];
        $process = $this->manager->getProcessByName($toProcessName, $toProcessWorkerId);
        if (is_object($process) && $process instanceof AbstractBaseWorker) {
            $processWorkers = [$toProcessWorkerId => $process];
        } else if (is_array($process)) {
            $processWorkers = $process;
        }

        $messageDto = new MessageDtoWorker();
        $messageDto->fromProcessName = $fromProcessName;
        $messageDto->fromProcessWorkerId = $fromProcessWorkerId;
        $messageDto->data = $data;
        $messageDto->isProxy = true;
        $message = serialize($messageDto);
        foreach ($processWorkers as $process) {
            $process->getSwooleProcess()->write($message);
        }
    }

    /**
     * 向某 process_name 下全部 worker 广播。进程不存在时走 onHandleException 而不是抛到 Event 回调外。
     *
     * @param mixed $data
     */
    public function broadcastProcessWorker(string $processName, $data = ''): void
    {
        $messageDto = new MessageDtoWorker();
        $messageDto->fromProcessName = $this->manager->getMasterWorkerName();
        $messageDto->fromProcessWorkerId = $this->manager->getMasterWorkerId();
        $messageDto->data = $data;
        $messageDto->isProxy = true;
        $message = serialize($messageDto);
        if ($processName) {
            $registry = $this->manager->getRegistry();
            if (!$registry->hasWorkerGroup($processName)) {
                $exception = new WorkerException(sprintf(
                    "%s::%s not exist process=%s, please check it",
                    MainManager::class,
                    'broadcastProcessWorker',
                    $processName
                ));
            } else {
                $processWorkers = $registry->getWorkersByName($processName) ?? [];
                foreach ($processWorkers as $process) {
                    $process->getSwooleProcess()->write($message);
                }
            }
        }

        if (isset($exception) && $exception instanceof \Throwable) {
            $this->manager->handleWorkerException($exception);
        }
    }
}

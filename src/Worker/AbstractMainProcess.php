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

namespace Swoolefy\Worker;

use Swoolefy\Core\SystemEnv;
use Swoolefy\Core\Process\AbstractProcess;

/**
 * swoole 自定义进程作为管理进程
 */
abstract class AbstractMainProcess extends AbstractProcess
{
    /**
     * @return void
     */
    public function init()
    {
        $workerConf = $this->parseWorkerConf();
        if (!empty($workerConf)) {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $mainManager->onHandleException = function (\Throwable $throwable) {
                fmtPrintError(sprintf("管理进程报错,err:%s, line: %d, trace=%s", $throwable->getMessage(), $throwable->getLine(), $throwable->getTraceAsString()));
            };
            $mainManager->loadConf($workerConf);
        }
    }

    /**
     * @return array|mixed
     */
    protected function parseWorkerConf()
    {
        // 指定只启动某一个进程，开发，调试使用
        // php daemon.php start Test --only=order-sync
        // php cron.php start Test --only=order-sync
        if(defined('WORKER_CONF')) {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $workerConfListNew = [];
            $workerConfList = WORKER_CONF;
            // Specify Process to Run When dev or test to debug, Avoid the impact of other processes
            $onlyProcess = Helper::getCliParams('only');
            if ($onlyProcess) {
                $onlyProcessItems = explode(',', $onlyProcess);
            }

            if (!empty($onlyProcessItems) && (SystemEnv::isCronService() || SystemEnv::isDaemonService()) && (SystemEnv::isTestEnv() || SystemEnv::isDevEnv())) {
                foreach ($workerConfList as  $workerConfItem) {
                    $processName = $workerConfItem['process_name'];
                    if (in_array($processName, $onlyProcessItems)) {
                        $workerConfListNew[] = $workerConfItem;
                    }
                }

                if (empty($workerConfListNew)) {
                    fmtPrintError("Not Found Specify Process --only={$onlyProcess}, All Process Exited!");
                    $masterPid = $mainManager->getMasterPid();
                    // kill master to exit
                    \Swoole\Process::kill($masterPid, SIGTERM);
                }else {
                    $workerConf = $workerConfListNew;
                }
            }else {
                $workerConf = $workerConfList;
            }
        }

        return $workerConf ?? [];
    }
}
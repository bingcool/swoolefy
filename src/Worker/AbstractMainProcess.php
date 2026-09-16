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

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Swfy;
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
        try {
            $workerConf = $this->parseWorkerConf();
            if (!empty($workerConf)) {
                $mainManager = \Swoolefy\Worker\MainManager::getInstance();
                $mainManager->onHandleException = function (\Throwable $throwable) {
                    throw $throwable;
                };
                $mainManager->loadConf($workerConf);
            }
        }catch (\Throwable $exception) {
            BaseServer::catchException($exception);
            fmtPrintError(sprintf("管理进程报错，error msg=%s, trace=%s", $exception->getMessage(), $exception->getTraceAsString()));
            Swfy::getServer()->shutdown();
        }
    }

    /**
     * @return array|mixed
     */
    protected function parseWorkerConf()
    {
        // 分组配置：不传 --group 时启动全部组；传 --group 时只启动指定组（多个用英文逗号分隔），k8s按照分组部署
        // php daemon.php start Test
        // php daemon.php start Test --group=group_1
        // php daemon.php start Test --group=group_1,group_2
        //
        // 指定只启动某一个进程，开发，调试使用
        // php daemon.php start Test --only=order-sync
        // php cron.php start Test --only=order-sync

        if (function_exists('customLoadWorkerConf')) {
            $workerConfList = customLoadWorkerConf();
        } else if (defined('WORKER_CONF_FILE')) {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $workerConfList = \Swoolefy\Worker\MainManager::loadWorkerConf(WORKER_CONF_FILE);
        }

        $workerConfListNew = [];
        if (!empty($workerConfList)) {
            // Specify Process to Run When dev or test to debug, Avoid the impact of other processes
            $onlyProcess = Helper::getCliParams('only');
            if ($onlyProcess) {
                $onlyProcessItems = explode(',', $onlyProcess);
            }

            if (!empty($onlyProcessItems) && (SystemEnv::isCronService() || SystemEnv::isDaemonService()) ) {
                foreach ($workerConfList as  $workerConfItem) {
                    $processName = $workerConfItem['process_name'];
                    if (in_array($processName, $onlyProcessItems)) {
                        $workerConfListNew[] = $workerConfItem;
                    }
                }

                if (empty($workerConfListNew)) {
                    fmtPrintError("Not Found Specify Process --only={$onlyProcess}, All Process Exited!");
                    if (isset($mainManager)) {
                        $masterPid = $mainManager->getMasterPid();
                        // kill master to exit
                        \Swoole\Process::kill($masterPid, SIGTERM);
                    }
                } else {
                    $workerConf = $workerConfListNew;
                }
            } else {
                $workerConf = $workerConfList;
            }
        }

        return $workerConf ?? [];
    }
}
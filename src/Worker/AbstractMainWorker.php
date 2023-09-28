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
use Swoolefy\Core\Log\LogManager;
use Swoolefy\Core\Process\AbstractProcess;

/**
 * swoole的自定义进程作为管理进程
 */
abstract class AbstractMainWorker extends AbstractProcess
{
    /**
     * @return void
     */
    public function init()
    {
        $this->rebuildLogger();
        if(defined('WORKER_CONF') && !SystemEnv::isScriptService()) {
            $mainManager = \Swoolefy\Worker\MainManager::getInstance();
            $mainManager->loadConf(WORKER_CONF);
        }
    }

    /**
     * @param array $logTypes
     * @param int $rotateDay
     * @return void
     */
    protected function rebuildLogger(array $logTypes = [], int $rotateDay = 1)
    {
        if (empty($logTypes)) {
            $logTypes = PROCESS_CLASS['Log'] ?? [];
        }

        if (SystemEnv::isScriptService()) {
            foreach($logTypes as $logType) {
                $logger = LogManager::getInstance()->getLogger($logType);
                if ($logger) {
                    if ($rotateDay >=2) {
                        $rotateDay = 2;
                    }
                    $logger->setRotateDay($rotateDay);
                    $filePath = $logger->getLogFilePath();
                    $filePathDir = pathinfo($filePath, PATHINFO_DIRNAME);
                    $class = str_replace('\\', '/', static::class);
                    $items = explode('/', $class);
                    $nums = count($items);
                    if ($nums >= 2) {
                        $fileName = $items[$nums - 2].'_'.$items[$nums -1];
                    }else {
                        $fileName = array_pop($items);
                    }
                    $dir = "/script/{$logType}/" . $fileName . '.log';
                    $filePath = $filePathDir . $dir;
                    $logger->setLogFilePath($filePath);
                }
            }
        }
    }
}
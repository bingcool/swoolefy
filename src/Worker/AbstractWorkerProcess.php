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

/**
 * 由swoole的自定义进程作为管理进程--拉起的子进程
 */

abstract class AbstractWorkerProcess extends AbstractBaseWorker
{
    /**
     * @var int
     */
    protected $maxHandle = 10000;

    /**
     * @var int
     */
    protected $lifeTime = 3600;

    /**
     * @var int
     */
    protected $currentRunCoroutineLastCid = 50000;

    /**
     * @var int
     */
    protected $limitCurrentRunCoroutineNum = null;

    /**
     * @var bool
     */
    protected $registerLogFlag = true;

    /**
     * init
     */
    protected function init()
    {
        $this->registerLogFlag && $this->registerLogComponents();
        $this->maxHandle                   = $this->getArgs()['max_handle'] ?? $this->maxHandle;
        $this->lifeTime                    = $this->getArgs()['life_time'] ?? $this->lifeTime;
        $this->currentRunCoroutineLastCid  = $this->getArgs()['current_run_coroutine_last_cid'] ?? $this->maxHandle * 10;
        $this->limitCurrentRunCoroutineNum = $this->getArgs()['limit_run_coroutine_num'] ?? null;
        $this->registerTickReboot($this->lifeTime);
        $this->onInit();
    }

    /**
     * onInit
     */
    protected function onInit()
    {

    }

    /**
     * 守护进程循环处理
     *
     * @return void
     */
    protected function loopHandle()
    {

    }

    /**
     * run 入口封装while 循环处理，业务只需要关注loopHandle实现业务即可
     *
     * @return void
     */
    public function run()
    {
        // 封装while(true)循环处理，业务只需要关注loopHandle实现业务即可
        if (method_exists(static::class, 'loopHandle')) {
            /**如果重写run函数，也需要按照以下模式处理
             * 1.设置$this->useLoopHandle = true
             * 2.自己处理进程退出,添加下面代码段，eg:
             * if ($this->waitToExit) {
                $pid = $this->getPid();
                $this->exitNow($pid, 5);
             }
             */

            $this->useLoopHandle = true;
            while (true) {
                if (!$this->isDue()) {
                    continue;
                }

                try {
                    $this->loopHandle();
                }catch (\Throwable $throwable) {
                    $this->onHandleException($throwable);
                }

                \Swoole\Coroutine::sleep(0.05);
                // 当接受到进程退出指令后，会设置waitToExit=true, 等主流程的执行完主业务流程后（即loopHandle业务），进程再退出
                if ($this->waitToExit) {
                    $pid = $this->getPid();
                    $this->exitNow($pid, 5);
                }

                // 定时任务处理完之后，判断达到一定时间，然后重启进程
                if ( (time() > $this->getStartTime() + 3600) && $this->isDue()) {
                    $this->reboot(5);
                }
            }
        }
    }

    /**
     * registerLogComponents
     *
     * @param int $rotateDay
     * @param string $handleClass
     * @return void
     */
    public static function registerLogComponents(int $rotateDay = 2, string $handleClass = null)
    {
        // log register
        $logComponents = include CONFIG_COMPONENT_PATH.DIRECTORY_SEPARATOR.'log.php';
        foreach($logComponents as $logType => $fn) {
            LogManager::getInstance()->registerLoggerByClosure($fn, $logType);
            $logger = LogManager::getInstance()->getLogger($logType);
            if ($logger) {
                if ($rotateDay >= 3 ) {
                    $rotateDay = 3;
                }

                if (empty($handleClass)) {
                    $handleClass = static::class;
                }

                $logger->setRotateDay($rotateDay);
                $filePath = $logger->getLogFilePath();
                $filePathDir = pathinfo($filePath, PATHINFO_DIRNAME);
                $class = str_replace('\\', DIRECTORY_SEPARATOR, $handleClass);
                $items = explode(DIRECTORY_SEPARATOR, $class);
                $fileName = array_pop($items);
                if (SystemEnv::isDaemonService()) {
                    $dir = "{$logType}" .DIRECTORY_SEPARATOR. $fileName . '.log';
                }else if (SystemEnv::isCronService()) {
                    $dir = "{$logType}" .DIRECTORY_SEPARATOR. $fileName . '.log';
                }
                $filePath = $filePathDir . DIRECTORY_SEPARATOR .$dir;
                $logger->setLogFilePath($filePath);
            }
        }
    }

    /**
     * afterReboot
     */
    protected function onAfterReboot()
    {

    }

    /**
     * CreateDynamicProcess
     *
     * @param string $dynamic_process_name
     * @param int $dynamic_process_num
     */
    protected function onCreateDynamicProcessCallback(string $dynamic_process_name, int $dynamic_process_num)
    {

    }

    /**
     * DestroyDynamicProcess
     *
     * @param string $dynamic_process_name
     * @param int $dynamic_process_num
     */
    protected function onDestroyDynamicProcessCallback(string $dynamic_process_name, int $dynamic_process_num)
    {

    }

    /**
     * onShutDown
     */
    public function onShutDown()
    {
        parent::onShutDown();
    }
}
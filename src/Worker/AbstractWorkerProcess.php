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
     * init
     */
    protected function init()
    {
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
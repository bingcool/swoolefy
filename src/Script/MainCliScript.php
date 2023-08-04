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

namespace Swoolefy\Script;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Worker\Helper;;
use Swoolefy\Worker\Script\AbstractScriptWorker;

class MainCliScript extends AbstractScriptWorker {

    /**
     * @var bool
     */
    private $exitAll = false;

    /**
     * @var string
     */
    private $scriptTable = 'table_for_script';

    /**
     * @return void
     */
    public function init()
    {

    }

    /**
     * @return void
     */
    public function run()
    {
        if($this->isExecuted()) {
            write("【Error】一次性脚本进程异常不断重复自动重启，请检查");
            $this->exitAll(true);
            return;
        }

        $this->setIsCliScript();
        try {
            $action = getenv('a');
            list($method, $params) = Helper::parseActionParams($this, $action, Helper::getCliParams());
            $this->{$action}(...$params);
            $this->waitCoroutineFinish();
            $this->exitAll();
        }catch (\Throwable $throwable) {
            write($throwable->getMessage());
            write($throwable->getTraceAsString());
            $this->exitAll();
            return;
        } finally {
            $this->exitAll(true);
        }
    }

    /**
     * 记录脚本执行标志，只执行一次，防止自定义进程异常退出后swoole自动拉起，重复执行
     *
     * @return bool
     */
    protected function isExecuted(): bool
    {
        $count = TableManager::count($this->scriptTable);
        if($count > 0) {
            return true;
        }
        TableManager::set($this->scriptTable,'script_flag', ['is_execute_flag' => 1]);
        return false;
    }

    /**
     * 防止脚本创建协程时，主进程脚本直接退出了，会把协程也退出，导致协程没执行，所以需要等待一段时间，让协程执行完
     *
     * @param float $timeOut
     * @return void
     */
    protected function waitCoroutineFinish(float $timeOut = 5.0)
    {
        $time = time();
        while (true) {
            $status = \Swoole\Coroutine::stats();
            if ($status['coroutine_num'] == 1 || time() > ($time + $timeOut)) {
                break;
            }
            \Swoole\Coroutine\System::sleep(0.5);
        }
    }

    /**
     * @return void
     */
    private function setIsCliScript()
    {
        defined('IS_CLI_SCRIPT') or define('IS_CLI_SCRIPT', 1);
    }

    /**
     * @param bool $force
     * @return void
     */
    protected function exitAll(bool $force = false)
    {
        $swooleMasterPid = Swfy::getMasterPid();
        if(\Swoole\Process::kill($swooleMasterPid, 0) && !$this->exitAll) {
            if($force) {
                \Swoole\Process::kill($swooleMasterPid, SIGKILL);
            }else {
                \Swoole\Process::kill($swooleMasterPid, SIGTERM);
            }
            $this->exitAll = true;
            $pidFile = Swfy::getConf()['setting']['pid_file'];
            @unlink($pidFile);
        }
    }

    /**
     * @return string
     */
    public static function parseClass()
    {
        $class = getenv('r');
        if(empty($class)) {
            write("【Error】Missing cli router param. eg: --r=Test/Scripts/FixedUser/fixName");
            return '';
        }

        $routerArr = explode('/', trim($class, '/'));
        $action    = array_pop($routerArr);
        $class     = implode('\\', $routerArr);

        if(!is_subclass_of($class, __CLASS__)) {
            write("【Error】Missing class={$class} extends \Swoolefy\Script\MainCliScript");
            return '';
        }
        putenv("a={$action}");
        return $class;
    }
}
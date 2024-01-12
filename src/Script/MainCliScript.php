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
use Swoolefy\Worker\Helper;
use Swoolefy\Worker\Script\AbstractScriptWorker;
use Swoolefy\Core\Coroutine\Context;

class MainCliScript extends AbstractScriptWorker
{

    /**
     * @var bool
     */
    private $exitAll = false;

    /**
     * @var string
     */
    private $scriptTable = 'table_for_script';

    /**
     * @var array
     */
    protected $forbiddenActions = [];

    /**
     * @return void
     */
    public function init()
    {
        write("【Info】 script start \n", 'green');
        parent::init();
        $this->generateTraceId();
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

        if (!Context::has('trace-id')) {
            $this->generateTraceId();
        }

        $this->setIsCliScript();
        try {
            $action = getenv('a');
            if (in_array($action, $this->forbiddenActions)) {
                write("【Warning】function action [$action] forbidden to exec!");
                $this->exitAll(true);
                return;
            }
            write("【Info】 running action={$action}......\n", 'green');
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
     * @return void
     */
    private function generateTraceId()
    {
        Context::set('trace-id', \Swoolefy\Util\Helper::UUid());
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
            $pidFile = Swfy::getConf()['setting']['pid_file'];
            if (is_file($pidFile)) {
                @unlink($pidFile);
            }else {
                $pidFile = parseScriptPidFile($pidFile);
                if (is_file($pidFile)) {
                    @unlink($pidFile);
                }
            }

            write("【Info】 script end! ", 'green');
            if($force) {
                \Swoole\Process::kill($swooleMasterPid, SIGKILL);
            }else {
                \Swoole\Process::kill($swooleMasterPid, SIGTERM);
            }
            $this->exitAll = true;
        }
    }

    /**
     * @return string
     */
    public static function parseClass()
    {
        $route = getenv('r');
        if(empty($route)) {
            write("【Error】Missing cli router param. eg: --r=FixedUser/fixName --name=xxxx");
            return '';
        }

        $routerArr = explode('/', trim($route, '/'));
        $action    = array_pop($routerArr);

        if (defined('ROOT_NAMESPACE')) {
            $rootNamespace = ROOT_NAMESPACE;
            $nameSpace = $rootNamespace[APP_NAME];
            $nameSpace = str_replace('\\', '/', $nameSpace);
            $nameSpaceArr = explode('/', trim($nameSpace, '/'));
            $routerArr = array_merge($nameSpaceArr, $routerArr);
            $class     = implode('\\', $routerArr);
        }else {
            $class     = implode('\\', $routerArr);
        }
        if(!is_subclass_of($class, __CLASS__)) {
            write("【Error】Missing class={$class} extends \Swoolefy\Script\MainCliScript");
            return '';
        }

        putenv("route=$route");
        putenv("a={$action}");
        return $class;
    }
}
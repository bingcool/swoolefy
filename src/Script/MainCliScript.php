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

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Exec;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Exception\SystemException;
use Swoolefy\Worker\Helper;
use Swoolefy\Worker\Script\AbstractScriptProcess;
use Swoolefy\Core\Coroutine\Context;

class MainCliScript extends AbstractScriptProcess
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
     *
     * @var float
     */
    protected $maxWaitTime = 5.0;

    /**
     * @var array
     */
    protected $forbiddenActions = [];

    /**
     * @return void
     */
    public function init()
    {
        if (!Context::has('x-trace-id')) {
            $this->generateTraceId();
        }
        BaseServer::saveCronScriptPidFile();
        parent::init();
    }

    /**
     * @return void
     */
    public function run()
    {
        if($this->isExecuted()) {
            fmtPrintError("一次性脚本进程异常不断重复自动重启，请检查");
            $this->exitAll(true, 5);
            return;
        }
        $this->setIsCliScript();
        try {
            $action = getenv('a');
            if (in_array($action, $this->forbiddenActions)) {
                fmtPrintError("Function action=[$action] forbidden to exec!");
                $this->exitAll(true, 0);
                return;
            }
            $handleClass = getenv('handle_class');
            list($method, $params) = Helper::parseActionParams($this, $action, Helper::getCliParams());
            fmtPrintInfo("Running Script: {$handleClass}::{$action}()");
            $this->{$action}(...$params);
            $this->waitCoroutineFinish($this->maxWaitTime);
            $this->exitAll();
        }catch (\Throwable $throwable) {
            fmtPrintError($throwable->getMessage().': trace='.$throwable->getTraceAsString());
            try {
                $this->onHandleException($throwable);
            }catch (\Throwable $exception) {
            }
        } finally {
            $this->exitAll(true, 0);
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
        Context::set('x-trace-id', \Swoolefy\Util\Helper::UUid());
    }

    /**
     * 防止脚本创建协程时，进程脚本继续往下执行，会直接执行existAll()退出了，会把未执行完的协程也退出了，导致协程没执行完毕，所以需要等待一段时间，让协程执行完
     *
     * @param float $maxTimeOut 最长等待时间单位秒
     * @return void
     */
    protected function waitCoroutineFinish(float $maxTimeOut = 5.0)
    {
        $time = time();
        while (true) {
            $status = \Swoole\Coroutine::stats();
            if ($status['coroutine_num'] == 1 || time() > ($time + $maxTimeOut)) {
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
        defined('IS_SCRIPT_SERVICE') or define('IS_SCRIPT_SERVICE', 1);
    }

    /**
     * @param bool $force
     * @param int $waitTime
     * @return void
     */
    protected function exitAll(bool $force = false, int $waitTime = 3)
    {
        if ($waitTime > 0) {
            \Swoole\Coroutine\System::sleep($waitTime);
        }
        $swooleMasterPid = Swfy::getMasterPid();
        $exist = \Swoole\Process::kill($swooleMasterPid, 0);

        if($exist && !$this->exitAll) {
            $pidFile = Swfy::getConf()['setting']['pid_file'];
            if (is_file($pidFile)) {
                @unlink($pidFile);
            }else {
                $pidFile = parseScriptPidFile($pidFile);
                if (is_file($pidFile)) {
                    @unlink($pidFile);
                }
            }

            if (file_exists(WORKER_PID_FILE)) {
                @unlink(WORKER_PID_FILE);
            }

            fmtPrintInfo("script end! ");

            if ($force) {
                @\Swoole\Process::kill($swooleMasterPid, SIGKILL);
            }else {
                @\Swoole\Process::kill($swooleMasterPid, SIGTERM);
            }

            $this->exitAll = true;
        }else if ($exist) {
            // strict kill exit process
            \Swoole\Coroutine\System::sleep(5);
            if (\Swoole\Process::kill($swooleMasterPid, 0)) {
                $managerProcessId = Swfy::getServer()->manager_pid;
                $workerProcessIds = (new Exec())->run('pgrep -P ' . $managerProcessId)->getOutput();
                foreach ([$swooleMasterPid, $managerProcessId, ...$workerProcessIds] as $processId) {
                    if ($processId > 0 && \Swoole\Process::kill($processId, 0)) {
                        @\Swoole\Process::kill($processId, SIGKILL);
                    }
                }
            }
        }
    }


    /**
     * @return string
     */
    public static function parseClass()
    {
        $command = getenv('c');
        if(empty($command)) {
            throw new SystemException("【Error】Missing cli command param. eg: --c=fixed:user:name --name=xxxx ");
        }

        if (!defined('ROOT_NAMESPACE')) {
            throw new SystemException("【Error】The script.php not define const `ROOT_NAMESPACE`.");
        }

        $rootNamespace = ROOT_NAMESPACE;
        $nameSpace = $rootNamespace[APP_NAME];
        $nameSpace = str_replace('\\', '/', $nameSpace);
        $nameSpaceArr = explode('/', trim($nameSpace, '/'));

        $kernelNameSpace = array_merge($nameSpaceArr, ['Kernel']);
        /**
         * @var \Swoolefy\Script\AbstractKernel $kernelClass
         */
        $kernelClass = implode('\\', $kernelNameSpace);
        $commands = $kernelClass::getCommands() ?? [];
        if (!isset($commands[$command])) {
            throw new SystemException("【Error】 Kernel::commands property not defined command={$command}.");
        }
        $class  = $commands[$command][0];
        $action = $commands[$command][1];

        if(!is_subclass_of($class, __CLASS__)) {
            throw new SystemException("【Error】class={$class} bust be extended \Swoolefy\Script\MainCliScript");
        }

        putenv("handle_class={$class}");
        putenv("a={$action}");
        return $class;
    }
}
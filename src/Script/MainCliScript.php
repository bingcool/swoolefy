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
use Swoolefy\Worker\AbstractMainWorker;

class MainCliScript extends AbstractMainWorker {

    /**
     * @var bool
     */
    private $exitAll = false;

    /**
     * @return void
     */
    public function run()
    {
        $this->setIsCliScript();
        try {
            $action = getenv('a');
            $this->{$action}();
            $this->exitAll();
        }catch (\Throwable $exception) {
            write($exception->getMessage());
            write($exception->getTraceAsString());
            $this->exitAll();
            return;
        } finally {
            $this->exitAll(true);
        }
    }

    /**
     * @return void
     */
    private function setIsCliScript()
    {
        defined('IS_CLI_SCRIPT') or define('IS_CLI_SCRIPT',1);
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
        }
    }

    /**
     * @return string|void
     */
    public static function parseClass()
    {
        $class = getenv('r');
        if(empty($class)) {
            write("【Error】Missing cli router param --r=xxxxx");
            return '';
        }
        $routerArr = explode('/', trim($class, '/'));
        $action = array_pop($routerArr);
        $class = implode('\\', $routerArr);
        if(!is_subclass_of($class, __CLASS__)) {
            write("【Error】Missing class={$class} extends \Swoolefy\Script\MainCliScript");
            return '';
        }
        putenv("a={$action}");
        return $class;
    }
}
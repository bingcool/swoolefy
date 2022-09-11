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

class MainScript extends AbstractMainWorker {

    /**
     * @return void
     */
    public function run()
    {
        $isExit = false;
        try {
            $action = getenv('a');
            $this->{$action}();
            $isExit = true;
            $this->exitAll();
        }catch (\Throwable $exception) {
            write($exception->getMessage());
            write($exception->getTraceAsString());
            $this->exitAll();
            $isExit = true;
            return;
        } finally {
            if(!$isExit) {
                $this->exitAll(true);
            }
        }
    }

    /**
     * @param bool $force
     * @return void
     */
    protected function exitAll(bool $force = false)
    {
        $swooleMasterPid = Swfy::getMasterPid();
        if(\Swoole\Process::kill($swooleMasterPid, 0)) {
            if($force) {
                \Swoole\Process::kill($swooleMasterPid, SIGKILL);
            }else {
                \Swoole\Process::kill($swooleMasterPid, SIGTERM);
            }
        }
    }

    /**
     * @return string|void
     */
    public static function parseClass()
    {
        $class = getenv('r');
        if(empty($class)) {
            return '';
        }
        $routerArr = explode('/', trim($class, '/'));
        $action = array_pop($routerArr);
        $class = implode('\\', $routerArr);
        if(!is_subclass_of($class, __CLASS__)) {
            write("【Error】Missing class={$class} extends \Swoolefy\Script\MainScript");
            return '';
        }
        putenv("a={$action}");
        return $class;
    }
}
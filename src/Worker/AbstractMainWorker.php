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

use Swoolefy\Core\Process\AbstractProcess;

abstract class AbstractMainWorker extends AbstractProcess {

    /**
     * @return array
     */
    protected function parseCliEnvParams()
    {
        $cliParams = [];
        $args = array_splice($_SERVER['argv'], 3);
        array_reduce($args, function ($result, $item) use (&$cliParams) {
            // start daemon
            if (in_array($item, ['-d', '-D'])) {
                putenv('daemon=1');
                defined('IS_DAEMON') OR define('IS_DAEMON', 1);
            } else if (in_array($item, ['-f', '-F'])) {
                // stop force
                putenv('force=1');
                $cliParams['force'] = 1;
            } else {
                $item = ltrim($item, '--');
                putenv($item);
                list($env, $value) = explode('=', $item);
                if ($env && $value) {
                    $cliParams[$env] = $value;
                }
            }
        });

        defined('WORKER_MASTER_ID') or define('WORKER_MASTER_ID', $this->getPid());
        defined('WORKER_CLI_PARAMS') or define('WORKER_CLI_PARAMS', json_encode($cliParams,JSON_UNESCAPED_UNICODE));
        return $cliParams;
    }

    /**
     * beforeStart
     */
    abstract protected function beforeStart();
}
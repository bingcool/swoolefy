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

namespace Swoolefy\AutoReload;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\Process\AbstractProcess;

class ReloadProcess extends AbstractProcess
{

    /**
     * @throws \Exception
     */
    public function run()
    {
        $conf = Swfy::getConf();
        if (isset($conf['reload_conf'])) {
            $reloadConfig = $conf['reload_conf'];
            $autoReload = new Reload();
            if (isset($reloadConfig['after_seconds'])) {
                $autoReload->setAfterSeconds((float)$reloadConfig['after_seconds']);
            } else {
                $autoReload->setAfterSeconds();
            }

            if (isset($reloadConfig['reload_file_types']) && is_array($reloadConfig['reload_file_types'])) {
                $autoReload->setReloadFileType($reloadConfig['reload_file_types']);
            } else {
                $autoReload->setReloadFileType();
            }

            if (isset($reloadConfig['ignore_dirs']) && is_array($reloadConfig['ignore_dirs'])) {
                $autoReload->setIgnoreDirs($reloadConfig['ignore_dirs']);
            } else {
                $autoReload->setIgnoreDirs();
            }

            $autoReload->init();
            $autoReload->watch($reloadConfig['monitor_path'])->onReload($reloadConfig['callback']);
        }
    }

    /**
     * @param $msg
     * @param mixed ...$args
     * @return mixed
     */
    public function onReceive($msg, ...$args)
    {
    }

    /**
     * @return mixed|void
     */
    public function onShutDown()
    {
    }
}
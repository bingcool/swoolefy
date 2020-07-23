<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
 */

namespace Swoolefy\AutoReload;

use Swoole\Process;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Process\AbstractProcess;

class ReloadProcess extends AbstractProcess {

    /**
     * @throws \Exception
     */
    public function run() {
        $config = Swfy::getConf();
        if(isset($config['reload_conf'])) {
            $reload_config = $config['reload_conf'];
            $autoReload = new Reload();
            if(isset($reload_config['after_seconds'])){
                $autoReload->setAfterSeconds((float)$reload_config['after_seconds']);
            }else {
                $autoReload->setAfterSeconds();
            }

            if(isset($reload_config['reload_file_types']) && is_array($reload_config['reload_file_types'])) {
                $autoReload->setReloadFileType($reload_config['reload_file_types']);
            }else {
                $autoReload->setReloadFileType();
            }

            if(isset($reload_config['ingore_dirs']) && is_array($reload_config['ingore_dirs'])) {
                $autoReload->setIgnoreDirs($reload_config['ingore_dirs']);
            }else {
                $autoReload->setIgnoreDirs();
            }

            // 初始化配置
            $autoReload->init();
            // 开始监听
            $autoReload->watch($reload_config['monitor_path'])->onReload($reload_config['callback']);
        }
    }

    /**
     * @param $str
     * @param mixed ...$args
     * @return mixed|void
     */
    public function onReceive($str, ...$args) {}

    /**
     * @return mixed|void
     */
    public function onShutDown() {}
}
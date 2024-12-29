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

use Swoolefy\Worker\AbstractMainProcess;

class GenerateDaemonService extends MainCliScript {

    const command = "gen:daemon:service";

    public function generate() {
        $serviceName = $this->getOption('service');
        if (empty($serviceName)) {
            $serviceName = 'WorkerDaemon';
        }

        $serviceName = ucfirst($serviceName);

        $servicePath = APP_PATH."/{$serviceName}";

        if (!is_dir($servicePath)) {
            mkdir($servicePath, 0777, true);
        }

        $confPath = $servicePath."/conf";
        if (!is_dir($confPath)) {
            mkdir($confPath, 0777, true);
        }

        $testConFile = $confPath."/test_conf.php";
        if (!is_file($testConFile)) {
            file_put_contents($testConFile, $this->generateTemplate());
        }

        $workerCronConfFile = $servicePath."/worker_daemon_conf.php";

        if (!is_file($workerCronConfFile)) {
            file_put_contents($workerCronConfFile, $this->generateTemplateConf());
        }

        $mainDaemonProcessFile = $servicePath."/MainDaemonProcess.php";
        if (!is_file($mainDaemonProcessFile)) {
            file_put_contents($mainDaemonProcessFile, $this->generateTemplateMain());
        }

        $testProcessFile = $servicePath."/TestWorkerProcess.php";
        if (!is_file($testProcessFile)) {
            file_put_contents($testProcessFile, $this->generateTestProcess());
        }
    }

    protected function generateTemplate() {
$template = <<<PHP
<?php

return [
    [
        // 进程
        'process_name' => 'test-worker',
        'handler' => \<TEMP_APP_NAME>\WorkerDaemon\TestWorkerProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 60, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, //当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => []
    ],
];
PHP;
    return str_replace('<TEMP_APP_NAME>', APP_NAME,  $template);
    }

    protected function generateTemplateMain() {
        $tempDaemonMain = <<<PHP
<?php
namespace <TEMP_APP_NAME>\WorkerDaemon;

use Swoolefy\Worker\AbstractMainProcess;

class MainDaemonProcess extends AbstractMainProcess {
    /**
     * @return void
     */
    public function run()
    {
        try {
            \$mainManager = \Swoolefy\Worker\MainManager::getInstance();
            // 状态上报存表
            \$mainManager->onReportStatus = function (array \$status) {
            };
            
            \$mainManager->start();
        }catch (\Throwable \$exception) {
            var_dump(\$exception->getMessage(), \$exception->getTraceAsString());
        }
    }
}
PHP;
        return str_replace('<TEMP_APP_NAME>', APP_NAME, $tempDaemonMain);
    }

    protected function generateTestProcess() {
        $testProcess = <<<PHP
<?php
namespace <TEMP_APP_NAME>\WorkerDaemon;

class TestWorkerProcess extends \Swoolefy\Worker\AbstractWorkerProcess
{
    public function run()
    {
        while (true) {
            echo "test WorkerProcess\n";
            sleep(10);
        }
    }
}
PHP;
        return str_replace('<TEMP_APP_NAME>', APP_NAME, $testProcess);
    }

    protected function generateTemplateConf() {
        return <<<PHP
<?php

return array_merge(
    include __DIR__."/conf/test_conf.php",
);
PHP;
    }
}
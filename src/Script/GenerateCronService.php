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

class GenerateCronService extends MainCliScript {

    const command = "gen:cron:service";

    public function generate()
    {
        fmtPrintInfo("------开始初始化生成cron服务项目-------");
        $serviceName = $this->getOption('service');
        if (empty($serviceName)) {
            $serviceName = 'WorkerCron';
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

        $sceduleConFile = $confPath."/schedule_conf.php";
        if (!is_file($sceduleConFile)) {
            file_put_contents($sceduleConFile, $this->generateTemplate());
        }

        $workerCronConfFile = $servicePath."/worker_cron_conf.php";
        if (!is_file($workerCronConfFile)) {
            file_put_contents($workerCronConfFile, $this->generateTemplateConf());
        }

        $mainCronProcessFile = $servicePath."/MainCronProcess.php";
        if (!is_file($mainCronProcessFile)) {
            file_put_contents($mainCronProcessFile, $this->generateTemplateMain());
        }

        fmtPrintInfo("------已生成cron服务项目-------");
    }

    protected function generateTemplate() {

$TemplateCron = <<<PHP
<?php
use <TEMP_APP_NAME>\Scripts\Kernel;

// 定时fork进程处理任务
return [
     [
          'process_name' => 'system-schedule-task', // 进程名称
          'handler' => \Swoolefy\Worker\Cron\CronForkProcess::class,
          'description' => '系统fork模式任务调度',
          'worker_num' => 1, // 默认动态进程数量
          'max_handle' => 100, //消费达到10000后reboot进程
          'life_time' => 3600, // 每隔3600s重启进程
          'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
          'extend_data' => [],
          'args' => [
              // 定时任务列表
              'task_list' => Kernel::buildScheduleTaskList(Kernel::schedule())
        
              // 动态定时任务列表，可以存在数据库中
              // 'task_list' => function () {
              // return include __DIR__ . '/schedule_task.php';
              // }
        ],
    ],
];
PHP;

    return str_replace('<TEMP_APP_NAME>', APP_NAME, $TemplateCron);
    }

    protected function generateTemplateMain() {
$tempCronMain = <<<PHP
<?php
namespace <TEMP_APP_NAME>\WorkerCron;

use Swoolefy\Worker\AbstractMainProcess;

class MainCronProcess extends AbstractMainProcess
{
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
        } catch (\Throwable \$exception) {
            \$this->onHandleException(\$exception);
        }
    }
}
PHP;
    return str_replace('<TEMP_APP_NAME>', APP_NAME, $tempCronMain);
    }

    protected function generateTemplateConf() {
return <<<PHP
<?php

return array_merge(
    include __DIR__ . "/conf/schedule_conf.php",
    //include  __DIR__."/conf/order_conf.php",
    //include  __DIR__."/conf/product_conf.php",
);
PHP;
    }
}
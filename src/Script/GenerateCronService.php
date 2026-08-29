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

    public function handle()
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

        $scheduleForkConFile = $confPath."/schedule_fork_conf.php";
        if (!is_file($scheduleForkConFile)) {
            file_put_contents($scheduleForkConFile, $this->generateForkTemplate());
        }

        $scheduleUrlConFile = $confPath."/schedule_url_conf.php";
        if (!is_file($scheduleUrlConFile)) {
            file_put_contents($scheduleUrlConFile, $this->generateUrlTemplate());
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

    protected function generateForkTemplate() {

$TemplateCron = <<<PHP
<?php
use <TEMP_APP_NAME>\Scripts\Kernel;

// 定时fork进程处理任务
return [
        [
        'process_name' => 'schedule-fork-task-cron', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronForkProcess::class,
        'description' => '系统fork模式任务调度',
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            // CronManager 唯一调度：配置轮询间隔（秒）。fetcher 抛异常时保留 Last Known Good Runtime。
            'cron_poll_interval' => env('CRON_POLL_INTERVAL', 20),
            'node_id' => env('CRON_NODE_ID'),
            // 节点心跳间隔（秒, start 立刻 ack 一次，再按此间隔 tick。
            'heartbeat_interval' => env('CRON_HEARTBEAT_INTERVAL', 15),
            // 跨进程 Manual Run：Admin 入队后由本 Polling 执行 runOnceNow，再 ack 清队列
            'run_once_ack' => static function (string \$jobId, int \$cronTaskId, \$result = null, int \$requestId = 0): void {
                unset(\$jobId, \$cronTaskId, \$result);
                (new \App\Module\Cron\Service\CronTaskService())->ackRunOnce(\$requestId);
            },
            // 节点心跳落库：upsert cron_agent_node.last_heartbeat_at / heartbeat_interval
            'node_heartbeat_ack' => static function (string \$nodeId, int \$heartbeatInterval = 15): void {
                (new \App\Module\Cron\Service\CronTaskService())->ackNodeHeartbeat(\$nodeId, \$heartbeatInterval);
            },
            // 动态定时任务列表
            'task_list' => function () {
                // 读取数据库cronTask配置模式
                \$taskList = (new \App\Module\Cron\Service\CronTaskService())
                    ->fetchCronTask(CronProcess::EXEC_FORK_TYPE, env('CRON_NODE_ID'));
                // 返回taskList
                if (!empty(\$taskList)) {
                    return \$taskList;
                } else {
                    return [];
                }
            }
        ],
    ],
];
PHP;

    return str_replace('<TEMP_APP_NAME>', APP_NAME, $TemplateCron);
    }

    protected function generateUrlTemplate() {

        $TemplateCron = <<<PHP
<?php
use <TEMP_APP_NAME>\Scripts\Kernel;

// 定时fork进程处理任务
return [
    // 定时请求远程url触发远程url的任务处理
    [
        'process_name' => 'schedule-url-task-cron', // 进程名称
        'handler' => \Swoolefy\Worker\Cron\CronUrlProcess::class,
        'worker_num' => 1, // 默认动态进程数量
        'max_handle' => 100, //消费达到10000后reboot进程
        'life_time'  => 3600, // 每隔3600s重启进程
        'limit_run_coroutine_num' => 10, // 当前进程的实时协程数量，如果协程数量超过此设置的数量，则禁止继续消费队列处理业务，而是在等待
        'extend_data' => [],
        'args' => [
            // CronManager 唯一调度：配置轮询间隔（秒）。
            'cron_poll_interval' => env('CRON_POLL_INTERVAL', 20),
            'node_id' => env('CRON_NODE_ID'),
            // 跨进程 Manual Run：Admin 入队后由本 Polling 执行 runOnceNow，再 ack 清队列
            'run_once_ack' => static function (string \$jobId, int \$cronTaskId, \$result = null, int \$requestId = 0): void {
                unset(\$jobId, \$cronTaskId, \$result);
                (new \App\Module\Cron\Service\CronTaskService())->ackRunOnce(\$requestId);
            },
            'heartbeat_interval' => env('CRON_HEARTBEAT_INTERVAL', 15),
            'node_heartbeat_ack' => static function (string \$nodeId, int \$heartbeatInterval = 15): void {
                (new \App\Module\Cron\Service\CronTaskService())->ackNodeHeartbeat(\$nodeId, \$heartbeatInterval);
            },

            // 动态定时任务列表
            'task_list' => function () {
                \$taskList = (new \App\Module\Cron\Service\CronTaskService())
                    ->fetchCronTask(CronProcess::EXEC_URL_TYPE, env('CRON_NODE_ID'));
                if (!empty(\$taskList)) {
                    return \$taskList;
                } else {
                    return [];
                }
            }
        ],
    ]
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
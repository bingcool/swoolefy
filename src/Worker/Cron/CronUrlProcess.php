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

namespace Swoolefy\Worker\Cron;

use Swoolefy\Worker\Dto\CronUrlTaskMetaDtoWorker;

/**
 * HTTP Cron Worker。
 *
 * 调度只走父类 CronManager；执行走 executeHttpSnapshot()：
 * 按冻结 Snapshot 发 HTTP，并执行业务 before/response/after 回调。
 * Timeout / 连接失败由 HttpExecutor 收成 FAILED，不拖垮 Worker。
 *
 * 本类不再向 CrontabManager addRule。run() 只调用 runCronTask()。
 *
 * @see CronProcess::runCronTask()
 * @see HttpExecutor
 */
class CronUrlProcess extends CronProcess
{

    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
    }

    /**
     * HTTP 任务走生产引擎；执行隔离异常，Timeout 只失败本轮。
     */
    protected function createCronExecutor(): CronExecutorInterface
    {
        return new HttpExecutor(fn (ExecutionSnapshot $snapshot): array => $this->executeHttpSnapshot($snapshot));
    }

    /**
     * 按冻结 Snapshot 发起 HTTP，并执行业务 before/response/after 回调。
     *
     * @return array{status:int,body?:string}
     */
    protected function executeHttpSnapshot(ExecutionSnapshot $snapshot): array
    {
        $scheduleUrlTask = $snapshot->definition->toLogDto();
        if (!$scheduleUrlTask instanceof CronUrlTaskMetaDtoWorker) {
            return ['status' => 0, 'body' => 'HTTP 任务定义无法转换'];
        }

        if (is_array($scheduleUrlTask->before_callback) && count($scheduleUrlTask->before_callback) == 2) {
            list($class, $action) = $scheduleUrlTask->before_callback;
            (new $class)->{$action}($scheduleUrlTask);
        } elseif ($scheduleUrlTask->before_callback instanceof \Closure) {
            $res = call_user_func($scheduleUrlTask->before_callback, $scheduleUrlTask);
            if ($res === false) {
                return ['status' => 0, 'body' => 'before_callback 返回 false'];
            }
        }

        $url = $scheduleUrlTask->url !== '' ? $scheduleUrlTask->url : $snapshot->definition->command;
        $timeout = (int) ($scheduleUrlTask->request_time_out ?? $snapshot->definition->httpRequestTimeOut);
        $client = new \GuzzleHttp\Client([
            'timeout' => max(1, $timeout),
            'connect_timeout' => (int) ($scheduleUrlTask->connect_time_out ?? 30),
            'http_errors' => false,
        ]);
        $method = strtoupper($scheduleUrlTask->method ?: 'GET');
        $options = [
            'headers' => $scheduleUrlTask->headers ?? [],
        ];
        $params = $scheduleUrlTask->params ?? [];
        if ($method === 'GET' && $params !== []) {
            $options['query'] = $params;
        } elseif ($params !== []) {
            $options['json'] = $params;
        }
        $raw = $client->request($method, $url, $options);
        $status = $raw->getStatusCode();
        $body = (string) $raw->getBody();

        if (is_array($scheduleUrlTask->response_callback) && count($scheduleUrlTask->response_callback) == 2) {
            list($class, $action) = $scheduleUrlTask->response_callback;
            (new $class)->{$action}($raw, $scheduleUrlTask);
        } elseif ($scheduleUrlTask->response_callback instanceof \Closure) {
            call_user_func($scheduleUrlTask->response_callback, $raw, $scheduleUrlTask);
        }

        if (is_array($scheduleUrlTask->after_callback) && count($scheduleUrlTask->after_callback) == 2) {
            list($class, $action) = $scheduleUrlTask->after_callback;
            (new $class)->{$action}($scheduleUrlTask);
        } elseif ($scheduleUrlTask->after_callback instanceof \Closure) {
            call_user_func($scheduleUrlTask->after_callback, $scheduleUrlTask);
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * 启动生产引擎。Worker 级异常才 reboot；单个 URL 异常已在 Executor 内隔离。
     */
    public function run()
    {
        try {
            parent::run();
            $this->runCronTask();
        }catch (\Throwable $throwable) {
            $context = [
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'message' => $throwable->getMessage(),
                'code' => $throwable->getCode(),
                "reboot_count" => $this->getRebootCount(),
                'trace' => $throwable->getTraceAsString(),
            ];
            parent::onHandleException($throwable, $context);
            sleep(2);
            $this->reboot();
        }
    }
}
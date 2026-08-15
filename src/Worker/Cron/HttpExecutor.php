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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * HTTP 执行器（exec_type=2）。
 *
 * 边界：只消费 ExecutionSnapshot，不改 Timer、本类不重试（retry 由 CronManager 编排）、
 * 不把异常抛出 Worker。
 * CronUrlProcess 可注入 $transport，以便复用业务 before/response/after 回调。
 *
 * 映射：url ?: command → URL；http_method / http_body / http_headers / http_request_time_out。
 * GET + body 走 query；其它方法 + body 走 json。2xx 为 SUCCESS，其余状态码 FAILED。
 *
 * Timeout / 连接失败 / 非法 URL 只让本轮 Execution Failed，Scheduler 已先 arm 下一轮。
 * 响应 body 失败日志截断到 200 字符，避免撑爆 cron_task_log。
 *
 * @see CompositeExecutor
 * @see CronUrlProcess::executeHttpSnapshot()
 */
final class HttpExecutor implements CronExecutorInterface
{
    /**
     * @param null|callable(ExecutionSnapshot):array{status:int,body?:string} $transport 可注入，便于单测
     */
    public function __construct(private readonly mixed $transport = null)
    {
    }

    /**
     * 按冻结 Snapshot 发 HTTP。transport 返回 ['status'=>int,'body'=>?string]；未注入则用 Guzzle。
     */
    public function run(ExecutionSnapshot $snapshot): ExecutionResult
    {
        $definition = $snapshot->definition;
        // 非法 URL 本轮 FAILED，不抛异常；Scheduler 已先 arm 下一轮
        $url = $definition->url !== '' ? $definition->url : $definition->command;
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ExecutionResult::failed('Invalid URL: ' . $url);
        }

        $timeout = max(1, $definition->httpRequestTimeOut);
        $method = $definition->httpMethod !== '' ? $definition->httpMethod : 'GET';

        // 生产路径由 SwooleCronTimer goApp 保证本方法在协程内（Guzzle/curl hook 需要）
        try {
            if (is_callable($this->transport)) {
                $response = ($this->transport)($snapshot);
                $status = (int) ($response['status'] ?? 0);
                $body = (string) ($response['body'] ?? '');
            } else {
                $client = new Client([
                    'timeout' => $timeout,
                    'connect_timeout' => min(30, $timeout),
                    'http_errors' => false,
                ]);
                $options = [
                    'headers' => $definition->httpHeaders,
                ];
                if (!in_array($method, ['GET', 'HEAD'], true) && $definition->httpBody !== []) {
                    $options['json'] = $definition->httpBody;
                } elseif ($method === 'GET' && $definition->httpBody !== []) {
                    $options['query'] = $definition->httpBody;
                }
                $raw = $client->request($method, $url, $options);
                $status = $raw->getStatusCode();
                $body = (string) $raw->getBody();
            }

            if ($status >= 200 && $status < 300) {
                return ExecutionResult::success(
                    sprintf('HTTP %s %s status=%d', $method, $url, $status),
                    0,
                    null,
                    $status,
                );
            }

            return ExecutionResult::failed(
                sprintf('HTTP %s %s status=%d body=%s', $method, $url, $status, mb_substr($body, 0, 200)),
                0,
                null,
                $status,
            );
        } catch (GuzzleException $e) {
            return ExecutionResult::failed(sprintf('HTTP Timeout/连接失败 url=%s error=%s', $url, $e->getMessage()));
        } catch (\Throwable $e) {
            return ExecutionResult::failed(sprintf('HTTP 执行异常 url=%s error=%s', $url, $e->getMessage()));
        }
    }
}

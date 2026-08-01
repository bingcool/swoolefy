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

namespace Swoolefy\Http;

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Task\TaskController;
use Swoolefy\Http\OpenTelemetry\OpenTelemetryConfig;
use Swoolefy\Http\OpenTelemetry\OpenTelemetryHttpCollector;
use Swoolefy\Library\OpenTelemetry\API\Globals;
use Swoolefy\Library\OpenTelemetry\API\Trace\SpanKind;
use Swoolefy\Library\OpenTelemetry\API\Trace\StatusCode;
use Swoolefy\Library\OpenTelemetry\Contrib\Context\Swoole\SwooleContextScope;
use Swoolefy\Library\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;

abstract class HttpAppServer extends HttpServer
{

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    abstract public function onWorkerStart(Server $server, int $worker_id);

    /**
     * onRequest
     * @param Request $request
     * @param Response $response
     * @return bool
     * @throws \Throwable
     */
    public function onRequest(Request $request, Response $response)
    {
        $appInstance = new \Swoolefy\Core\App();
        $appInstance->run($request, $response);
        return true;
    }

    /**
     * @param Request $request
     * @return array
     */
    protected function startOpenTelemetry(Request $request)
    {
        $globalEnabled = (bool) env('OTEL_PHP_AUTOLOAD_ENABLED', false);
        $route = $request->server['path_info'] ?? '';
        $method = $request->server['request_method'] ?? '';
        $routeOption = Route::findRouteOption((string) $route, (string) $method);
        if (!OpenTelemetryHttpCollector::shouldCollect($globalEnabled, $routeOption)) {
            return [null, null, null, null];
        }
        /**
         * @var \Swoolefy\Library\OpenTelemetry\SDK\Trace\Tracer $tracer
         */
        $tracer = Globals::tracerProvider()->getTracer(env('OTEL_TRACING_NAME','swoolefy-http-request'), '1.0.0');
        $inputBody = [];
        $queryParams = [];
        $carrier = $this->normalizeHeaderKeys($request->header ?? []);
        $contentType = $this->getHeaderValue($carrier, 'content-type', '');
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $post = $request->post ?? [];
            // OTEL 采样不得因非法 JSON 中断；业务路径在 RequestParseTrait 再解析并抛 400
            try {
                $input = RequestBodyParser::parseJsonPayload($contentType, $request->rawContent(), $method);
            } catch (\Swoolefy\Exception\InvalidJsonException $e) {
                $input = [];
            }
            $inputBody = array_merge($post, $input);
        } elseif ($method === 'GET') {
            $queryParams = $request->get ?? [];
        }
        $otelConfig = OpenTelemetryConfig::load();
        $attributes = OpenTelemetryHttpCollector::buildAttributes(
            (string) $method,
            (string) $route,
            $carrier,
            $inputBody,
            $queryParams,
            $otelConfig,
            \Swoole\Coroutine::getCid(),
            (string) gethostname(),
        );
        $parentContext = TraceContextPropagator::getInstance()->extract($carrier);
        $spanName = $route ? sprintf("%s %s %s (server)", "HTTP", $method, $route) : sprintf("%s %s %s (server)", "HTTP", $method, "/");
        $spanBuilder = $tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parentContext)
            ->startSpan();
        foreach ($attributes as $key => $value) {
            $spanBuilder->setAttribute($key, $value);
        }
        $rootSpan = $spanBuilder;
        $scope   = $rootSpan->activate();
        $traceId = $rootSpan->getContext()->getTraceId();
        $traceparent = $this->getHeaderValue($carrier, TraceContextPropagator::TRACEPARENT);
        if ($traceparent === null || $traceparent === '') {
            // {version}-{trace-id}-{parent-id}-{trace-flags}
            $traceparent = join('-', ["00", $traceId, $rootSpan->getContext()->getSpanId(), $rootSpan->getContext()->getTraceFlags() ? "01" : "00"]);
        }
        /**
         * @var SwooleContextScope $scope
         */
        $scope->detach();
        return  [$rootSpan, $scope, $traceId, $traceparent ?? ""];
    }

    /**
     * @param $span
     * @return void
     */
    protected function endOpenTelemetry($span)
    {
        if (!env('OTEL_PHP_AUTOLOAD_ENABLED', false)) {
            return;
        }
        /**
         * @var \Swoolefy\Library\OpenTelemetry\SDK\Trace\Span $span
         */
        $span->setStatus(StatusCode::STATUS_OK, "Successful");
        $span->end();
    }

    /**
     * @param $span
     * @param $exception
     * @return void
     */
    protected function errorOpenTelemetry($span, $exception)
    {
        if (!env('OTEL_PHP_AUTOLOAD_ENABLED', false)) {
            return;
        }
        /**
         * @var \Swoolefy\Library\OpenTelemetry\SDK\Trace\Span $span
         */
        if ($exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $span->end();
        }
    }

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    abstract public function onPipeMessage(Server $server, int $from_worker_id, $message);

    /**
     * onTask
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param mixed $task
     * @return void
     * @throws \Throwable
     */
    public function onTask(Server $server, int $task_id, int $from_worker_id, $data, $task = null)
    {
        /** @var TaskController|null $taskInstance */
        $taskInstance = null;
        try {
            list($callable, $taskData, $contextData, $fd) = $data;
            list($className, $action) = $callable;

            $taskInstance = new $className;
            $taskInstance->setTaskId((int)$task_id);
            $taskInstance->setFromWorkerId((int)$from_worker_id);
            $task && $taskInstance->setTask($task);
            foreach ($contextData as $key => $value) {
                \Swoolefy\Core\Coroutine\Context::set($key, $value);
            }
            $taskInstance->$action($taskData);
            $taskInstance->afterHandle();

            unset($callable, $extendData, $fd);
        } catch (\Throwable $throwable) {
            // 业务异常向上抛出；清理统一放 finally，避免成功路径漏 end
            throw $throwable;
        } finally {
            // task_enable_coroutine=false 时无 defer，必须主动 end；已由协程 defer 接管则不重复结束
            if ($taskInstance !== null && !$taskInstance->isDefer()) {
                try {
                    $taskInstance->end();
                } catch (\Throwable $cleanupThrowable) {
                    // 清理异常不得覆盖原始业务异常
                    BaseServer::catchException($cleanupThrowable);
                }
            }
        }
    }

    /**
     * onFinish
     * @param Server $server
     * @param int $task_id
     * @param mixed $data
     * @return void
     */
    public function onFinish(Server $server, int $task_id, $data)
    {
    }

}	
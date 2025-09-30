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

use Common\Library\OpenTelemetry\API\Globals;
use Common\Library\OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use Common\Library\OpenTelemetry\API\Trace\SpanKind;
use Common\Library\OpenTelemetry\API\Trace\StatusCode;
use Common\Library\OpenTelemetry\SemConv\TraceAttributes;
use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\Task\TaskController;

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
        $appInstance = new \Swoolefy\Core\App(Swfy::getAppConf());
        $appInstance->run($request, $response);
        return true;
    }

    /**
     * @param Request $request
     * @return array
     */
    protected function startOpenTelemetry(Request $request)
    {
        if (!env('OTEL_PHP_AUTOLOAD_ENABLED', false)) {
            return [null, null, null, null];
        }
        /**
         * @var \Common\Library\OpenTelemetry\SDK\Trace\Tracer $tracer
         */
        $tracer = Globals::tracerProvider()->getTracer(env('OTEL_TRACING_NAME','swoolefy-http-request'), '1.0.0');
        $route = $request->server['path_info'] ?? '';
        $method = $request->server['request_method'] ?? '';
        $inputBody = [];
        if ($method == 'POST' || $method == 'PUT' || $method == 'DELETE') {
            $post = $request->post ?? [];
            $input = json_decode($request->rawContent(), true) ?? [];
            $inputBody = array_merge($post, $input);
        }else if ($method == 'GET') {
            $queryParams = $request->get ?? [];
            $queryString = "";
            foreach ($queryParams as $key => $value) {
                $queryString .= $key . "=" . $value . "&";
            }
            $queryString = rtrim($queryString, "&");
        }
        $carrier = $request->header;
        $parentContext = TraceContextPropagator::getInstance()->extract($carrier);
        $spanName = $route ? sprintf("%s %s %s (server)", "HTTP", $method, $route) : sprintf("%s %s %s (server)", "HTTP", $method, "/");
        $span = $tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setParent($parentContext)
            ->startSpan()
            ->setAttribute(TraceAttributes::COROUTINE_ID, \Swoole\Coroutine::getCid())
            ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $method)
            ->setAttribute(TraceAttributes::URL_PATH, $route)
            ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY, json_encode($inputBody, JSON_UNESCAPED_UNICODE))
            ->setAttribute(TraceAttributes::HTTP_REQUEST_HEADERS, json_encode($request->header, JSON_UNESCAPED_UNICODE))
            ->setAttribute(TraceAttributes::HTTP_REQUEST_QUERY_PARAMS, $queryString)
            ->setAttribute(TraceAttributes::HTTP_USER_AGENT, $headers['User-Agent'] ?? 'unknown')
            ->setAttribute(TraceAttributes::HTTP_USER_AGENT, $headers['User-Agent'] ?? 'unknown')
            ->setAttribute(TraceAttributes::SERVER_ADDRESS, gethostname())
        ;
        $scope = $span->activate();
        $traceId = $span->getContext()->getTraceId();
        if (isset($carrier[TraceContextPropagator::TRACEPARENT])) {
            $traceparent = $carrier[TraceContextPropagator::TRACEPARENT];
        }else {
            // {version}-{trace-id}-{parent-id}-{trace-flags}
            $traceparent = join('-', ["00", $traceId, $span->getContext()->getSpanId(), $span->getContext()->getTraceFlags() ? "01" : "00"]);
        }
        return  [$span, $scope, $traceId, $traceparent ?? ""];
    }

    /**
     * @param $span
     * @param $scope
     * @return void
     */
    protected function endOpenTelemetry($span, $scope)
    {
        if (!env('OTEL_PHP_AUTOLOAD_ENABLED', false)) {
            return;
        }
        /**
         * @var \Common\Library\OpenTelemetry\SDK\Trace\Span $span
         */
        $span->setStatus(StatusCode::STATUS_OK, "Successful");
        $scope->detach();
        $span->end();
    }

    /**
     * @param $span
     * @param $scope
     * @param $exception
     * @return void
     */
    protected function errorOpenTelemetry($span, $scope, $exception)
    {
        if (!env('OTEL_PHP_AUTOLOAD_ENABLED', false)) {
            return;
        }
        /**
         * @var \Common\Library\OpenTelemetry\SDK\Trace\Span $span
         */
        if ($exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $scope->detach();
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
        try {
            list($callable, $taskData, $contextData, $fd) = $data;
            list($className, $action) = $callable;

            /**@var TaskController $taskInstance */
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
            if(!$taskInstance->isDefer()) {
                $taskInstance->end();
            }
            throw $throwable;
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
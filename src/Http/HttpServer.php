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

use Swoole\Coroutine;
use Swoolefy\Core\EventApp;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Util\Helper;
use Swoolefy\Worker\CtlApi;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoolefy\Support\FrameworkContext;
use Swoolefy\Support\HeaderPropagation\HeaderContext;
use Swoolefy\Support\HeaderPropagation\HeaderPropagator;

abstract class HttpServer extends BaseServer
{

    /**
     * WorkerService 无 HTTP 控制面时的固定错误码（对外不暴露内部服务类型）。
     */
    public const WORKER_SERVICE_HTTP_UNAVAILABLE = 'worker_service_http_unavailable';

    /**
     * serverName
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_HTTP;

    /**
     * setting
     * @var array
     */
    public static $setting = [
        'reactor_num'     => 1,
        'worker_num'      => 1,
        'max_request'     => 1000,
        'task_tmpdir'     => '/dev/shm',
        'daemonize'       => 0,
        'hook_flags'      => SWOOLE_HOOK_ALL,
        'log_file'        => APP_PATH . '/log/log.txt',
        'pid_file'        => APP_PATH . '/log/server.pid',
    ];

    /**
     * webServer
     * @var \Swoole\Http\Server
     */
    protected $webServer = null;

    /**
     * Normalize HTTP header names before tracing so Swoole/client casing differences do not break propagation.
     */
    protected function normalizeHeaderKeys(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string)$key)] = $value;
        }

        return $normalized;
    }

    /**
     * Read a header from a normalized or raw header array.
     */
    protected function getHeaderValue(array $headers, string $name, ?string $default = null): ?string
    {
        $key = strtolower($name);
        foreach ($headers as $headerName => $value) {
            if (strtolower((string)$headerName) !== $key) {
                continue;
            }

            return is_scalar($value) ? (string)$value : $default;
        }

        return $default;
    }

    /**
     * __construct
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        self::clearCache();
        self::$config = $config;
        self::$setting = array_merge(self::$setting, self::$config['setting']);
        self::$config['setting'] = self::$setting;
        self::setSwooleSockType();
        self::setServerName(self::SERVER_NAME);
        self::$server = $this->webServer = new \Swoole\Http\Server(self::$config['host'], self::$config['port'], self::$swooleProcessModel, self::$swooleSocketType);
        $this->webServer->set(self::$setting);
        parent::__construct();
    }

    /**
     * start
     */
    public function start()
    {
        /**
         * start
         */
        $this->webServer->on('Start', function (\Swoole\Http\Server $server) {
            try {
                self::setMasterProcessName(self::$config['master_process_name']);
                $this->saveCronScriptPidFile();
                $this->startCtrl->start($server);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * managerStart
         */
        $this->webServer->on('ManagerStart', function (\Swoole\Http\Server $server) {
            try {
                self::setManagerProcessName(self::$config['manager_process_name']);
                EventApp::run(function () use ($server) {
                    $this->startCtrl->managerStart($server);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * managerStop
         */
        $this->webServer->on('ManagerStop', function (\Swoole\Http\Server $server) {
            try {
                EventApp::run(function () use ($server) {
                    $this->startCtrl->managerStop($server);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * WorkerStart
         */
        $this->webServer->on('WorkerStart', function (\Swoole\Http\Server $server, $worker_id) {
            $this->workerStartInit($server, $worker_id);
        });

        /**
         * request
         */
        $this->webServer->on('request', function (Request $request, Response $response) {
            if (IgnoreRouteConfig::shouldIgnore($request)) {
                $response->end();
                return true;
            }

            if (SystemEnv::isWorkerService()) {
                // Cron/Daemon 控制面走 CtlApi；其余 WorkerService 必须显式 end，禁止空返回导致请求悬挂
                if (self::shouldServeWorkerServiceHttpControlPlane()) {
                    goApp(function () use($request, $response) {
                        (new CtlApi($request, $response))->handle();
                        return true;
                    });
                } else {
                    self::endWorkerServiceHttpUnavailable($response);
                }
                return true;
            } else {
                try {
                    /**
                     * @var \Swoolefy\Library\OpenTelemetry\SDK\Trace\Span $span
                     */
                    $headers = $this->normalizeHeaderKeys($request->header ?? []);
                    list ($span, $scope, $traceId, $traceparent) = $this->startOpenTelemetry($request);
                    if (empty($traceId)) {
                        $traceId = $this->getHeaderValue($headers, OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, Helper::UUid());
                    }
                    SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, $traceId);
                    SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_TRACEPARENT_ID, $traceparent ?? "");
                    HeaderContext::set(HeaderPropagator::captureIncoming($headers, $traceId));
                    // 请求结束清除本地验票快照，避免常驻 Worker 协程上下文残留身份（最终也会由swoole在协程结束后释放的）
                    Coroutine::defer(static function () {
                        FrameworkContext::clearUser();
                        HeaderContext::clear();
                    });
                    static::onRequest($request, $response);
                    if (isset($span)) {
                        Coroutine::defer(function () use ($span) {
                            SwooleContext::set(OpentelemetryMiddleware::IS_CALL_ENDOPENTELEMETRY, 1);
                            $this->endOpenTelemetry($span);
                        });
                    }
                    return true;
                } catch (\Throwable $e) {
                    self::catchException($e);
                    if (isset($span) && !SwooleContext::has(OpentelemetryMiddleware::IS_CALL_ENDOPENTELEMETRY)) {
                        Coroutine::defer(function () use ($span, $e) {
                            $this->errorOpenTelemetry($span, $e);
                        });
                    }
                    // 外层异常（OpenTelemetry / onRequest 前置等）可能尚未写出响应
                    if ($response->isWritable()) {
                        try {
                            $response->status(500);
                            $response->header('Content-Type', 'application/json; charset=utf-8');
                            $response->end(json_encode([
                                'code' => 500,
                                'msg' => 'Internal Server Error',
                                'data' => null,
                            ], JSON_UNESCAPED_UNICODE));
                        } catch (\Throwable $endError) {
                            // 已部分写出或连接已关闭时忽略
                        }
                    }
                }
            }
        });

        /**
         * task
         */
        if (!SystemEnv::isWorkerService()) {
            if (parent::isTaskEnableCoroutine()) {
                $this->webServer->on('task', function (\Swoole\Http\Server $server, \Swoole\Server\Task $task) {
                    try {
                        $data           = $task->data;
                        $task_id        = $task->id;
                        $from_worker_id = $task->worker_id;
                        $task_data      = unserialize($data, ['allowed_classes' => false]);
                        static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }
                });
            } else {
                $this->webServer->on('task', function (\Swoole\Http\Server $server, $task_id, $from_worker_id, $data) {
                    try {
                        $task_data = unserialize($data, ['allowed_classes' => false]);
                        static::onTask($server, $task_id, $from_worker_id, $task_data);
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }

                });
            }
        }

        /**
         * finish
         */
        $this->webServer->on('finish', function (\Swoole\Http\Server $server, $task_id, $data) {
            try {
                $params = unserialize($data, ['allowed_classes' => false]);
                list($data, $contextData) = $params;
                EventApp::run(function () use ($server, $task_id, $data, $contextData) {
                    foreach ($contextData as $key=>$value) {
                        \Swoolefy\Core\Coroutine\Context::set($key, $value);
                    }
                    static::onFinish($server, $task_id, $data);
                });
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * pipeMessage
         */
        $this->webServer->on('pipeMessage', function (\Swoole\Http\Server $server, $from_worker_id, $message) {
            try {
                EventApp::run(function () use ($server, $from_worker_id, $message) {
                    static::onPipeMessage($server, $from_worker_id, $message);
                });
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * workerStop
         */
        $this->webServer->on('WorkerStop', function (\Swoole\Http\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use ($server, $worker_id) {
                try {
                    EventApp::run(function () use ($server, $worker_id) {
                        $this->startCtrl->workerStop($server, $worker_id);
                    });
                } catch (\Throwable $e) {
                    self::catchException($e);
                }
            });
        });

        /**
         * workerExit
         */
        $this->webServer->on('WorkerExit', function (\Swoole\Http\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use ($server, $worker_id) {
                try {
                    EventApp::run(function () use ($server, $worker_id) {
                        $this->startCtrl->workerExit($server, $worker_id);
                    });
                } catch (\Throwable $e) {
                    self::catchException($e);
                }
            });
        });

        /**
         * WorkerError
         * tips callback function manager进程中发生的,不能使用创建协程和使用协程api,否则报错
         */
        $this->webServer->on('WorkerError', function (\Swoole\Http\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
            try {
                EventApp::run(function () use ($server, $worker_id, $worker_pid, $exit_code, $signal) {
                    $this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        $this->webServer->start();
    }

    /**
     * Cron/Daemon + HTTP 控制面才走 CtlApi；其他 WorkerService 组合一律 503。
     */
    public static function shouldServeWorkerServiceHttpControlPlane(): bool
    {
        return (SystemEnv::isCronService() || SystemEnv::isDaemonService()) && self::isHttpApp();
    }

    /**
     * 固定不透明错误体：仅暴露约定 code，不带出内部服务类型/配置。
     *
     * @return string
     */
    public static function buildWorkerServiceHttpUnavailableBody(): string
    {
        return json_encode([
            'code' => self::WORKER_SERVICE_HTTP_UNAVAILABLE,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 无 HTTP 控制面的 WorkerService：显式结束响应，避免请求悬挂。
     */
    public static function endWorkerServiceHttpUnavailable(Response $response): void
    {
        $response->status(503);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->end(self::buildWorkerServiceHttpUnavailableBody());
    }

}

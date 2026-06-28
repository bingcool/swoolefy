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

namespace Swoolefy\Websocket;

use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoole\WebSocket\Frame;
use Swoolefy\Core\EventApp;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Util\Helper;
use Swoolefy\Core\Coroutine\Context as SwooleContext;

abstract class WebsocketServer extends BaseServer
{
    /**
     * serverName
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_WEBSOCKET;

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
     * @var \Swoole\WebSocket\Server
     */
    protected $webServer = null;

    /**
     * __construct
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        self::clearCache();
        self::$config = $config;
        $websocketConfig = self::$config['websocket'] ?? [];
        // WebSocket 连接状态必须在 Swoole Server 启动前创建 table，才能被所有 worker 共享。
        self::$config['table'] = array_merge(
            self::$config['table'] ?? [],
            WebsocketConnectionManager::tableDefinitions($websocketConfig)
        );
        if (!empty($websocketConfig['metrics']['enable'])) {
            self::$config['table'] = array_merge(
                self::$config['table'],
                \Swoolefy\Websocket\Metrics\WebsocketMetrics::tableDefinitions()
            );
        }
        if (!empty($websocketConfig['graceful_shutdown']['enable'])) {
            self::$config['table'] = array_merge(
                self::$config['table'],
                Cluster\WebsocketShutdownCoordinator::tableDefinitions()
            );
        }
        self::$setting = array_merge(self::$setting, self::$config['setting']);
        self::$config['setting'] = self::$setting;
        self::setSwooleSockType();
        self::setServerName(self::SERVER_NAME);
        self::$server = $this->webServer = new \Swoole\WebSocket\Server(self::$config['host'], self::$config['port'], self::$swooleProcessModel, self::$swooleSocketType);
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
        $this->webServer->on('Start', function (\Swoole\WebSocket\Server $server) {
            try {
                self::setMasterProcessName(self::$config['master_process_name']);
                Cluster\WebsocketShutdownCoordinator::installForegroundSignalHandler($server);
                $this->startCtrl->start($server);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * managerStart
         */
        $this->webServer->on('ManagerStart', function (\Swoole\WebSocket\Server $server) {
            try {
                self::setManagerProcessName(self::$config['manager_process_name']);
                (new EventApp())->registerApp(function () use ($server) {
                    $this->startCtrl->managerStart($server);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * managerStop
         */
        $this->webServer->on('ManagerStop', function (\Swoole\WebSocket\Server $server) {
            try {
                (new EventApp())->registerApp(function () use ($server) {
                    $this->startCtrl->managerStop($server);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * WorkerStart
         */
        $this->webServer->on('WorkerStart', function (\Swoole\WebSocket\Server $server, $worker_id) {
            $this->workerStartInit($server, $worker_id);
        });

        /**
         * 自定义handshake,如果子类设置了onHandshake()，函数中必须要"自定义"握手过程,否则将不会建立websockdet连接
         */
        if (method_exists(static::class, 'onHandshake')) {
            $this->webServer->on('handshake', function (Request $request, Response $response) {
                try {
                    if (Cluster\WebsocketShutdownCoordinator::shouldRejectNewConnections()) {
                        $response->status(503);
                        $response->end(Cluster\WebsocketShutdownCoordinator::rejectReason());

                        return false;
                    }
                    // 自定义handshake函数
                    static::onHandshake($request, $response);
                } catch (\Throwable $e) {
                    self::catchException($e);
                }
            });
        }

        /**
         * open
         */
        $this->webServer->on('open', function (\Swoole\WebSocket\Server $server, $request) {
            try {
                if (Cluster\WebsocketShutdownCoordinator::shouldRejectNewConnections()) {
                    $server->disconnect(
                        (int) $request->fd,
                        1008,
                        Cluster\WebsocketShutdownCoordinator::rejectReason()
                    );

                    return false;
                }

                $websocketConfig = self::getWebsocketConfig();
                // 握手完成后的第一道框架鉴权；失败使用 1008(policy violation) 主动断开。
                $auth = WebsocketAuthenticator::authenticate($request, $websocketConfig);
                if (!$auth['ok']) {
                    $server->disconnect((int) $request->fd, 1008, (string) $auth['reason']);
                    return false;
                }

                // 框架 Redis（register/touch 等）与业务 onOpen 均须在 goApp 内，复用协程级 Redis 单例
                goApp(function () use ($server, $request, $websocketConfig, $auth) {
                    if (!empty($websocketConfig['socketio']['enable']) && SocketIO\SocketIOHandler::isSocketIORequest($request)) {
                        SocketIO\SocketIOHandler::onOpen($server, $request, $websocketConfig, (string) $auth['user_id']);
                    } else {
                        WebsocketConnectionManager::open($server, $request, [
                            'user_id' => (string) $auth['user_id'],
                        ]);
                    }

                    static::onOpen($server, $request);
                });

                return true;
            } catch (\Swoolefy\Websocket\Cluster\ClusterRedisException $e) {
                // on_redis_failure=reject_open 时，Redis 注册失败拒绝建连
                if ($server->isEstablished((int) $request->fd)) {
                    $server->disconnect((int) $request->fd, 1011, 'cluster registry failed');
                }
                self::catchException($e);
                return false;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * message
         */
        $this->webServer->on('message', function (\Swoole\WebSocket\Server $server, Frame $frame) {
            try {
                SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, Helper::UUid());
                parent::beforeHandle();

                try {
                    // 分片帧重组：finish=false 时缓存，收齐后再进入 Socket.IO / 原生 WS 处理（无 Redis，留在 goApp 外）
                    $frame = WebsocketFrameAssembler::feed($frame);
                } catch (WebsocketFrameException $exception) {
                    WebsocketFrameAssembler::clear((int) $frame->fd);
                    if ($server->isEstablished((int) $frame->fd)) {
                        $server->disconnect((int) $frame->fd, 1009, 'fragmentation error');
                    }
                    return false;
                }

                if ($frame === null) {
                    return true;
                }

                $websocketConfig = self::getWebsocketConfig();
                $this->dispatchMessageFrame($server, $frame, $websocketConfig);
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * task
         */
        if (!SystemEnv::isWorkerService()) {
            if (parent::isTaskEnableCoroutine()) {
                $this->webServer->on('task', function (\Swoole\WebSocket\Server $server, \Swoole\Server\Task $task) {
                    try {
                        $data           = $task->data;
                        $task_id        = $task->id;
                        $from_worker_id = $task->worker_id;
                        $task_data      = unserialize($data);
                        static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }
                });
            } else {
                $this->webServer->on('task', function (\Swoole\WebSocket\Server $server, $task_id, $from_worker_id, $data) {
                    try {
                        $task_data = unserialize($data);
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
        $this->webServer->on('finish', function (\Swoole\WebSocket\Server $server, $task_id, $data) {
            try {
                $params = unserialize($data);
                list($data, $contextData) = $params;
                (new EventApp())->registerApp(function () use ($server, $task_id, $data, $contextData) {
                    foreach ($contextData as $key=>$value) {
                        SwooleContext::set($key, $value);
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
        $this->webServer->on('pipeMessage', function (\Swoole\WebSocket\Server $server, $from_worker_id, $message) {
            try {
                (new EventApp())->registerApp(function () use ($server, $from_worker_id, $message) {
                    static::onPipeMessage($server, $from_worker_id, $message);
                });
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * close
         */
        $this->webServer->on('close', function (\Swoole\WebSocket\Server $server, $fd, $reactorId) {
            try {
                WebsocketFrameAssembler::clear((int) $fd);
                SocketIO\SocketIOBinaryAssembler::clear((int) $fd);
                goApp(function () use ($server, $fd) {
                    // close 回调是清理 fd/user/group 索引的唯一兜底入口（含 Redis unregister）
                    WebsocketConnectionManager::close((int) $fd);
                    static::onClose($server, $fd);
                });

                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * accept http
         */
        if (isset(self::$config['accept_http'])) {
            $acceptHttpRequest = filter_var(self::$config['accept_http'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($acceptHttpRequest) {
                $this->webServer->on('request', function (Request $request, Response $response) {
                    try {
                        if ($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
                            return $response->end();
                        }
                        if (self::serveSocketIoTestPage($request, $response)) {
                            return true;
                        }
                        $websocketConfig = self::getWebsocketConfig();
                        if (!empty($websocketConfig['socketio']['enable'])
                            && SocketIO\SocketIOHandler::isSocketIOPollingRequest($request)
                            && SocketIO\SocketIOHandler::isPollingEnabled($websocketConfig)) {
                            (new EventApp())->registerApp(function () use ($request, $response, $websocketConfig) {
                                SocketIO\SocketIOPollingHandler::handleHttp($request, $response, $websocketConfig);
                            });

                            return true;
                        }
                        if (SocketIO\SocketIOHandler::isSocketIOHttpRequest($request)) {
                            $response->status(400);
                            return $response->end(json_encode([
                                'code' => -1,
                                'msg' => 'Socket.IO HTTP request unsupported; enable allow_polling or use websocket transport',
                            ], JSON_UNESCAPED_UNICODE));
                        }
                        static::onRequest($request, $response);
                        return true;
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }
                });
            }
        }

        /**
         * WorkerStop
         */
        $this->webServer->on('WorkerStop', function (\Swoole\WebSocket\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use ($server, $worker_id) {
                try {
                    (new EventApp())->registerApp(function () use ($server, $worker_id) {
                        $this->startCtrl->workerStop($server, $worker_id);
                    });
                } catch (\Throwable $e) {
                    self::catchException($e);
                }
            });
        });

        /**
         * WorkerExit
         */
        $this->webServer->on('WorkerExit', function (\Swoole\WebSocket\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use ($server, $worker_id) {
                try {
                    (new EventApp())->registerApp(function () use ($server, $worker_id) {
                        $this->startCtrl->workerExit($server, $worker_id);
                    });
                } catch (\Throwable $e) {
                    self::catchException($e);
                }
            });
        });

        /**
         * WorkerError
         */
        $this->webServer->on('WorkerError', function (\Swoole\WebSocket\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
            try {
                $this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        if (!empty(self::getWebsocketConfig()['graceful_shutdown']['enable'])) {
            Cluster\WebsocketShutdownCoordinator::registerServerShutdownHook($this->webServer);
        }

        $this->webServer->start();
    }

    protected static function getWebsocketConfig(): array
    {
        return self::$config['websocket'] ?? [];
    }

    /**
     * 开发用：accept_http 开启时提供同源测试页，避免 file:// 打开导致 polling XHR 被浏览器拦截。
     */
    protected static function serveSocketIoTestPage(Request $request, Response $response): bool
    {
        $uri = (string) ($request->server['request_uri'] ?? '');
        $path = explode('?', $uri, 2)[0];
        if ($path !== '/socketio-client.html') {
            return false;
        }

        $file = __DIR__ . '/Tests/socketio-client.html';
        if (!is_readable($file)) {
            $response->status(404);
            $response->end('socketio-client.html not found');

            return true;
        }

        $response->header('Content-Type', 'text/html; charset=UTF-8');
        $response->header('Cache-Control', 'no-cache');
        $response->end((string) file_get_contents($file));

        return true;
    }

    protected function workerStartInit($server, $workerId)
    {
        parent::workerStartInit($server, $workerId);

        $websocketConfig = self::getWebsocketConfig();
        $interval = (int) ($websocketConfig['heartbeat_check_interval'] ?? 30);
        $timeout = (int) ($websocketConfig['heartbeat_idle_time'] ?? 90);
        if ($workerId === 0 && $interval > 0 && $timeout > 0) {
            // 心跳扫描只放在 worker 0，所有连接状态在 Swoole\Table 中共享。
            goTick($interval * 1000, function () use ($server, $timeout) {
                WebsocketConnectionManager::disconnectExpired($server, $timeout);
            });
        }

        $clusterInterval = (int) ($websocketConfig['cluster']['cleanup_interval'] ?? 30);
        if ($workerId === 0 && !empty($websocketConfig['cluster']['enable']) && $clusterInterval > 0) {
            // 集群模式：worker 0 定时清理 Redis alive ZSET 中的僵尸连接索引
            goTick($clusterInterval * 1000, function () use ($timeout) {
                Cluster\ClusterConnectionCoordinator::cleanupExpired($timeout);
            });
        }

        if ($workerId === 0 && !empty($websocketConfig['metrics']['enable'])) {
            \Swoolefy\Websocket\Metrics\WebsocketMetrics::bootRow();
            $metricsInterval = \Swoolefy\Websocket\Metrics\WebsocketMetrics::refreshInterval();
            goTick($metricsInterval * 1000, static function () {
                \Swoolefy\Websocket\Metrics\WebsocketMetrics::refreshGauges();
            });
        }
    }
}

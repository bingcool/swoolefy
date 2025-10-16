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

use Swoole\WebSocket\Frame;
use Swoolefy\Core\EventApp;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Util\Helper;

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
        'log_file'        => __DIR__ . '/log/log.txt',
        'pid_file'        => __DIR__ . '/log/server.pid',
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
                (new EventApp())->registerApp(function () use ($server, $request) {
                    static::onOpen($server, $request);
                });
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * message
         */
        $this->webServer->on('message', function (\Swoole\WebSocket\Server $server, Frame $frame) {
            try {
                \Swoolefy\Core\Coroutine\Context::set('x-trace-id', Helper::UUid());
                parent::beforeHandle();
                static::onMessage($server, $frame);
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
                (new EventApp())->registerApp(function () use ($server, $fd) {
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

        $this->webServer->start();
    }
}

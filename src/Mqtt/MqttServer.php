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

namespace Swoolefy\Mqtt;

use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoole\Server;
use Swoolefy\Util\Helper;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventApp;
use Swoolefy\Core\Swfy;
abstract class MqttServer extends BaseServer
{

    /**
     * Swoolefy 协议标识，EventCtrl 据此加载 MQTT 入口。
     */
    const SERVER_NAME = SWOOLEFY_MQTT;

    /**
     * Swoole Server 默认 setting（可被 conf.setting 覆盖）。
     *
     * open_mqtt_protocol 必须 true；生产环境建议同时配置 heartbeat_* 与 package_max_length。
     */
    public static $setting = [
        'reactor_num'        => 1,
        'worker_num'         => 1,
        'max_request'        => 1000,
        'task_tmpdir'        => '/dev/shm',
        'daemonize'          => 0,
        'open_mqtt_protocol' => true,
        'hook_flags'         => SWOOLE_HOOK_ALL,
        'log_file'           => APP_PATH . '/log/log.txt',
        'pid_file'           => APP_PATH . '/log/server.pid',
    ];

    /**
     * $tcpServer
     * @var \Swoole\Server
     */
    public $mqttServer = null;

    /**
     * __construct
     *
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config = [])
    {
        if (!class_exists('Simps\MQTT\Client')) {
            throw new \Exception("Missing class \Simps\MQTT\Client, please 'composer require simps/mqtt'");
        }
        self::clearCache();
        self::$config = $config;
        // 优雅停机 Table 须在 Server 启动前创建，供 Master/Worker 共享
        if (!empty(self::$config['graceful_shutdown']['enable'])) {
            self::$config['table'] = array_merge(
                self::$config['table'] ?? [],
                MqttShutdownCoordinator::tableDefinitions()
            );
        }
        self::$setting = array_merge(self::$setting, self::$config['setting']);
        self::$config['setting'] = self::$setting;
        self::setSwooleSockType();
        self::setServerName(self::SERVER_NAME);
        self::$server = $this->mqttServer = new \Swoole\Server(self::$config['host'], self::$config['port'], self::$swooleProcessModel, self::$swooleSocketType);
        $this->mqttServer->set(self::$setting);
        parent::__construct();
    }

    public function start()
    {
        /**
         * start
         */
        $this->mqttServer->on('Start', function (\Swoole\Server $server) {
            try {
                self::setMasterProcessName(self::$config['master_process_name']);
                $this->startCtrl->start($server);
                MqttShutdownCoordinator::installForegroundSignalHandler($server);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * managerStart
         */
        $this->mqttServer->on('ManagerStart', function (\Swoole\Server $server) {
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
        $this->mqttServer->on('ManagerStop', function (\Swoole\Server $server) {
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
        $this->mqttServer->on('WorkerStart', function (\Swoole\Server $server, $worker_id) {
            $this->workerStartInit($server, $worker_id);
        });

        /**
         * tcp connect
         */
        $this->mqttServer->on('connect', function (\Swoole\Server $server, $fd) {
            try {
                // 停机中直接关 TCP，不等待 CONNECT（Swoole 已停 accept，仍可能有半开连接）
                if (MqttShutdownCoordinator::shouldRejectNewSessions()) {
                    $server->close((int) $fd);

                    return;
                }
                EventApp::run(function () use ($server, $fd) {
                    static::onConnect($server, $fd);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * receive
         */
        $this->mqttServer->on('receive', function (\Swoole\Server $server, $fd, $reactor_id, $data) {
            try {
                $traceId = Helper::UUid();
                \Swoolefy\Core\Coroutine\Context::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, $traceId);
                static::onReceive($server, $fd, $reactor_id, $data);
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * task
         */
        if (!isWorkerService()) {
            if (parent::isTaskEnableCoroutine()) {
                $this->mqttServer->on('task', function (\Swoole\Server $server, \Swoole\Server\Task $task) {
                    try {
                        $from_worker_id = $task->worker_id;
                        $task_id = $task->id;
                        $data = $task->data;
                        $task_data = unserialize($data, ['allowed_classes' => false]);
                        static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }
                });

            } else {
                $this->mqttServer->on('task', function (\Swoole\Server $server, $task_id, $from_worker_id, $data) {
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
        $this->mqttServer->on('finish', function (\Swoole\Server $server, $task_id, $data) {
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
        $this->mqttServer->on('pipeMessage', function (\Swoole\Server $server, $from_worker_id, $message) {
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
         * close
         */
        $this->mqttServer->on('close', function (\Swoole\Server $server, $fd, $reactorId) {
            try {
                // TCP 断开时清理 Worker 内会话（与 Dispatcher::close 互补）
                MqttSessionManager::getInstance()->remove((int) $fd);
                EventApp::run(function () use ($server, $fd) {
                    static::onClose($server, $fd);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * WorkerStop：优雅停机时先排空本 Worker 在途 QoS，再关连接。
         */
        $this->mqttServer->on('WorkerStop', function (\Swoole\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use ($server, $worker_id) {
                try {
                    if (MqttShutdownCoordinator::shouldRejectNewSessions()) {
                        MqttShutdownCoordinator::waitForLocalPendingDrain();
                        foreach (MqttSessionManager::getInstance()->connectedFds() as $fd) {
                            if ($server->exists($fd)) {
                                $server->close($fd);
                            }
                        }
                    }
                    EventApp::run(function () use ($server, $worker_id) {
                        $this->startCtrl->workerStop($server, $worker_id);
                    });
                } catch (\Throwable $e) {
                    self::catchException($e);
                }
            });
        });

        if (!empty(self::$config['graceful_shutdown']['enable'])) {
            MqttShutdownCoordinator::registerServerShutdownHook($this->mqttServer);
        }

        /**
         * WorkerExit
         */
        $this->mqttServer->on('WorkerExit', function (\Swoole\Server $server, $worker_id) {
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
         */
        $this->mqttServer->on('WorkerError', function (\Swoole\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
            try {
                $this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        $this->mqttServer->start();
    }

    /** Worker 启动时重置 SessionManager 单例，避免热重启脏数据。 */
    protected function workerStartInit($server, $workerId)
    {
        MqttSessionManager::reset();
        $mqttConf = Swfy::getConf()['mqtt'] ?? [];
        $mgr = MqttSessionManager::getInstance();
        if (isset($mqttConf['retain_limits']) && is_array($mqttConf['retain_limits'])) {
            $mgr->configureRetainLimits($mqttConf['retain_limits']);
        }
        // QoS2 pending 限额：旧配置缺失时走 SessionManager 保守默认
        if (isset($mqttConf['qos2_pending']) && is_array($mqttConf['qos2_pending'])) {
            $mgr->configureQos2PendingLimits($mqttConf['qos2_pending']);
        }

        parent::workerStartInit($server, $workerId);

        // 会话状态在 Worker 内存：每个 Worker 都要跑维护 tick（QoS2 TTL + keep_alive）
        $interval = (int) ($mqttConf['keepalive_check_interval']
            ?? (self::$setting['heartbeat_check_interval'] ?? 30));
        if ($interval > 0) {
            goTick($interval * 1000, static function () use ($server) {
                $manager = MqttSessionManager::getInstance();
                $manager->cleanupExpiredQos2Pending();
                $manager->closeKeepAliveTimeouts($server);
            });
        }
    }

    /**
     * TCP 收包入口：解析 MQTT 报文并交给 {@see MqttReceiveDispatcher}。
     *
     * auto_protocol：CONNECT 成功后后续报文固定用 Session 协议级别。
     */
    public function onReceive(Server $server, int $fd, int $reactor_id, $data)
    {
        $mqttConf = (array) (Swfy::getConf()['mqtt'] ?? []);
        try {
            $protocolLevel = MqttReceiveDispatcher::resolveReceiveProtocolLevel(
                (int) $fd,
                (string) $data,
                $mqttConf
            );
        } catch (MqttProtocolException $e) {
            // Session 不存在的非 CONNECT（auto_protocol）：协议错误断开
            if ($server->exists((int) $fd)) {
                $server->close((int) $fd);
            }
            throw $e;
        }

        return MqttReceiveDispatcher::dispatch($server, $fd, (string) $data, $protocolLevel);
    }

    /**
     * onTask
     * @param Server $server
     * @param int $task_id
     * @param int $from_worker_id
     * @param mixed $data
     * @param \Swoole\Server\Task|null $task
     * @return bool
     * @throws \Throwable
     */
    public function onTask(Server $server, int $task_id, int $from_worker_id, $data, $task = null)
    {
        //todo
    }

    /**
     * onFinish
     * @param Server $server
     * @param $task_id
     * @param $data
     * @return mixed
     */
    public function onFinish(Server $server, int $task_id, $data)
    {
        // todo
    }

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $from_worker_id
     * @param mixed $message
     * @return void
     */
    public function onPipeMessage(Server $server, int $from_worker_id, $message)
    {
        //todo
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    abstract public function onWorkerStart(Server $server, int $worker_id);

    /**
     * onConnect
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onConnect(Server $server, int $fd);

    /**
     * onClose tcp
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onClose(Server $server, int $fd);

}

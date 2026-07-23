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
use Simps\MQTT\Protocol;
use Simps\MQTT\Protocol\Types;

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
        $this->mqttServer->on('ManagerStop', function (\Swoole\Server $server) {
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
                (new EventApp())->registerApp(function () use ($server, $fd) {
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
                        $task_data = unserialize($data);
                        static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }
                });

            } else {
                $this->mqttServer->on('task', function (\Swoole\Server $server, $task_id, $from_worker_id, $data) {
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
        $this->mqttServer->on('finish', function (\Swoole\Server $server, $task_id, $data) {
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
        $this->mqttServer->on('pipeMessage', function (\Swoole\Server $server, $from_worker_id, $message) {
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
        $this->mqttServer->on('close', function (\Swoole\Server $server, $fd, $reactorId) {
            try {
                // TCP 断开时清理 Worker 内会话（与 Dispatcher::close 互补）
                MqttSessionManager::getInstance()->remove((int) $fd);
                (new EventApp())->registerApp(function () use ($server, $fd) {
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
                    (new EventApp())->registerApp(function () use ($server, $worker_id) {
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
        parent::workerStartInit($server, $workerId);
    }

    /**
     * @param Server $server
     * @param int $fd
     * @param int $reactor_id
     * @param $data
     * @return bool
     * @throws \Throwable
     */
    /**
     * TCP 收包入口：解析 MQTT 报文并交给 {@see MqttReceiveDispatcher}。
     *
     * auto_protocol=true 时从 CONNECT 报文推断 V3/V5。
     */
    public function onReceive(Server $server, int $fd, int $reactor_id, $data)
    {
        $conf = Swfy::getConf();
        $protocolLevel = (int) ($conf['mqtt']['protocol_level'] ?? MQTT_PROTOCOL_LEVEL3);

        // auto_protocol：首包若是 CONNECT，从报文推断 V3/V5
        if (($conf['mqtt']['auto_protocol'] ?? false) === true) {
            $peek = Protocol\V3::unpack($data);
            if (is_array($peek) && ($peek['type'] ?? null) === Types::CONNECT) {
                $protocolLevel = MqttReceiveDispatcher::resolveProtocolLevel($peek);
            }
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

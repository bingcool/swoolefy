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

include_once SWOOLEFY_CORE_ROOT_PATH . '/MainEventInterface.php';

use Swoole\Server;
use Simps\MQTT\Protocol;
use Simps\MQTT\Protocol\Types;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventApp;
use Swoolefy\Core\EventController;
use Swoolefy\Core\RpcEventInterface;
use Swoolefy\Core\Swfy;
use Simps\MQTT\Hex\ReasonCode;

abstract class MqttServer extends BaseServer implements RpcEventInterface
{

    /**
     * $serverName
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_MQTT;

    /**
     * $setting
     * @var array
     */
    public static $setting = [
        'reactor_num'        => 1,
        'worker_num'         => 1,
        'max_request'        => 1000,
        'task_worker_num'    => 1,
        'task_tmpdir'        => '/dev/shm',
        'daemonize'          => 0,
        'open_mqtt_protocol' => true,
        'hook_flags'         => SWOOLE_HOOK_ALL | SWOOLE_HOOK_CURL,
        'log_file'           => __DIR__ . '/log/log.txt',
        'pid_file'           => __DIR__ . '/log/server.pid',
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
        self::$config['setting'] = self::$setting = array_merge(self::$setting, self::$config['setting']);
        self::setSwooleSockType();
        self::setServerName(self::SERVER_NAME);
        self::$server = $this->mqttServer = new \Swoole\Server(self::$config['host'], self::$config['port'], self::$swoole_process_model, self::$swoole_socket_type);
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
                $this->startCtrl->managerStart($server);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * managerStop
         */
        $this->mqttServer->on('ManagerStop', function (\Swoole\Server $server) {
            try {
                $this->startCtrl->managerStop($server);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * WorkerStart
         */
        $this->mqttServer->on('WorkerStart', function (\Swoole\Server $server, $worker_id) {
            // 记录主进程加载的公共files,worker重启不会在加载的
            self::getIncludeFiles($worker_id);
            // 启动动态运行时的Coroutine
            self::runtimeEnableCoroutine();
            // registerShutdown
            self::registerShutdownFunction();
            // 重启worker时，清空字节cache
            self::clearCache();
            // 重新设置进程名称
            self::setWorkerProcessName(self::$config['worker_process_name'], $worker_id, self::$setting['worker_num']);
            // 设置worker工作的进程组
            self::setWorkerUserGroup(self::$config['www_user']);
            // 启动时提前加载文件
            self::startInclude();
            // 记录worker的进程worker_pid与worker_id的映射
            self::setWorkersPid($worker_id, $server->worker_pid);
            // 超全局变量server
            Swfy::setSwooleServer($this->mqttServer);
            // 全局配置
            Swfy::setConf(self::$config);
            // 启动的初始化函数
            (new EventApp())->registerApp(function (EventController $event) use ($server, $worker_id) {
                $this->startCtrl->workerStart($server, $worker_id);
                static::onWorkerStart($server, $worker_id);
            });

        });

        /**
         * tcp connect
         */
        $this->mqttServer->on('connect', function (\Swoole\Server $server, $fd) {
            try {
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
                static::onReceive($server, $fd, $reactor_id, $data);
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * task
         */
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

        /**
         * finish
         */
        $this->mqttServer->on('finish', function (\Swoole\Server $server, $task_id, $data) {
            try {
                (new EventApp())->registerApp(function (EventController $event) use ($server, $task_id, $data) {
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
                (new EventApp())->registerApp(function (EventController $event) use ($server, $from_worker_id, $message) {
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
                (new EventApp())->registerApp(function (EventController $event) use ($server, $fd) {
                    static::onClose($server, $fd);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * WorkerStop
         */
        $this->mqttServer->on('WorkerStop', function (\Swoole\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use ($server, $worker_id) {
                try {
                    (new EventApp())->registerApp(function (EventController $event) use ($server, $worker_id) {
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
        $this->mqttServer->on('WorkerExit', function (\Swoole\Server $server, $worker_id) {
            \Swoole\Coroutine::create(function () use ($server, $worker_id) {
                try {
                    (new EventApp())->registerApp(function (EventController $event) use ($server, $worker_id) {
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

    /**
     * @param $server
     * @param $fd
     * @param $reactor_id
     * @param $data
     * @return bool
     * @throws \Throwable
     */
    public function onReceive($server, $fd, $reactor_id, $data)
    {
        $conf          = Swfy::getConf();
        $protocolLevel = (int)($conf['mqtt']['protocol_level'] ?? MQTT_PROTOCOL_LEVEL3);

        if ($protocolLevel === MQTT_PROTOCOL_LEVEL3) {
            return $this->handleV3($server, $fd, $data);
        } else if ($protocolLevel === MQTT_PROTOCOL_LEVEL5) {
            return $this->handleV5($server, $fd, $data);
        }
    }

    /**
     * handleV3 mqtt receive handle
     *
     * @param Server $server
     * @param int $fd
     * @param mixed $data
     * @return bool
     * @throws \Throwable
     */
    public function handleV3($server, $fd, &$data)
    {
        $data = Protocol\V3::unpack($data);

        if (is_array($data) && isset($data['type'])) {
            $type = $data['type'];
        }

        if (!isset($type)) {
            if ($server->exists($fd)) {
                $server->close($fd);
            }
            throw new \Exception('Mqtt Packet parse missing type');
        }

        $conf       = Swfy::getConf();
        $eventClass = $conf['mqtt']['mqtt_event_handler'] ?? MqttEvent::class;

        /**
         * @var MqttEvent $mqttEvent
         */
        $mqttEvent = new $eventClass($fd, $data);

        try {
            switch ($type) {
                case Types::CONNECT:
                    $protocol_name  = $data['protocol_name'];
                    $protocol_level = $data['protocol_level'] ?? MQTT_PROTOCOL_LEVEL3;
                    $username       = $data['user_name'] ?? '';
                    $password       = $data['password'] ?? '';
                    $clean_session  = $data['clean_session'] ?? 0;
                    $keep_alive     = $data['keep_alive'];
                    $client_id      = $data['client_id'];
                    $will           = $data['will'] ?? [];

                    if (!$mqttEvent->verify($username, $password) || !$mqttEvent->connect(
                            $protocol_name,
                            $protocol_level,
                            $username,
                            $password,
                            $client_id,
                            $keep_alive,
                            $clean_session,
                            $will
                        )) {

                        if ($server->exists($fd)) {
                            $server->close($fd);
                        }

                        return false;
                    }

                    $mqttEvent->connectAck($clean_session);
                    break;

                case Types::PINGREQ:
                    $mqttEvent->pingReq();
                    break;

                case Types::DISCONNECT:
                    $mqttEvent->disconnect();
                    if ($server->exist($fd)) {
                        $server->close($fd);
                    }
                    break;

                case Types::PUBLISH:
                    $topic      = $data['topic'];
                    $message    = $data['message'];
                    $dup        = $data['dup'];
                    $qos        = $data['qos'];
                    $retain     = $data['retain'];
                    $message_id = $data['message_id'] ?? '';

                    // Send to subscribers
                    $mqttEvent->publish(
                        $topic,
                        $message,
                        $dup,
                        $qos,
                        $retain,
                        $message_id
                    );

                    if ($data['qos'] === 1) {
                        $mqttEvent->publishAck($message_id);
                    }
                    break;

                case Types::SUBSCRIBE:
                    $payload    = [];
                    $topics     = $data['topics'];
                    $type       = $data['type'];
                    $message_id = $data['message_id'];

                    if (method_exists($mqttEvent, 'subscribe')) {
                        $mqttEvent->subscribe($type, $topics, $message_id);
                    }

                    foreach ($topics as $k => $qos) {
                        if (is_numeric($qos) && $qos < 3) {
                            $payload[] = chr($qos);
                        } else {
                            $payload[] = chr(0x80);
                        }
                    }

                    $mqttEvent->subscribeAck($message_id, $payload);
                    break;

                case Types::UNSUBSCRIBE:
                    $topics     = $data['topics'];
                    $type       = $data['type'];
                    $message_id = $data['message_id'] ?? '';

                    if (method_exists($mqttEvent, 'unSubscribe')) {
                        $mqttEvent->unSubscribe($type, $topics, $message_id);
                    }

                    $mqttEvent->unSubscribeAck($message_id);
                    break;

                default:
                    throw new \Exception("Mqtt Packet type={$type} error");
            }
        } catch (\Exception | \Throwable $exception) {
            if ($server->exists($fd)) {
                $server->close($fd);
            }
            self::catchException($exception);
            return false;
        }

        return true;
    }

    /**
     * @param $server
     * @param $fd
     * @param $data
     * @return bool
     * @throws \Exception
     */
    public function handleV5($server, $fd, &$data)
    {
        $data = Protocol\V5::unpack($data);

        if (is_array($data) && isset($data['type'])) {
            $type = $data['type'];
        }

        if (!isset($type)) {
            if ($server->exists($fd)) {
                $server->close($fd);
            }
            throw new \Exception('Mqtt Packet parse missing type');
        }

        $conf       = Swfy::getConf();
        $eventClass = $conf['mqtt']['mqtt_event_handler'] ?? MqttEvent5::class;

        /**
         * @var MqttEvent5 $mqttEvent
         */
        $mqttEvent = new $eventClass($fd, $data);

        try {
            switch ($type) {
                case Types::CONNECT:
                    $protocol_name         = $data['protocol_name'];
                    $protocol_level        = $data['protocol_level'];
                    $username              = $data['user_name'] ?? '';
                    $password              = $data['password'] ?? '';
                    $clean_session         = $data['clean_session'] ?? 0;
                    $keep_alive            = $data['keep_alive'];
                    $properties            = $data['properties'];
                    $client_id             = $data['client_id'];
                    $will                  = $data['will'] ?? [];
                    $authentication_method = $properties['authentication_method'] ?? '';
                    $authentication_data   = $properties['authentication_data'] ?? '';

                    // connect 附带authentication_method,authentication_data开启auth验证
                    if (!$mqttEvent->verify($username, $password, $authentication_method, $authentication_data) || !$mqttEvent->connect(
                            $protocol_name,
                            $protocol_level,
                            $username,
                            $password,
                            $client_id,
                            $keep_alive,
                            $properties,
                            $clean_session,
                            $will
                        )) {

                        if ($server->exists($fd)) {
                            $server->close($fd);
                        }

                        return false;
                    }

                    $mqttEvent->connectAck($clean_session);
                    break;

                // connect 附带authentication_method,authentication_data开启auth验证
                case Types::AUTH:
                    $code       = $data['code'];
                    $properties = $data['properties'] ?? [];
                    $mqttEvent->auth($code, $properties);
                    break;

                case Types::PINGREQ:
                    $mqttEvent->pingReq();
                    break;

                case Types::DISCONNECT:
                    $mqttEvent->disconnect();
                    if ($server->exist($fd)) {
                        $server->close($fd);
                    }
                    break;

                case Types::PUBLISH:
                    $topic      = $data['topic'];
                    $message    = $data['message'];
                    $dup        = $data['dup'];
                    $qos        = $data['qos'];
                    $retain     = $data['retain'];
                    $message_id = $data['message_id'] ?? '';
                    // Send to subscribers
                    $mqttEvent->publish(
                        $topic,
                        $message,
                        $dup,
                        $qos,
                        $retain,
                        $message_id
                    );

                    if ($data['qos'] === 1) {
                        $mqttEvent->publishAck($message_id);
                    }
                    break;

                case Types::SUBSCRIBE:
                    $payload    = [];
                    $topics     = $data['topics'];
                    $type       = $data['type'];
                    $message_id = $data['message_id'];

                    if (method_exists($mqttEvent, 'subscribe')) {
                        $mqttEvent->subscribe($type, $topics, $message_id);
                    }

                    foreach ($data['topics'] as $k => $option) {
                        $qos = $option['qos'];
                        if (is_numeric($qos) && $qos < 3) {
                            $payload[] = $qos;
                        } else {
                            $payload[] = ReasonCode::QOS_NOT_SUPPORTED;
                        }
                    }

                    $mqttEvent->subscribeAck($message_id, $payload);
                    break;

                case Types::UNSUBSCRIBE:
                    $topics     = $data['topics'];
                    $type       = $data['type'];
                    $message_id = $data['message_id'] ?? '';

                    if (method_exists($mqttEvent, 'unSubscribe')) {
                        $mqttEvent->unSubscribe($type, $topics, $message_id);
                    }

                    $mqttEvent->unSubscribeAck($message_id);
                    break;

                default:
                    throw new \Exception("Mqtt Packet type={$type} error");
            }
        } catch (\Exception | \Throwable $exception) {
            if ($server->exists($fd)) {
                $server->close($fd);
            }
            self::catchException($exception);
            return false;
        }
        return true;
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
    public function onTask($server, $task_id, $from_worker_id, $data, $task = null)
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
    public function onFinish($server, $task_id, $data)
    {
        // todo
    }

    /**
     * onPipeMessage
     * @param Server $server
     * @param int $src_worker_id
     * @param mixed $message
     * @return void
     */
    public function onPipeMessage($server, $from_worker_id, $message)
    {
        //todo
    }

    /**
     * onWorkerStart
     * @param Server $server
     * @param int $worker_id
     * @return void
     */
    abstract public function onWorkerStart($server, $worker_id);

    /**
     * onConnect
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onConnect($server, $fd);

    /**
     * onClose tcp
     * @param Server $server
     * @param int $fd
     * @return void
     */
    abstract public function onClose($server, $fd);

}

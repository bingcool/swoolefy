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

namespace Swoolefy\Tcp;

use Swoolefy\Core\EventApp;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Util\Helper;

abstract class TcpServer extends BaseServer
{
    /**
     * serverName
     * @var string
     */
    const SERVER_NAME = SWOOLEFY_TCP;

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
     * tcpServer
     * @var \Swoole\Server
     */
    public $tcpServer = null;

    /**
     * $pack
     * @var \Swoolefy\Rpc\Pack
     */
    protected $Pack = null;

    /**
     * $Text text protocol
     * @var \Swoolefy\Rpc\Text
     */
    protected $Text = null;

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
        self::$server = $this->tcpServer = new \Swoole\Server(self::$config['host'], self::$config['port'], self::$swooleProcessModel, self::$swooleSocketType);
        $this->tcpServer->set(self::$setting);
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
        $this->tcpServer->on('Start', function (\Swoole\Server $server) {
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
        $this->tcpServer->on('ManagerStart', function (\Swoole\Server $server) {
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
        $this->tcpServer->on('ManagerStop', function (\Swoole\Server $server) {
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
        $this->tcpServer->on('WorkerStart', function (\Swoole\Server $server, $worker_id) {
            $this->workerStartInit($server, $worker_id);
        });

        /**
         * tcp connect
         */
        $this->tcpServer->on('connect', function (\Swoole\Server $server, $fd) {
            try {
                (new EventApp())->registerApp(function () use ($server, $fd) {
                    $this->onConnect($server, $fd);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * receive
         */
        $this->tcpServer->on('receive', function (\Swoole\Server $server, $fd, $reactor_id, $data) {
            try {
                \Swoolefy\Core\Coroutine\Context::set('trace-id', Helper::UUid());
                parent::beforeHandle();
                if (parent::isPackLength()) {
                    $buffer = $this->Pack->decodePack($fd, $data);
                } else {
                    $buffer = $this->Text->decodePackEof($data);
                }
                if ($buffer) {
                    static::onReceive($server, $fd, $reactor_id, $buffer);
                }
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
                $this->tcpServer->on('task', function (\Swoole\Server $server, \Swoole\Server\Task $task) {
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
                $this->tcpServer->on('task', function (\Swoole\Server $server, $task_id, $from_worker_id, $data) {
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
        $this->tcpServer->on('finish', function (\Swoole\Server $server, $task_id, $data) {
            try {
                $params = unserialize($data);
                list($data, $contextData) = $params;
                (new EventApp())->registerApp(function () use ($server, $task_id, $data, $contextData) {
                    foreach ($contextData as $key=>$value) {
                        \Swoolefy\Core\Coroutine\Context::set($key, $value);
                    }
                    $this->onFinish($server, $task_id, $data);
                });
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * pipeMessage
         */
        $this->tcpServer->on('pipeMessage', function (\Swoole\Server $server, $from_worker_id, $message) {
            try {
                (new EventApp())->registerApp(function () use ($server, $from_worker_id, $message) {
                    $this->onPipeMessage($server, $from_worker_id, $message);
                });
                return true;
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * close
         */
        $this->tcpServer->on('close', function (\Swoole\Server $server, $fd, $reactorId) {
            try {
                if (parent::isPackLength()) {
                    $this->Pack->destroy();
                }
                (new EventApp())->registerApp(function () use ($server, $fd) {
                    $this->onClose($server, $fd);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        /**
         * WorkerStop
         */
        $this->tcpServer->on('WorkerStop', function (\Swoole\Server $server, $worker_id) {
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
        $this->tcpServer->on('WorkerExit', function (\Swoole\Server $server, $worker_id) {
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
         * tips此回调是在manager进程中发生的，不能使用创建协程和使用协程api,否则报错
         */
        $this->tcpServer->on('WorkerError', function (\Swoole\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
            try {
                $this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        $this->tcpServer->start();
    }

}


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

use Swoolefy\Core\EventApp;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\SystemEnv;
use Swoolefy\Util\Helper;
use Swoolefy\Worker\CtlApi;

abstract class HttpServer extends BaseServer
{

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
        'log_file'        => __DIR__ . '/log/log.txt',
        'pid_file'        => __DIR__ . '/log/server.pid',
    ];

    /**
     * webServer
     * @var \Swoole\Http\Server
     */
    protected $webServer = null;

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
        $this->webServer->on('ManagerStop', function (\Swoole\Http\Server $server) {
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
        $this->webServer->on('WorkerStart', function (\Swoole\Http\Server $server, $worker_id) {
            $this->workerStartInit($server, $worker_id);
        });

        /**
         * request
         */
        $this->webServer->on('request', function (Request $request, Response $response) {
            if ($request->server['path_info'] == '/favicon.ico' || $request->server['request_uri'] == '/favicon.ico') {
                $response->end();
                return true;
            }

            if(SystemEnv::isWorkerService()) {
                if ((SystemEnv::isCronService() || SystemEnv::isDaemonService()) && self::isHttpApp()) {
                    goApp(function () use($request, $response) {
                        (new CtlApi($request, $response))->handle();
                        return true;
                    });
                }
            }else {
                try {
                    $traceId = $request->header['trace-id'] ?? Helper::UUid();
                    \Swoolefy\Core\Coroutine\Context::set('trace-id', $traceId);
                    parent::beforeHandle();
                    static::onRequest($request, $response);
                    return true;
                } catch (\Throwable $e) {
                    self::catchException($e);
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
                        $task_data      = unserialize($data);
                        static::onTask($server, $task_id, $from_worker_id, $task_data, $task);
                    } catch (\Throwable $e) {
                        self::catchException($e);
                    }
                });
            } else {
                $this->webServer->on('task', function (\Swoole\Http\Server $server, $task_id, $from_worker_id, $data) {
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
        $this->webServer->on('finish', function (\Swoole\Http\Server $server, $task_id, $data) {
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
        $this->webServer->on('pipeMessage', function (\Swoole\Http\Server $server, $from_worker_id, $message) {
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
         * workerStop
         */
        $this->webServer->on('WorkerStop', function (\Swoole\Http\Server $server, $worker_id) {
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
         * workerExit
         */
        $this->webServer->on('WorkerExit', function (\Swoole\Http\Server $server, $worker_id) {
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
         * tips callback function manager进程中发生的,不能使用创建协程和使用协程api,否则报错
         */
        $this->webServer->on('WorkerError', function (\Swoole\Http\Server $server, $worker_id, $worker_pid, $exit_code, $signal) {
            try {
                (new EventApp())->registerApp(function () use ($server, $worker_id, $worker_pid, $exit_code, $signal) {
                    $this->startCtrl->workerError($server, $worker_id, $worker_pid, $exit_code, $signal);
                });
            } catch (\Throwable $e) {
                self::catchException($e);
            }
        });

        $this->webServer->start();
    }

}

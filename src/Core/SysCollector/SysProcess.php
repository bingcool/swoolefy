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

namespace Swoolefy\Core\SysCollector;

use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Memory\AtomicManager;
use Swoolefy\Core\Process\AbstractProcess;
use Swoolefy\Exception\SystemException;

class SysProcess extends AbstractProcess
{

    /**
     * 默认定时，单位秒
     * @var int
     */
    const DEFAULT_TICK_TIME = 5;

    /**
     * 协程处理一定数量自重启
     * @var int
     */
    const DEFAULT_MAX_TICK_HANDLE_COROUTINE_NUM = 10000;

    /**
     * @var array
     */
    protected $sysCollectorConfig = [];

    /**
     * run system收集信息进程
     *
     * @return mixed
     */
    public function run()
    {
        $conf                     = Swfy::getConf();
        $this->sysCollectorConfig = $conf['sys_collector_conf'];
        if (isset($this->sysCollectorConfig['type'])) {
            $type                      = $this->sysCollectorConfig['type'];
            $tickTime                  = isset($this->sysCollectorConfig['tick_time']) ? (float)$this->sysCollectorConfig['tick_time'] : self::DEFAULT_TICK_TIME;
            $atomic                    = AtomicManager::getInstance()->getAtomicLong('atomic_request_count');
            $maxTickHandleCoroutineNum = $this->sysCollectorConfig['max_tick_handle_coroutine_num'] ?? self::DEFAULT_MAX_TICK_HANDLE_COROUTINE_NUM;
            $isEnablePvCollector       = BaseServer::isEnablePvCollector();
            $callback                  = $this->sysCollectorConfig['callback'] ?? null;

            \Swoole\Timer::tick($tickTime * 1000, function ($timerId) use ($type, $atomic, $tickTime, $isEnablePvCollector, $maxTickHandleCoroutineNum, $callback) {
                try {
                    // reboot for max Coroutine
                    if ($this->getCurrentCoroutineLastCid() > $maxTickHandleCoroutineNum) {
                        \Swoole\Timer::clear($timerId);
                        $this->reboot();
                        return;
                    }
                    // report info
                    if (isset($callback) && $callback instanceof \Closure) {
                        $event = new EventController();
                        $response = $callback->call($event) ?? [];
                        if (is_array($response)) {
                            $collectionInfo = json_encode($response, JSON_UNESCAPED_UNICODE);
                        } else {
                            $collectionInfo = $response;
                        }
                    }
                    // pv
                    $totalRequestNum = $isEnablePvCollector ? $atomic->get() : 0;
                    // clear
                    $totalRequestNum && $atomic->sub($totalRequestNum);
                    $data = [
                        'total_request' => $totalRequestNum,
                        'tick_time'     => $tickTime,
                        'from_service'  => $this->sysCollectorConfig['from_service'] ?? '',
                        'timestamp'     => date('Y-m-d H:i:s')
                    ];
                    $data['sys_collector_message'] = $collectionInfo ?? '';
                    switch ($type) {
                        case SWOOLEFY_SYS_COLLECTOR_UDP:
                            $this->sendByUdp($data);
                            break;
                        case SWOOLEFY_SYS_COLLECTOR_SWOOLEREDIS:
                            $this->publishBySwooleRedis($data);
                            break;
                        case SWOOLEFY_SYS_COLLECTOR_PHPREDIS:
                            $this->publishByPhpRedis($data);
                            break;
                        case SWOOLEFY_SYS_COLLECTOR_FILE:
                            $this->writeByFile($data);
                            break;
                        default:
                            \Swoole\Timer::clear($timerId);
                            break;
                    }
                } catch (\Throwable $throwable) {
                    BaseServer::catchException($throwable);
                }
            });
        }
        return;
    }

    /**
     * sendByUdp 通过UDP发送方式
     *
     * @param array $data
     * @return void
     * @throws mixed
     */
    protected function sendByUdp(array $data = [])
    {
        $udpClient = new \Swoole\Coroutine\Client(SWOOLE_SOCK_UDP);
        $host      = $this->sysCollectorConfig['host'];
        $port      = (int)$this->sysCollectorConfig['port'];
        $timeout   = (int)($this->sysCollectorConfig['timeout'] ?? 3);
        $service   = $this->sysCollectorConfig['target_service'];
        $event     = $this->sysCollectorConfig['event'];

        // Udp data format
        $message = implode(SWOOLEFY_EOF_FLAG, [$service, $event, json_encode($data, JSON_UNESCAPED_UNICODE)]);

        if (empty($host) || empty($port) || empty($service) || empty($event)) {
            throw new SystemException('Config about sys_collector_config of udp is wrong, host, port, service, event of params must be setting');
        }

        if (!$udpClient->isConnected()) {
            $isConnected = $udpClient->connect($host, $port, $timeout);
            if (!$isConnected) {
                throw new SystemException("SysProcess::sendByUdp of connect udp is failed");
            }
        }

        $udpClient->send($message);
    }

    /**
     * publicByRedis swooleRedis的订阅发布方式
     *
     * @param array $data
     * @return void
     * @throws mixed
     */
    protected function publishBySwooleRedis(array $data = [])
    {
        $host     = $this->sysCollectorConfig['host'];
        $port     = (int)$this->sysCollectorConfig['port'];
        $password = $this->sysCollectorConfig['password'] ?? '';
        $timeout  = isset($this->sysCollectorConfig['timeout']) ? (float)$this->sysCollectorConfig['timeout'] : 3;

        $channel = $this->sysCollectorConfig['channel'] ?? SWOOLEFY_SYS_COLLECTOR_CHANNEL;
        goApp(function () use ($host, $port, $password, $timeout, $channel, $data) {
            $redisClient = new \Swoole\Coroutine\Redis();
            $redisClient->setOptions([
                'connect_timeout' => $timeout,
                'timeout'         => -1,
                'reconnect'       => 2
            ]);

            $redisClient->connect($host, $port);
            !empty($password) && $redisClient->auth($password);
            $isConnected = $redisClient->connected;
            if ($isConnected && $data) {
                $message = json_encode($data, JSON_UNESCAPED_UNICODE);
                $redisClient->publish($channel, $message);
            }
        });
    }

    /**
     * publicByPhpRedis phpRedis的订阅发布方式
     *
     * @param array $data
     * @return void
     * @throws mixed
     */
    protected function publishByPhpRedis(array $data = [])
    {
        $host     = $this->sysCollectorConfig['host'];
        $port     = (int)$this->sysCollectorConfig['port'];
        $password = $this->sysCollectorConfig['password'] ?? '';
        $timeout  = isset($this->sysCollectorConfig['timeout']) ? (float)$this->sysCollectorConfig['timeout'] : 60;
        $database = $this->sysCollectorConfig['database'] ?? 0;
        $channel  = $this->sysCollectorConfig['channel'] ?? SWOOLEFY_SYS_COLLECTOR_CHANNEL;

        if (!extension_loaded('redis')) {
            throw new SystemException("Because you enable sys_collector, must be install extension of phpredis", 1);
        }

        if (empty($host) || empty($port) || empty($password)) {
            throw new SystemException('Config of sys_collector_config of phpRedis is wrong, host, port, password of params must be setted');
        }

        $redisClient = new \Redis();
        $redisClient->pconnect($host, $port, $timeout);
        $redisClient->auth($password);
        $redisClient->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        $redisClient->select($database);

        if ($data) {
            $message = json_encode($data, JSON_UNESCAPED_UNICODE);
            $redisClient->publish($channel, $message);
        }
    }

    /**
     * writeByFile 记录到文件
     * @param array $data
     * @return void
     */
    protected function writeByFile(array $data = [])
    {
        $filePath = $this->sysCollectorConfig['file_path'];
        $maxSize  = $this->sysCollectorConfig['max_size'] ?? 2 * 1024 * 1024;

        if (file_exists($filePath)) {
            $fileSize = filesize($filePath);
            if ($fileSize > $maxSize) {
                @unlink($filePath);
            }
        }

        if ($data) {
            $message = json_encode($data, JSON_UNESCAPED_UNICODE);
            @file_put_contents($filePath, $message . PHP_EOL, FILE_APPEND);
        }
    }

    /**
     * @param mixed $msg
     * @param mixed ...$args
     * @return mixed|void
     */
    public function onReceive($msg, ...$args)
    {
    }

    /**
     * @return mixed|void
     */
    public function onShutDown()
    {
    }

}
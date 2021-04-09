<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| @see https://github.com/bingcool/swoolefy
+----------------------------------------------------------------------
*/

namespace Swoolefy\Core\SysCollector;

use Swoole\Process;
use Swoolefy\Core\EventController;
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Memory\AtomicManager;
use Swoolefy\Core\Process\AbstractProcess;

class SysProcess extends AbstractProcess {

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
    protected $sys_collector_config = [];

	/**
	 * run system收集信息进程
	 * @param  Process $process
	 * @return void          
	 */
	public function run() {
		$conf = Swfy::getConf();
		$this->sys_collector_config = $conf['sys_collector_conf'];
		if(is_array($this->sys_collector_config) && isset($this->sys_collector_config['type'])) {
			$type = $this->sys_collector_config['type'];
			$tick_time = isset($sys_collector_config['tick_time']) ? (float) $this->sys_collector_config['tick_time'] : self::DEFAULT_TICK_TIME;
			$atomic = AtomicManager::getInstance()->getAtomicLong('atomic_request_count');
			$max_tick_handle_coroutine_num = $this->sys_collector_config['max_tick_handle_coroutine_num'] ?? self::DEFAULT_MAX_TICK_HANDLE_COROUTINE_NUM;
            $isEnablePvCollector = BaseServer::isEnablePvCollector();
            $callback = $this->sys_collector_config['callback'] ?? null;
			\Swoole\Timer::tick($tick_time * 1000, function($timer_id) use($type, $atomic, $tick_time, $isEnablePvCollector, $max_tick_handle_coroutine_num, $callback) {
				try {
				    // 处理达到一定协程数量进程重启
				    if($this->getCurrentCoroutineLastCid() > $max_tick_handle_coroutine_num) {
                        \Swoole\Timer::clear($timer_id);
                        $this->reboot();
                        return;
                    }
                    // 统计系统信息
                    if(isset($callback) && $callback instanceof \Closure) {
                        $event = new EventController();
                        $response = $callback->call($event) ?? [];
                        if(is_array($response)) {
                            $collectionInfo = json_encode($response, JSON_UNESCAPED_UNICODE);
                        }else {
                            $collectionInfo = $response;
                        }
                    }
                    // pv原子计数器
                    $total_request_num = $isEnablePvCollector ? $atomic->get() : 0;
                    // 当前时间段内原子清空
                    $total_request_num && $atomic->sub($total_request_num);
                    // 数据聚合
                    $data = [
                        'total_request'=> $total_request_num,
                        'tick_time'=>$tick_time,
                        'from_service'=>$this->sys_collector_config['from_service'] ?? '',
                        'timestamp'=>date('Y-m-d H:i:s')
                    ];
                    $data['sys_collector_message'] = $collectionInfo;
                    switch($type) {
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
                            \Swoole\Timer::clear($timer_id);
                            break;
                    }
                }catch(\Throwable $t) {
                    BaseServer::catchException($t);
                }
			});
		}
		return;
	}

	/**
	 * sendByUdp 通过UDP发送方式
	 * @param  array  $data
     * @throws mixed
	 * @return void
	 */
	protected function sendByUdp(array $data = []) {
        $udpClient = new \Swoole\Coroutine\Client(SWOOLE_SOCK_UDP);
		$host = $this->sys_collector_config['host'];
		$port = (int)$this->sys_collector_config['port'];
		$timeout = (int)$this->sys_collector_config['timeout'] ?? 3;
		$service = $this->sys_collector_config['target_service'];
		$event = $this->sys_collector_config['event'];
		// 组合消息,udp的数据格式
		$message = $service."::".$event."::".json_encode($data, JSON_UNESCAPED_UNICODE);

		if(empty($host) || empty($port) || empty($service) || empty($event)) {
			throw new \Exception('Config about sys_collector_config of udp is wrong, host, port, service, event of params must be setting');
		}

        try{
            if(!$udpClient->isConnected()) {
                $isConnected = $udpClient->connect($host, $port, $timeout);
                if(!$isConnected) {
                    throw new \Exception("SysProcess::sendByUdp function connect udp is failed");
                }
            }
            $udpClient->send($message);
        }catch(\Throwable $throwable) {
            throw $throwable;
        }
	}

	/**
	 * publicByRedis swooleRedis的订阅发布方式
	 * @param  array $data
     * @throws mixed
	 * @return void
	 */
	protected function publishBySwooleRedis(array $data = []) {
		$host = $this->sys_collector_config['host'];
		$port = (int)$this->sys_collector_config['port'];
		$password = $this->sys_collector_config['password'];
		$timeout = isset($this->sys_collector_config['timeout']) ? (float) $this->sys_collector_config['timeout'] : 3;

		$channel = $this->sys_collector_config['channel'] ?? SWOOLEFY_SYS_COLLECTOR_CHANNEL;
		\Swoole\Coroutine::create(function() use($host, $port, $password, $timeout, $channel, $data) {
            $redisClient = new \Swoole\Coroutine\Redis();
            $redisClient->setOptions([
				'connect_timeout'=> $timeout,
				'timeout'=> -1,
				'reconnect'=>2
			]);
            $redisClient->connect($host, $port);
            $redisClient->auth($password);
			$isConnected = $redisClient->connected;
			if($isConnected && $data) {
                $message = json_encode($data, JSON_UNESCAPED_UNICODE);
                try{
                    $redisClient->publish($channel, $message);
                }catch(\Throwable $throwable) {
                    throw $throwable;
                }
			}
		});
	}

	/**
	 * publicByPhpRedis phpRedis的订阅发布方式
	 * @param  array  $data
     * @throws mixed
	 * @return void                      
	 */
	protected function publishByPhpRedis(array $data = []) {
		$host = $this->sys_collector_config['host'];
		$port = (int)$this->sys_collector_config['port'];
		$password = $this->sys_collector_config['password'];
		$timeout = isset($this->sys_collector_config['timeout']) ? (float) $this->sys_collector_config['timeout'] : 60;
		$database = $this->sys_collector_config['database'] ?? 0;
		$channel = $this->sys_collector_config['channel'] ?? SWOOLEFY_SYS_COLLECTOR_CHANNEL;

		if(!extension_loaded('redis')) {
			throw new \Exception("Because you enable sys_collector, must be install extension of phpredis", 1);
		}

		if(empty($host) || empty($port)  || empty($password)) {
            throw new \Exception('Config of sys_collector_config of phpRedis is wrong, host, port, password of params must be setted');
        }

        $redisClient = new \Redis();
        try {
            $redisClient->pconnect($host, $port, $timeout);
            $redisClient->auth($password);
            $redisClient->setOption(\Redis::OPT_READ_TIMEOUT, -1);
            $redisClient->select($database);
        }catch(\RedisException|\Exception $exception) {
            throw $exception;
        }catch (\Throwable $throwable) {
            throw $throwable;
        }

		if($data) {
			$message = json_encode($data, JSON_UNESCAPED_UNICODE);
            $redisClient->publish($channel, $message);
		}
	}

	/**
	 * writeByFile 记录到文件
	 * @param  array  $data
	 * @return void                      
	 */
	protected function writeByFile(array $data = []) {
		$file_path = $this->sys_collector_config['file_path'];
		$max_size = $this->sys_collector_config['max_size'] ?? 2 * 1024 * 1024;
		if(file_exists($file_path)) {
			$file_size = filesize($file_path);
			if($file_size > $max_size) {
				@unlink($file_path);
			}
		}
		if($data) {
			$message = json_encode($data, JSON_UNESCAPED_UNICODE);
			@file_put_contents($file_path, $message."\r\n", FILE_APPEND);
		}
	}

    /**
     * @param mixed $str
     * @param mixed ...$args
     * @return mixed|void
     */
	public function onReceive($str, ...$args) {}

    /**
     * @return mixed|void
     */
	public function onShutDown() {}

}
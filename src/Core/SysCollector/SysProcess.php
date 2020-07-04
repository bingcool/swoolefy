<?php
/**
+----------------------------------------------------------------------
| swoolefy framework bases on swoole extension development, we can use it easily!
+----------------------------------------------------------------------
| Licensed ( https://opensource.org/licenses/MIT )
+----------------------------------------------------------------------
| Author: bingcool <bingcoolhuang@gmail.com || 2437667702@qq.com>
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
     */
    const DEFAULT_TICK_TIME = 5;

    /**
     * 协程处理一定数量自重启
     */
    const DEFAULT_MAX_TICK_HANDLE_COROUTINE_NUM = 10000;

	/**
	 * run system收集信息进程
	 * @param  Process $process
	 * @return void          
	 */
	public function run() {
		// 获取协议层协议配置
		$conf = Swfy::getConf();
		$sys_collector_config = $conf['sys_collector_conf'];
		if(is_array($sys_collector_config) && isset($sys_collector_config['type'])) {
			$type = $sys_collector_config['type'];
			$tick_time = isset($sys_collector_config['tick_time']) ? (float) $sys_collector_config['tick_time'] : self::DEFAULT_TICK_TIME;
			$atomic = AtomicManager::getInstance()->getAtomicLong('atomic_request_count');
			$max_tick_handle_coroutine_num = $sys_collector_config['max_tick_handle_coroutine_num'] ?? self::DEFAULT_MAX_TICK_HANDLE_COROUTINE_NUM;
            $isEnablePvCollector = BaseServer::isEnablePvCollector();
			\Swoole\Timer::tick($tick_time * 1000, function($timer_id) use($type, $sys_collector_config, $atomic, $tick_time, $isEnablePvCollector, $max_tick_handle_coroutine_num) {
				try{
				    // 处理达到一定协程数量进程重启
				    if($this->getCurrentCoroutineLastCid() > $max_tick_handle_coroutine_num) {
                        \Swoole\Timer::clear($timer_id);
                        $this->reboot();
                    }
                    // 统计系统信息
                    if(isset($sys_collector_config['func']) && $sys_collector_config['func'] instanceof \Closure) {
                        $event = new EventController();
                        $func = $sys_collector_config['func'];
                        $res = $func->call($event);
                        if(is_array($res)) {
                            $sys_info = json_encode($res, JSON_UNESCAPED_UNICODE);
                        }else {
                            $sys_info = $res;
                        }
                    }
                    // pv原子计数器
                    $total_request_num = $isEnablePvCollector ? $atomic->get() : 0;
                    // 当前时间段内原子清空
                    $total_request_num && $atomic->sub($total_request_num);
                    $data = ['total_request'=> $total_request_num, 'tick_time'=>$tick_time,'from_service'=>$sys_collector_config['from_service'], 'timestamp'=>date('Y-m-d H:i:s')];
                    $data['sys_collector_message'] = $sys_info;
                    switch($type) {
                        case SWOOLEFY_SYS_COLLECTOR_UDP:
                            $this->sendByUdp($sys_collector_config, $data);
                            break;
                        case SWOOLEFY_SYS_COLLECTOR_SWOOLEREDIS:
                            $this->publishBySwooleRedis($sys_collector_config, $data);
                            break;
                        case SWOOLEFY_SYS_COLLECTOR_PHPREDIS:
                            $this->publishByPhpRedis($sys_collector_config, $data);
                            break;
                        case SWOOLEFY_SYS_COLLECTOR_FILE:
                            $this->writeByFile($sys_collector_config, $data);
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
	 * @param  array  $sys_collector_config
	 * @param  array  $data
     * @throws mixed
	 * @return void
	 */
	protected function sendByUdp(array $sys_collector_config, array $data = []) {
        $udpClient = new \Swoole\Coroutine\Client(SWOOLE_SOCK_UDP);
		$host = $sys_collector_config['host'];
		$port = $sys_collector_config['port'];
		$timeout = $sys_collector_config['timeout'] ?? 3;
		$service = $sys_collector_config['target_service'];
		$event = $sys_collector_config['event'];
		// 组合消息,udp的数据格式
		$message = $service."::".$event."::".json_encode($data, JSON_UNESCAPED_UNICODE);

		if(empty($host) || empty($port) || empty($service) || empty($event)) {
			throw new \Exception('sys_collector_config of udp is wrong, host, port, service, event of params must be setted');
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
	 * publicByRedis swooleredis的订阅发布方式
	 * @param  array  $sys_collector_config
	 * @param  array $data
     * @throws mixed
	 * @return void
	 */
	protected function publishBySwooleRedis(array $sys_collector_config, array $data = []) {
		$host = $sys_collector_config['host'];
		$port = (int)$sys_collector_config['port'];
		$password = $sys_collector_config['password'];
		$timeout = $sys_collector_config['timeout'] ? (float) $sys_collector_config['timeout'] : 3;

		$channel = isset($sys_collector_config['channel']) ? $sys_collector_config['channel'] : SWOOLEFY_SYS_COLLECTOR_CHANNEL;
		go(function() use($host, $port, $password, $timeout, $channel, $data) {
            $redisClient = new \Swoole\Coroutine\Redis();
            $redisClient->setOptions([
				'connect_timeout'=> $timeout,
				'timeout'=> -1,
				'reconnect'=>2
			]);
            $redisClient->connect($host, $port);
            $redisClient->auth($password);
			$isConnected = $redisClient->connected;
			if($isConnected) {
                if($data) {
                    $message = json_encode($data, JSON_UNESCAPED_UNICODE);
                    try{
                        $redisClient->publish($channel, $message);
                    }catch(\Throwable $throwable) {
                        throw $throwable;
                    }
                }
			}
		});
	}

	/**
	 * publicByPhpRedis phpRedis的订阅发布方式
	 * @param  array  $sys_collector_config
	 * @param  array  $data
     * @throws mixed
	 * @return void                      
	 */
	protected function publishByPhpRedis(array $sys_collector_config, array $data = []) {
		$host = $sys_collector_config['host'];
		$port = (int)$sys_collector_config['port'];
		$password = $sys_collector_config['password'];
		$timeout = $sys_collector_config['timeout'] ? (float) $sys_collector_config['timeout'] : 60;
		$database = isset($sys_collector_config['database']) ? $sys_collector_config['database'] : 0;
		$channel = isset($sys_collector_config['channel']) ? $sys_collector_config['channel'] : SWOOLEFY_SYS_COLLECTOR_CHANNEL;

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
        }catch(\Exception $exception) {
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
	 * @param  array  $sys_collector_config
	 * @param  array  $data                
	 * @return void                      
	 */
	protected function writeByFile(array $sys_collector_config, array $data = []) {
		$file_path = $sys_collector_config['file_path'];
		$max_size = isset($sys_collector_config['max_size']) ? $sys_collector_config['max_size'] : 2 * 1024 * 1024;
		if(file_exists($file_path)) {
			$file_size = filesize($file_path);
			if($file_size > $max_size) {
				@unlink($file_path);
				return;
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
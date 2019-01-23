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
use Swoolefy\Core\Swfy;
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Memory\AtomicManager;
use Swoolefy\Core\Process\AbstractProcess;

class SysProcess extends AbstractProcess {
	/**
	 * run system收集信息进程
	 * @param  Process $process
	 * @return void          
	 */
	public function run(Process $process) {
		// 获取协议层协议配置
		$p_config = Swfy::getConf();
		$sys_collector_config = $p_config['sys_collector_config'];
		if(is_array($sys_collector_config) && isset($sys_collector_config['type'])) {
			$type = $sys_collector_config['type'];
			$tick_time = isset($sys_collector_config['tick_time']) ? (float) $sys_collector_config['tick_time'] : 5;
			$atomic = AtomicManager::getInstance()->getAtomicLong('atomic_request_count');
            $isEnablePvCollector = BaseServer::isEnablePvCollector();
			swoole_timer_tick($tick_time * 1000, function($timer_id) use($type, $sys_collector_config, $atomic, $tick_time, $isEnablePvCollector) {
				// 统计系统信息
				if(isset($sys_collector_config['func']) && $sys_collector_config['func'] instanceof \Closure) {
					$res = call_user_func($sys_collector_config['func']);
					if(is_array($res)) {
						$sys_info = json_encode($res);
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
						swoole_timer_clear($timer_id);
						return;
					break;
				}
			});
		}
		return;
	}

	/**
	 * sendByUdp 通过UDP发送方式
	 * @param  array  $sys_collector_config
	 * @param  $array $data
	 * @return void
	 */
	protected function sendByUdp(array $sys_collector_config, array $data = []) {
		$udp_client = null;
		if(!is_object($udp_client)) {
			$udp_client = new \Swoole\Client(SWOOLE_SOCK_UDP, SWOOLE_SOCK_SYNC);
		}
		$host = $sys_collector_config['host'];
		$port = $sys_collector_config['port'];
		$service = $sys_collector_config['service'];
		$event = $sys_collector_config['event'];
		// 组合消息,udp的数据格式
		$message = $service."::".$event."::".json_encode($data);
		if($host && $port && $service && $event) {
			try{
				if(!$udp_client->isConnected()) {
					$isConnected = $udp_client->connect($host, $port);
					if(!$isConnected) {
						throw new \Exception("sendByUdp function connect udp is failed", 1);	
					}
				}
				$udp_client->send($message);
			}catch(\Exception $e) {
				throw new \Exception($e->getMessage(), 1);
			}
		}else {
			throw new \Exception("sys_collector_config of udp is wrong, host, port, service, event must be setted", 1);
		}
	}

	/**
	 * publicByRedis swooleredis的订阅发布方式
	 * @param  array  $sys_collector_config
	 * @param  $array $data
	 * @return void
	 */
	protected function publishBySwooleRedis(array $sys_collector_config, array $data = []) {
		$redis_client = null;
		$host = $sys_collector_config['host'];
		$port = (int)$sys_collector_config['port'];
		$password = $sys_collector_config['password'];
		$timeout = $sys_collector_config['timeout'] ? (float) $sys_collector_config['timeout'] : 3;
		$database = isset($sys_collector_config['timeout']) ? $sys_collector_config['timeout'] : 15;
		$channel = isset($sys_collector_config['channel']) ? $sys_collector_config['channel'] : SWOOLEFY_SYS_COLLECTOR_CHANNEL;

		if(!is_object($redis_client)) {
			$redis_client = new \swoole\redis([
		    	'timeout' => $timeout,
		    	'password' => $password,
		    	'database' => $database
	    	]);
		}

		if($data) {
			$message = json_encode($data);
			try{
				$redis_client->connect($host, $port, function($redis_client, $result) use($channel, $message) {
		    		$redis_client->publish($channel, $message, function($redis_client, $result) {
		    			$redis_client->close();
		    		});
	    		});
			}catch(\Exception $e) {
				throw new \Exception($e->getMessage(), 1);
			}
		}
	}

	/**
	 * publicByPhpRedis phpredis的订阅发布方式
	 * @param  array  $sys_collector_config
	 * @param  array  $data                
	 * @return void                      
	 */
	protected function publishByPhpRedis(array $sys_collector_config, array $data = []) {
		$redis_client = null;
		$host = $sys_collector_config['host'];
		$port = (int)$sys_collector_config['port'];
		$password = $sys_collector_config['password'];
		$timeout = $sys_collector_config['timeout'] ? (float) $sys_collector_config['timeout'] : 60;
		$database = isset($sys_collector_config['timeout']) ? $sys_collector_config['timeout'] : 15;
		$channel = isset($sys_collector_config['channel']) ? $sys_collector_config['channel'] : SWOOLEFY_SYS_COLLECTOR_CHANNEL;

		if(!extension_loaded('redis')) {
			throw new \Exception("because you enable sys_collector, must be install extension of phpredis", 1);	
		}
		if(!is_object($redis_client)) {
			$redis_client = new \Redis();
		}
		if($host && $port && $password) {
			try{
				$redis_client->pconnect($host, $port, $timeout);
				$redis_client->auth($password);
				$redis_client->setOption(\Redis::OPT_READ_TIMEOUT, -1);
				$redis_client->select($database);
			}catch(\RedisException $e) {
				throw new \Exception($e->getMessage(), 1);
			}
		}else {
			throw new \Exception('sys_collector_config of params: $host, $port, $password must be setted');
		}
		
		if($data) {
			$message = json_encode($data);
			$redis_client->publish($channel, $message);
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
		$max_size = isset($sys_collector_config['max_size']) ? $sys_collector_config['max_size'] : 50 * 1024;
		if(file_exists($file_path)) {
			$file_size = filesize($file_path);
			if($file_size > $max_size) {
				@unlink($file_path);
				return;
			}
		}
		if($data) {
			$message = json_encode($data);
			@file_put_contents($file_path, $message."\r\n", FILE_APPEND);
		}
	}

	public function onReceive($str, ...$args) {}

	public function onShutDown() {}

}
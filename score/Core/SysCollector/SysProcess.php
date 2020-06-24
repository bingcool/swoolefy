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
		$udp_client = null;
		if(!is_object($udp_client)) {
			$udp_client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_UDP);
		}
		$host = $sys_collector_config['host'];
		$port = $sys_collector_config['port'];
		$service = $sys_collector_config['target_service'];
		$event = $sys_collector_config['event'];
		// 组合消息,udp的数据格式
		$message = $service."::".$event."::".json_encode($data, JSON_UNESCAPED_UNICODE);

		if(empty($host) || empty($port) || empty($service) || empty($event)) {
			throw new \Exception('sys_collector_config of udp is wrong, host, port, service, event of params must be setted');
		}

        try{
            if(!$udp_client->isConnected()) {
                $isConnected = $udp_client->connect($host, $port);
                if(!$isConnected) {
                    throw new \Exception("SysProcess::sendByUdp function connect udp is failed");
                }
            }
            $udp_client->send($message);
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
		$redis_client = null;
		$host = $sys_collector_config['host'];
		$port = (int)$sys_collector_config['port'];
		$password = $sys_collector_config['password'];
		$timeout = $sys_collector_config['timeout'] ? (float) $sys_collector_config['timeout'] : 3;

		$channel = isset($sys_collector_config['channel']) ? $sys_collector_config['channel'] : SWOOLEFY_SYS_COLLECTOR_CHANNEL;
		go(function() use($host, $port, $password, $timeout, $channel, $data) {
			$redis_client = new \Swoole\Coroutine\Redis();
			$redis_client->setOptions([
				'connect_timeout'=> $timeout,
				'timeout'=> -1,
				'reconnect'=>2
			]);
            $redis_client->connect($host, $port);
            $redis_client->auth($password);
			$isConnected = $redis_client->connected;
			if($isConnected) {
                if($data) {
                    $message = json_encode($data, JSON_UNESCAPED_UNICODE);
                    try{
                        $redis_client->publish($channel, $message);
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
		$redis_client = null;
		$host = $sys_collector_config['host'];
		$port = (int)$sys_collector_config['port'];
		$password = $sys_collector_config['password'];
		$timeout = $sys_collector_config['timeout'] ? (float) $sys_collector_config['timeout'] : 60;
		$database = isset($sys_collector_config['database']) ? $sys_collector_config['database'] : 0;
		$channel = isset($sys_collector_config['channel']) ? $sys_collector_config['channel'] : SWOOLEFY_SYS_COLLECTOR_CHANNEL;

		if(!extension_loaded('redis')) {
			throw new \Exception("because you enable sys_collector, must be install extension of phpredis", 1);	
		}

		if(empty($host) || empty($port)  || empty($password)) {
            throw new \Exception('sys_collector_config of phpRedis is wrong, host, port, password of params must be setted');
        }

		if(!is_object($redis_client)) {
			$redis_client = new \Redis();
		}

        try {
            $redis_client->pconnect($host, $port, $timeout);
            $redis_client->auth($password);
            $redis_client->setOption(\Redis::OPT_READ_TIMEOUT, -1);
            $redis_client->select($database);
        }catch(\Exception $exception) {
            throw $exception;
        }catch (\Throwable $throwable) {
            throw $throwable;
        }

		if($data) {
			$message = json_encode($data, JSON_UNESCAPED_UNICODE);
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

	public function onReceive($str, ...$args) {}

	public function onShutDown() {}

}
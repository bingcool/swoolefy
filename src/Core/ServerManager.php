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

namespace Swoolefy\Core;

class ServerManager {

	use \Swoolefy\Core\SingletonTrait, \Swoolefy\Core\ServiceTrait;

	/**
	 * $server_port
	 * @var array
	 */
	private $server_ports = [];

	/**
	 * __construct 
	 */
	private function __construct() {}

	/**
	 * addListener
	 * @param string $host 
	 * @param int    $port 
	 * @param string $type
     * @return mixed
     * @throws \Exception
	 */
	public function addListener(string $host, $port, $type = SWOOLE_SOCK_TCP) {
		$port = (int)$port;
		$server_port = Swfy::getServer()->addListener($host, $port, $type);
		if(!is_object($server_port)) {
            throw new \Exception("ServerManager::addListener port = {$port} failed", 1);
        }
        $this->server_ports[$port] = $server_port;
        return $server_port;
	}

	/**
	 * getListener
	 * @param  int $port
	 * @return boolean
	 */
	public function getListener($port) {
		$port = (int)$port;
		if(isset($this->server_ports[$port])) {
			return $this->server_ports[$port];
		}
		return false;
	}

	/**
	 * stopWorker 
	 * @param  int|integer  $worker_id
	 * @param  bool|boolean $waitEvent
	 * @return boolean
	 */
	public function stopWorker(int $worker_id = -1, bool $waitEvent = false) {
        Swfy::getServer()->stop($worker_id);
		return true;
	}

	/**
	 * getClientInfo 
	 * @param  int $fd
	 * @param  int $extraData
	 * @param  bool $ignoreError
	 * @return mixed
	 */
	public function getClientInfo(int $fd, int $extraData, bool $ignoreError = false) {
		return Swfy::getServer()->getClientInfo($fd, $extraData);
	}

    /**
     * reload
     * @param  bool  $only_reload_taskWorker 是否重启task进程，默认false
     * @return bool
     */
	public function reload(bool $only_reload_taskWorker = false) {
        Swfy::getServer()->reload($only_reload_taskWorker);
        return true;
    }

    /**
     * shutdown
     * @return  boolean
     */
    public function shutdown() {
        Swfy::getServer()->shutdown();
        return true;
    }
	
	/**
	 * __toString
	 * @return string
	 */
	public function __toString() {
		return get_called_class();
	}

}
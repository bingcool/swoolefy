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

namespace Swoolefy\Core;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\ProcessManager;

class StartCtrl implements \Swoolefy\Core\StartInterface {
	/**
	 * init start之前初始化
	 */
	public function init() {
        static::eachStartInfo();
        // 创建一个统计system_collector的自定义定时进程
		if(BaseServer::isEnableSysCollector()) {
			ProcessManager::getInstance()->addProcess('swoolefy_system_collector', \Swoolefy\Core\SysCollector\SysProcess::class);
		}
		if(BaseServer::isEnableReload()) {
            ProcessManager::getInstance()->addProcess('swoolefy_system_reload',\Swoolefy\AutoReload\ReloadProcess::class);
        }
		static::onInit();
	}

	/**
	 * onStart 
	 * @param    $server
	 * @return          
	 */
	public function start($server) {
		static::onStart($server);
	}

	/**
	 * onManagerStart 
	 * @param    $server
	 * @return          
	 */
	public function managerStart($server) {
		static::onManagerStart($server);
	}

	/**
	 * onWorkerStart
	 * @param    $server
	 * @return   
	 */
	public function workerStart($server, $worker_id) {
		static::onWorkerStart($server, $worker_id);
	}

	/**
	 * onWorkerStop
	 * @param    $server   
	 * @param    $worker_id
	 * @return             
	 */
	public function workerStop($server, $worker_id) {
		static::onWorkerStop($server, $worker_id);
	}

	/**
	 * workerError 
	 * @param    $server    
	 * @param    $worker_id 
	 * @param    $worker_pid
	 * @param    $exit_code 
	 * @param    $signal    
	 * @return              
	 */
	public function workerError($server, $worker_id, $worker_pid, $exit_code, $signal) {
		static::onWorkerError($server, $worker_id, $worker_pid, $exit_code, $signal);
	}

	/**
	 * workerExit 1.9.17+版本支持
	 * @param    $server   
	 * @param    $worker_id
	 * @return                 
	 */
	public function workerExit($server, $worker_id) {
		static::onWorkerExit($server, $worker_id);
	}

	/**
	 * onManagerStop
	 * @param    $server
	 * @return          
	 */
	public function managerStop($server){
		static::onManagerStop($server);
	}

    /**
     * eachStartInfo
     */
	protected function eachStartInfo() {
        $protocol = BaseServer::getServiceProtocol();
        switch($protocol) {
            case SWOOLEFY_HTTP :
                $main_server = 'HttpServer';
                break;
            case SWOOLEFY_WEBSOCKET :
                $main_server = 'WebsockServer';
                break;
            case SWOOLEFY_TCP :
                $main_server = 'RpcServer';
                break;
            case SWOOLEFY_UDP :
                $main_server = 'UdpServer';
                break;
            default:
                $main_server = 'HttpServer';
        }
        $conf = Swfy::getConf();
        $daemonize = isset($conf['setting']['daemonize']) ? $conf['setting']['daemonize'] : false;
        $listen_host = isset($conf['host']) ? $conf['host'] : '127.0.0.1';
        $listen_port = isset($conf['port']) ? $conf['port'] : null;
        $worker_num  = isset($conf['setting']['worker_num']) ? $conf['setting']['worker_num'] : 1;
        $task_worker_num = isset($conf['setting']['task_worker_num']) ? $conf['setting']['task_worker_num'] : 0;
        $swoole_version = swoole_version();
        $php_version = phpversion();
        $swoolefy_version = SWOOLEFY_VERSION;
        $swoolefy_env = defined('SWOOLEFY_ENV') ? SWOOLEFY_ENV : null;
        $this->each(str_repeat('-',50),'light_green');
        $this->each("
            main server         {$main_server}
            daemonize           {$daemonize}
            listen address      {$listen_host}
            listen port         {$listen_port}
            worker num          {$worker_num}
            task worker num     {$task_worker_num}
            swoole version      {$swoole_version}
            php version         {$php_version}
            swoolefy version    {$swoolefy_version}
            swoolefy envirment  {$swoolefy_env}
            tips                执行 php swoolefy help 可以查看更多信息
",'light_green');
        $this->each(str_repeat('-',50)."\n",'light_green');

    }

    /**
     * _each
     * @param $msg
     * @param string $foreground
     * @param string $background
     */
    protected function each(string $msg, string $foreground = "red", string $background = "black") {
	    _each($msg, $foreground, $background);
    }
} 
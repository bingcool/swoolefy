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

namespace Swoolefy\Core;

trait ServiceTrait
{
    /**
     * getMasterId
     * @return int
     */
    public static function getMasterPid()
    {
        return Swfy::getServer()->master_pid;
    }

    /**
     * getManagerId
     * @return int
     */
    public static function getManagerPid()
    {
        return Swfy::getServer()->manager_pid;
    }

    /**
     * getCurrentWorkerPid
     * @return int
     */
    public static function getCurrentWorkerPid()
    {
        $workerPid = Swfy::getServer()->worker_pid;
        if ($workerPid) {
            return $workerPid;
        } else {
            // 自定义进程pid
            return posix_getpid();
        }
    }

    /**
     * getCurrentWorkerId
     * @return int
     */
    public static function getCurrentWorkerId()
    {
        //自定义进程worker_id=-1
        $workerId = Swfy::getServer()->worker_id;
        return $workerId;
    }

    /**
     * getConnections
     * @return object
     */
    public static function getConnections()
    {
        return Swfy::getServer()->connections;
    }

    /**
     * getWorkersPid
     * @return array
     */
    public static function getWorkersPid()
    {
        return BaseServer::getWorkersPid();
    }

    /**
     * getLastError
     * @return int
     */
    public static function getLastError()
    {
        return Swfy::getServer()->getLastError();
    }

    /**
     * getStats
     * @return array
     */
    public static function getSwooleStats()
    {
        return Swfy::getServer()->stats();
    }

    /**
     * getLocalIp
     * @return array
     */
    public static function getLocalIp()
    {
        return swoole_get_local_ip();
    }

    /**
     * getIncludeFiles
     * @return array|bool
     */
    public static function getInitIncludeFiles($dir = null)
    {
        $result = false;
        $workerId = self::getCurrentWorkerId();
        if (isset(Swfy::$conf['setting']['log_file'])) {
            $path = pathinfo(Swfy::$conf['setting']['log_file'], PATHINFO_DIRNAME);
            $filePath = $path . '/includes.json';
        }

        if (is_file($filePath)) {
            $includes_string = file_get_contents($filePath);
            if ($includes_string) {
                $result = [
                    'current_worker_id' => $workerId,
                    'include_init_files' => json_decode($includes_string, true),
                ];
            }
        }
        return $result;
    }

    /**
     *
     * 获取执行到目前action为止，swoole server中的该worker中内存中已经加载的class文件
     *
     * @return array
     */
    public static function getIncludeFiles()
    {
        $includeFiles = get_included_files();
        $workerId = self::getCurrentWorkerId();
        return [
            'current_worker_id' => $workerId,
            'include_memory_files' => $includeFiles,
        ];
    }

    /**
     * @param $server
     * @return bool
     */
    public static function setSwooleServer($server)
    {
        if (is_object($server)) {
            Swfy::$server = $server;
            return true;
        }
        return false;
    }

    /**
     * @param array $conf
     * @param bool
     */
    public static function setConf(array $conf)
    {
        if (is_array($conf)) {
            Swfy::$conf = $conf;
        }
        return true;
    }

    /**
     * getConf 获取协议层对应的配置
     * @return array
     */
    public static function getConf()
    {
        if (!empty(Swfy::$conf)) {
            return Swfy::$conf;
        }
        return BaseServer::getConf();
    }

    /**
     * getAppConfig 获取应用层配置
     * @return array
     */
    public static function getAppConf()
    {
        if (!empty(Swfy::$app_conf)) {
            return Swfy::$app_conf;
        }
        return BaseServer::getAppConf();
    }

    /**
     * setAppConf 设置或重新设置原有的应用层配置
     * @param array $config
     * @return bool
     */
    public static function setAppConf(array $conf = [])
    {
        Swfy::$app_conf = $conf;
        return true;
    }

    /**
     * getAppParams 应用参数
     * @param array $params
     * @return array
     */
    public function getAppParams(array $params = [])
    {
        if (isset(Swfy::$conf['app_conf']['params']) && !empty(Swfy::$conf['app_conf']['params'])) {
            return Swfy::$conf['app_conf']['params'];
        }
        return $params;
    }

    /**
     * getSwooleSetting 获取swoole的setting配置
     * @return array
     */
    public static function getSwooleSetting()
    {
        return BaseServer::getSwooleSetting();
    }

    /**
     * isWorkerProcess 进程是否是worker进程
     * @return bool
     * @throws Exception
     */
    public static function isWorkerProcess()
    {
        if (!self::isTaskProcess() && Swfy::getCurrentWorkerId() >= 0) {
            return true;
        }
        return false;
    }

    /**
     * isTaskProcess 进程是否是task进程
     * @return bool
     * @throws Exception
     */
    public static function isTaskProcess()
    {
        $server = Swfy::getServer();
        if (property_exists($server, 'taskworker')) {
            return $server->taskworker;
        }
        throw new \Exception("Not found task process,may be you use it before workerStart");
    }

    /** isUserProcess 进程是否是process进程
     * @return bool
     * @throws Exception
     */
    public static function isUserProcess()
    {
        // process的进程的worker_id等于-1
        if (!self::isTaskProcess() && Swfy::getCurrentWorkerId() < 0) {
            return true;
        }
        return false;
    }

    /** isSelfProcess 进程是否是process进程
     * @return bool
     * @throws \Exception
     */
    public static function isSelfProcess()
    {
        return self::isUserProcess();
    }

    /**
     * isHttpApp
     * @return bool
     */
    public function isHttpApp()
    {
        return BaseServer::isHttpApp();
    }

    /**
     * isRpcApp 判断当前应用是否是Tcp
     * @return bool
     */
    public function isRpcApp()
    {
        return BaseServer::isRpcApp();
    }

    /**
     * isWebsocketApp
     * @return bool
     */
    public function isWebsocketApp()
    {
        return BaseServer::isWebsocketApp();
    }

    /**
     * isUdpApp
     * @return bool
     */
    public function isUdpApp()
    {
        return BaseServer::isUdpApp();
    }

    /**
     * getServer 获取server对象
     * @return \Swoole\Server|\Swoole\Http\Server|\Swoole\WebSocket\Server
     */
    public static function getServer()
    {
        if (is_object(Swfy::$server)) {
            return Swfy::$server;
        } else {
            return BaseServer::getServer();
        }
    }

    /**
     * getRouters
     */
    public static function getRouters()
    {
        static $routerMap;
        if(!isset($routerMap)) {
            $routerFile = APP_NAME.'/Router.php';
            if(is_file($routerFile)) {
                $routerMap = include $routerFile;
            }
        }

        return $routerMap;
    }

    /**
     * @param string $uri
     * @return string
     */
    public static function getRouterMapUri(string $uri)
    {
        $routerMap = self::getRouters();
        return $routerMap[$uri] ?? $uri;
    }
}
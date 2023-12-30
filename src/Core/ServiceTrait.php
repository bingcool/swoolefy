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

use Swoolefy\Exception\SystemException;

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
     * @return \Swoole\Connection\Iterator
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
    public static function getInitIncludeFiles()
    {
        $result   = false;
        $workerId = self::getCurrentWorkerId();
        if (isset(Swfy::getConf()['setting']['log_file'])) {
            $path = pathinfo(Swfy::getConf()['setting']['log_file'], PATHINFO_DIRNAME);
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
     * getIncludeFiles
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
     * getConf
     * @return array
     */
    public static function getConf()
    {
        return BaseServer::getConf();
    }

    /**
     * getAppConf
     * @return array
     */
    public static function getAppConf()
    {
        return BaseServer::getAppConf();
    }

    /**
     * getAppParams 应用层参数设置
     * @param array $params
     * @return array
     */
    public static function getAppParams()
    {
        return Swfy::getAppConf()['app_conf']['params'] ?? [];
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
     * isWorkerProcess
     * @return bool
     * @throws SystemException
     */
    public static function isWorkerProcess()
    {
        if (!self::isTaskProcess() && Swfy::getCurrentWorkerId() >= 0) {
            return true;
        }
        return false;
    }

    /**
     * isTaskProcess
     * @return bool
     * @throws SystemException
     */
    public static function isTaskProcess()
    {
        $server = Swfy::getServer();
        if (property_exists($server, 'taskworker')) {
            return $server->taskworker;
        }
        throw new SystemException("Not found task process,may be you use it before workerStart");
    }

    /** isUserProcess
     * @return bool
     */
    public static function isUserProcess()
    {
        // process的进程的worker_id等于-1
        if (!self::isTaskProcess() && Swfy::getCurrentWorkerId() < 0) {
            return true;
        }
        return false;
    }

    /**
     * @return bool
     */
    public static function isSelfProcess()
    {
        return self::isUserProcess();
    }

    /**
     * isHttpApp application
     * @return bool
     */
    public function isHttpApp()
    {
        return BaseServer::isHttpApp();
    }

    /**
     * isRpcApp application
     * @return bool
     */
    public function isRpcApp()
    {
        return BaseServer::isRpcApp();
    }

    /**
     * isWebsocketApp application
     * @return bool
     */
    public function isWebsocketApp()
    {
        return BaseServer::isWebsocketApp();
    }

    /**
     * isUdpApp application
     * @return bool
     */
    public function isUdpApp()
    {
        return BaseServer::isUdpApp();
    }

        /**
     * getServer
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

}
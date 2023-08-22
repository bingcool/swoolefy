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

use Swoolefy\Exception\DispatchException;
use Swoolefy\Exception\SystemException;

class Swfy
{

    use \Swoolefy\Core\ServiceTrait;

    /**
     * global swoole server
     * @var \Swoole\Server
     */
    protected static $server;

    /**
     * global conf
     * @var array
     */
    protected static $conf = [];

    /**
     * application conf
     * @var array
     */
    protected static $appConf = [];

    /**
     * @var array
     */
    protected static $routes = [];

    /**
     * @var string
     */
    protected static $routeRootDir = APP_PATH.DIRECTORY_SEPARATOR.'Router';

    /**
     * @param $server
     * @return bool
     */
    public static function setSwooleServer($server)
    {
        if (is_object($server)) {
            static::$server = $server;
            return true;
        }
        return false;
    }

    /**
     * @param array $conf
     * @param bool
     */
    public static function setConf(array $conf): bool
    {
        static::$conf = array_merge(static::$conf, $conf);
        return true;
    }

    /**
     * setAppConf
     * @param array $appConf
     * @return bool
     */
    public static function setAppConf(array $appConf = []): bool
    {
        static::$appConf = array_merge(static::$appConf, $appConf);
        return true;
    }

    /**
     * @param array $routes
     * @return void
     */
    public static function mergeRoutes(array $routes)
    {
        self::$routes = array_merge(self::$routes, $routes);
    }

    /**
     * @return bool
     */
    public static function hasCacheRoutes(): bool
    {
        return !empty(self::$routes) ? true : false;
    }

    /**
     * @return array
     */
    public static function getRoutes(): array
    {
        if (empty(self::$routes)) {
            return self::scanRouteFiles(self::$routeRootDir);
        }else {
            return self::$routes;
        }
    }

    /**
     * @param string $routeRootDir
     * @return array
     */
    protected static function scanRouteFiles(string $routeRootDir)
    {
        if (!is_dir($routeRootDir)){
            return [];
        }

        $handle = opendir($routeRootDir);
        while ($file = readdir($handle)) {
            if($file == '.' || $file == '..' ){
                continue;
            }

            $filePath = $routeRootDir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($filePath)) {
                self::scanRouteFiles($filePath);
            }else {
                $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
                if (in_array($fileType, ['php'])) {
                    $routerTemp = include $filePath;
                    self::mergeRoutes($routerTemp);
                }
            }
        }

        closedir($handle);

        return self::$routes;
    }

    /**
     * @param string $uri
     * @return array
     */
    public static function getRouterMapService(string $uri)
    {
        $routerMap = self::getRoutes();
        $uri = trim($uri,DIRECTORY_SEPARATOR);
        if (isset($routerMap[$uri])) {
            $routerHandle = $routerMap[$uri];
            if(!isset($routerHandle['dispatch_route'])) {
                throw new DispatchException('Missing dispatch_route option key');
            }else {
                $dispatchRoute = $routerHandle['dispatch_route'];
            }

            $beforeHandle = [];

            foreach($routerHandle as $alias => $handle) {
                if ($alias != 'dispatch_route') {
                    $beforeHandle[] = $handle;
                    unset($routerHandle[$alias]);
                    continue;
                }
                unset($routerHandle[$alias]);
                break;
            }

            $afterHandle = array_values($routerHandle);

            return [$beforeHandle, $dispatchRoute, $afterHandle];

        }else {
            throw new DispatchException('Missing Dispatch Route Setting');
        }
    }

    /**
     * createComponent
     * @param string $com_alias_name
     * @param mixed $definition
     * @return mixed
     */
    public static function createComponent(string $com_alias_name, \Closure|array $definition = [])
    {
        return Application::getApp()->creatObject($com_alias_name, $definition);
    }

    /**
     * removeComponent
     * @param string|array $com_alias_name
     * @param bool $isAll
     * @return bool
     */
    public static function removeComponent(string|array $com_alias_name, bool $isAll = false)
    {
        return Application::getApp()->clearComponent($com_alias_name, $isAll);
    }

    /**
     * getComponent
     * @param string $com_alias_name
     * @return mixed
     */
    public static function getComponent(string $com_alias_name)
    {
        return Application::getApp()->getComponents($com_alias_name);
    }

    /**
     * @param string $action
     * @param array $args
     * @return mixed
     * @throws \Exception
     */
    public function __call(string $action, array $args = [])
    {
        // stop exec
        throw new SystemException(sprintf(
                "Calling unknown method: %s::%s",
                get_called_class(),
                $action
            )
        );
    }

    /**
     * @param string $action
     * @param array $args
     * @return mixed
     * @throws SystemException
     */
    public static function __callStatic(string $action, array $args = [])
    {
        // stop exec
        throw new SystemException(sprintf(
                "Calling unknown static method: %s::%s",
                get_called_class(),
                $action
            )
        );
    }

}
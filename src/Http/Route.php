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

namespace Swoolefy\Http;

use Swoolefy\Core\RouteMiddlewareInterface;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Http\Middleware\CorsMiddlewareInterface;

class Route
{
    /**
     * @var array
     */
    protected static $routeMap;

    /**
     * @var string
     */
    protected static $routeRootDir = APP_PATH.DIRECTORY_SEPARATOR.'Router';

    /**
     * http methods
     */
    const HTTP_METHODS = ['GET','POST','PUT','DELETE','HEAD','OPTION'];

    /**
     * __CURRENT_REQUEST_GROUP_META
     */
    const __CURRENT_REQUEST_GROUP_META = '__current_request_group_meta';

    /**
     * @param array $groupMeta
     * @param callable $fn
     * @return void
     */
    public static function group(array $groupMeta, callable $fn)
    {
        SwooleContext::set(self::__CURRENT_REQUEST_GROUP_META, $groupMeta);
        $fn($groupMeta);
        SwooleContext::set(self::__CURRENT_REQUEST_GROUP_META, []);
    }

    /**
     * @return array|mixed
     */
    public static function getGroupMeta()
    {
        $groupMeta = [];
        if (SwooleContext::has(self::__CURRENT_REQUEST_GROUP_META)) {
            $groupMeta = SwooleContext::get(self::__CURRENT_REQUEST_GROUP_META) ?? [];
        }
        return $groupMeta;
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return array
     */
    public static function get(string $uri, array $routeMeta)
    {
        $groupMeta = self::getGroupMeta();
        $routeOption = new RouteOption();
        $newUri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$newUri]['GET'] = [
            'group_meta' => $groupMeta,
            'method' => ['GET'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption,
            'enable_cors_middleware' => self::setCoresOptionMethod($groupMeta, $routeMeta, $newUri),
        ];

        return $routeOption;
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return RouteOption
     */
    public static function post(string $uri, array $routeMeta)
    {
        $groupMeta = self::getGroupMeta();
        $routeOption = new RouteOption();
        $newUri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$newUri]['POST'] = [
            'group_meta' => $groupMeta,
            'method' => ['POST'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption,
            'enable_core_middleware' => self::setCoresOptionMethod($groupMeta, $routeMeta, $newUri),
        ];

        return $routeOption;
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return RouteOption
     */
    public static function put(string $uri, array $routeMeta)
    {
        $groupMeta = self::getGroupMeta();
        $routeOption = new RouteOption();
        $newUri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$newUri]['PUT'] = [
            'group_meta' => $groupMeta,
            'method' => ['PUT'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption,
            'enable_core_middleware' => self::setCoresOptionMethod($groupMeta, $routeMeta, $newUri),
        ];
        return $routeOption;
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return RouteOption
     */
    public static function delete(string $uri, array $routeMeta)
    {
        $groupMeta = self::getGroupMeta();
        $routeOption = new RouteOption();
        $newUri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$newUri]['DELETE'] = [
            'group_meta' => $groupMeta,
            'method' => ['DELETE'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption,
            'enable_core_middleware' => self::setCoresOptionMethod($groupMeta, $routeMeta, $newUri),
        ];
        return $routeOption;
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return RouteOption
     */
    public static function head(string $uri, array $routeMeta)
    {
        $groupMeta = self::getGroupMeta();
        $routeOption = new RouteOption();
        $newUri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$newUri]['HEAD'] = [
            'group_meta' => $groupMeta,
            'method' => ['HEAD'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption
        ];
        return $routeOption;
    }

    /**
     * @param array $methods
     * @param string $uri
     * @param array $routeMeta
     * @return RouteOption
     */
    public static function match(array $methods, string $uri, array $routeMeta)
    {
        $groupMeta = self::getGroupMeta();
        $routeOption = new RouteOption();
        foreach ($methods as $method) {
            $method = strtoupper($method);
            $newUri = self::parseUri($uri, $groupMeta);
            self::$routeMap[$newUri][$method] = [
                'group_meta' => $groupMeta,
                'method' => [$method],
                'route_meta' => $routeMeta,
                'route_option' => &$routeOption,
                'enable_core_middleware' => self::setCoresOptionMethod($groupMeta, $routeMeta, $newUri),
            ];
        }
        return $routeOption;
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return RouteOption
     */
    public static function any(string $uri, array $routeMeta)
    {
        $groupMeta = self::getGroupMeta();
        $routeOption = new RouteOption();
        $newUri = self::parseUri($uri, $groupMeta);
        foreach (self::HTTP_METHODS as $method) {
            self::$routeMap[$newUri][$method] = [
                'group_meta' => $groupMeta,
                'method' => [$method],
                'route_meta' => $routeMeta,
                'route_option' => &$routeOption,
                'enable_core_middleware' => self::setCoresOptionMethod($groupMeta, $routeMeta, $newUri),
            ];
        }
        return $routeOption;
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $routeMeta
     * @return mixed
     */
    public static function addRoute(string $method, string $uri, array $routeMeta)
    {
        $method = strtolower($method);
        return self::{$method}($uri, $routeMeta);
    }

    /**
     * @param string $uri
     * @return string
     */
    protected static function parseUri(string $uri, $groupMeta): string
    {
        if (isset($groupMeta['prefix']) && !empty($groupMeta['prefix'])) {
            $uri = DIRECTORY_SEPARATOR.trim($groupMeta['prefix'],DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);
        }else {
            $uri = DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);
        }
        $uri = rtrim($uri, DIRECTORY_SEPARATOR);
        return $uri;
    }

    /**
     * @return array
     */
    public static function loadRouteFile(bool $force = false): array
    {
        if (empty(self::$routeMap) || $force) {
            self::scanRouteFiles(self::$routeRootDir);
            return self::$routeMap;
        }else {
            return self::$routeMap;
        }
    }

    /**
     * @param string $routeRootDir
     * @return void
     */
    protected static function scanRouteFiles(string $routeRootDir)
    {
        if (!is_dir($routeRootDir)) {
            return;
        }
        $handle = opendir($routeRootDir);
        while ($file = readdir($handle)) {
            if ($file == '.' || $file == '..' ) {
                continue;
            }

            $filePath = $routeRootDir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($filePath)) {
                self::scanRouteFiles($filePath);
            }else {
                $fileType = pathinfo($filePath, PATHINFO_EXTENSION);
                if (in_array($fileType, ['php'])) {
                    include $filePath;
                }
            }
        }
        closedir($handle);
    }

    /**
     * 设置OPTIONS方法
     * @param $groupMeta
     * @param $routeMeta
     * @param $newUri
     * @return bool
     */
    protected static function setCoresOptionMethod(&$groupMeta, &$routeMeta, $newUri): bool
    {
        // 分组级别已启用coresMiddleware
        $enableGroupCoresMiddleware = self::isEnableGroupCoresMiddleware($groupMeta);
        if (!$enableGroupCoresMiddleware) {
            // 分组没启用,那么检测单个路由是否启用coresMiddleware
            $enableCoresMiddleware = self::isEnableRouteCoresMiddleware($routeMeta);
            if ($enableCoresMiddleware) {
                self::$routeMap[$newUri]['OPTIONS'] = [
                    'group_meta' => [],
                    'method' => ['OPTIONS'],
                    'route_meta' => [],
                    'route_option' => null,
                ];
            }
        } else {
            $enableCoresMiddleware = $enableGroupCoresMiddleware;
        }
        return $enableCoresMiddleware;
    }

    /**
     * 分组级别cores middleware
     *
     * @param $groupMeta
     * @return array
     */
    protected static function isEnableGroupCoresMiddleware(&$groupMeta): bool
    {
        foreach ($groupMeta['middleware'] ?? [] as $handle) {
            if (is_string($handle)) {
                if (is_subclass_of($handle, CorsMiddlewareInterface::class)) {
                    return true;
                }
            }
        }
        return false;
    }


    /** 单个路由级别的cores middleware
     * @param $routeMeta
     * @return bool
     */
    protected static function isEnableRouteCoresMiddleware(&$routeMeta): bool
    {
        foreach($routeMeta as $alias => $handle) {
            if ($alias != 'dispatch_route') {
                if (is_array($handle)) {
                    foreach ($handle as $handleItem) {
                        if (is_string($handleItem)) {
                            if (is_subclass_of($handleItem, CorsMiddlewareInterface::class)) {
                                return true;
                            }
                        }
                    }
                } else {
                    if (is_string($handle)) {
                        if (is_subclass_of($handle, CorsMiddlewareInterface::class)) {
                            return true;
                        }
                    }
                }
            } else {
                // 找到 dispatch_route, 后面的都是 after middleware 这里直接return退出
                break;
            }
        }
        return false;
    }
}
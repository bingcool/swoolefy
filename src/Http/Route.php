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

use Swoolefy\Core\Coroutine\Context as SwooleContext;

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
     * @param array $groupMeta
     * @param callable $fn
     * @return void
     */
    public static function group(array $groupMeta, callable $fn)
    {
        SwooleContext::set('__current_request_group_meta', $groupMeta);
        $fn($groupMeta);
        SwooleContext::set('__current_request_group_meta', []);
    }

    /**
     * @return array|mixed
     */
    public static function getGroupMeta()
    {
        $groupMeta = [];
        if (SwooleContext::has('__current_request_group_meta')) {
            $groupMeta = SwooleContext::get('__current_request_group_meta') ?? [];
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
        $uri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$uri]['GET'] = [
            'group_meta' => $groupMeta,
            'method' => ['GET'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption
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
        $uri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$uri]['POST'] = [
            'group_meta' => $groupMeta,
            'method' => ['POST'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption
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
        $uri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$uri]['PUT'] = [
            'group_meta' => $groupMeta,
            'method' => ['PUT'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption
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
        $uri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$uri]['DELETE'] = [
            'group_meta' => $groupMeta,
            'method' => ['DELETE'],
            'route_meta' => $routeMeta,
            'route_option' => &$routeOption
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
        $uri = self::parseUri($uri, $groupMeta);
        self::$routeMap[$uri]['HEAD'] = [
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
            $uri = self::parseUri($uri, $groupMeta);
            self::$routeMap[$uri][$method] = [
                'group_meta' => $groupMeta,
                'method' => [$method],
                'route_meta' => $routeMeta,
                'route_option' => &$routeOption
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
        foreach (self::HTTP_METHODS as $method) {
            $uri = self::parseUri($uri, $groupMeta);
            self::$routeMap[$uri][$method] = [
                'group_meta' => $groupMeta,
                'method' => [$method],
                'route_meta' => $routeMeta,
                'route_option' => &$routeOption
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
}
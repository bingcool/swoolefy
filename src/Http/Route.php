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
use Swoolefy\Core\Coroutine\Context;

class Route
{
    protected static $routeMap;

    /**
     * @var string
     */
    protected static $routeRootDir = APP_PATH.DIRECTORY_SEPARATOR.'Router';

    /**
     * @param array $groupMeta
     * @param callable $fn
     * @return void
     */
    public static function group(array $groupMeta, callable $fn)
    {
        Context::set('__current_request_group_meta', $groupMeta);
        $fn($groupMeta);
        Context::set('__current_request_group_meta', []);
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return array
     */
    public static function get(string $uri, array $routeMeta)
    {
        $groupMeta = Context::get('__current_request_group_meta') ?? [];
        $uri = self::parseUri($uri, $groupMeta);
        return self::$routeMap[$uri] = [
            'group_meta' => $groupMeta,
            'method' => 'GET',
            'route_meta' => $routeMeta
        ];
    }

    /**
     * @param string $uri
     * @param array $routeMeta
     * @return array
     */
    public static function post(string $uri, array $routeMeta)
    {
        $groupMeta = Context::get('__current_request_group_meta') ?? [];
        $uri = self::parseUri($uri, $groupMeta);
        return self::$routeMap[$uri] = [
            'group_meta' => $groupMeta,
            'method' => 'POST',
            'route_meta' => $routeMeta
        ];
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
        if (isset($groupMeta['prefix'])) {
            $uri = DIRECTORY_SEPARATOR.trim($groupMeta['prefix'],DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);
        }else {
            $uri = DIRECTORY_SEPARATOR.trim($uri,DIRECTORY_SEPARATOR);
        }
        return $uri;
    }

    /**
     * @return array
     */
    public static function loadRouteFile(): array
    {
        if (empty(self::$routeMap)) {
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
            if($file == '.' || $file == '..' ){
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
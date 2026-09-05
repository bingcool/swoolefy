<?php

declare(strict_types=1);

/**
 * HTTP 忽略路由（create 时复制为 Config/ignore_route.php）
 *
 * 匹配的 URI 会在进入路由分发前直接结束响应，避免浏览器或探针请求触发 Not Found 报错。
 *
 * @see \Swoolefy\Http\IgnoreRouteConfig
 * @see \Swoolefy\Http\HttpServer
 */

return [
    /**
     * 精确匹配的 URI 路径（不含 query string）。
     * 框架内置默认忽略：/favicon.ico、/.well-known/appspecific/com.chrome.devtools.json
     */
    'routes' => [
        // '/robots.txt',
    ],

    /**
     * 前缀匹配的 URI 路径。
     * 例如 '/.well-known/' 可忽略 Chrome、Apple 等 well-known 探测。
     */
    'prefixes' => [
        // '/.well-known/',
    ],
];

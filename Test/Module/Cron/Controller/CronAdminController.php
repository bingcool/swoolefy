<?php

declare(strict_types=1);

namespace Test\Module\Cron\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoole\Http\Status as HttpStatus;
use Swoolefy\Exception\DispatchException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;

/**
 * 提供 Cron Web Admin 静态页（拆分后的多页面结构）。
 */
class CronAdminController extends BController
{
    /**
     * 入口页：/cron-admin，默认返回 index.html。
     *
     * Route: GET /cron-admin
     */
    #[ApiOperation('Cron Admin UI')]
    public function index(ResponseOutput $response): void
    {
        $this->renderStaticFile('index.html', $response);
    }

    /**
     * 多页面资源分发：/cron-admin/*.html 与 /cron-admin/assets/*。
     *
     * Route: GET /cron-admin/{path}
     */
    #[ApiOperation('Cron Admin UI Static Assets')]
    public function assets(RequestInput $request, ResponseOutput $response): void
    {
        $uri = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        $prefix = '/cron-admin/';
        if (!str_starts_with($uri, $prefix)) {
            throw new DispatchException('Invalid cron-admin asset path', HttpStatus::NOT_FOUND);
        }

        $relativePath = substr($uri, strlen($prefix));
        $this->renderStaticFile($relativePath, $response);
    }

    private function renderStaticFile(string $relativePath, ResponseOutput $response): void
    {
        $allowed = [
            'index.html',
            'task.html',
            'execution.html',
            'log.html',
            'assets/css/common.css',
            'assets/css/pages.css',
            'assets/js/common.js',
            'assets/js/app.js',
            'assets/js/dashboard.js',
            'assets/js/tasks.js',
            'assets/js/editor.js',
            'assets/js/detail.js',
            'assets/js/executions.js',
            'assets/js/nodes.js',
            'assets/js/runtime.js',
        ];

        $normalized = trim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '' || !in_array($normalized, $allowed, true)) {
            throw new DispatchException('Cron admin resource not found: ' . $relativePath, HttpStatus::NOT_FOUND);
        }

        $baseDir = dirname(__DIR__) . '/static/cron-admin/';
        $file = $baseDir . $normalized;
        $isAsset = str_starts_with($normalized, 'assets/');

        if ($isAsset) {
            $etag = '"' . sha1($normalized . '|' . (string) @filemtime($file) . '|' . (string) @filesize($file)) . '"';
            $response->withHeader('Cache-Control', 'public, max-age=600, stale-while-revalidate=60');
            $response->withHeader('ETag', $etag);
        } else {
            $response->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->withHeader('Pragma', 'no-cache');
            $response->withHeader('Expires', '0');
        }

        $response->download($file, basename($normalized), true);
    }
}

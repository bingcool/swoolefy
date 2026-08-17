<?php

declare(strict_types=1);

namespace Test\Module\Cron\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\ResponseOutput;

/**
 * 提供 Cron Web Admin 静态页（vue-element-admin 风格 SPA）。
 */
class CronAdminController extends BController
{
    /**
     * 内联输出 Test/Module/Cron/static/cron-admin.html。
     *
     * Route: GET /cron-admin
     */
    #[ApiOperation('Cron Admin UI')]
    public function index(ResponseOutput $response): void
    {
        $file = dirname(__DIR__) . '/static/cron-admin.html';
        $response->download($file, 'cron-admin.html', true);
    }
}

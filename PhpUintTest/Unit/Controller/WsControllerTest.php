<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

/**
 * @see \Test\Controller\WsController::test1
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/ws'
 * ```
 */
final class WsControllerTest extends ControllerHttpTestCase
{
    /**
     * 验证：GET /api/ws 返回 HTTP 200 且页面 HTML 含 WebSocket 脚本。
     */
    public function testWsPageContainsWebSocketScript(): void
    {
        $res = $this->getRaw('/api/ws');
        $this->assertSame(200, $res['status']);
        $this->assertStringContainsString('WebSocket', $res['body']);
    }
}

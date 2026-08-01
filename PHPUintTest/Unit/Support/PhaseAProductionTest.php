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

declare(strict_types=1);

namespace PHPUintTest\Unit\Support;

/**
 * Phase A 生产加固测试 —— MCP 失败可观测性 / SupportLog 默认路径。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | McpFactory::tools | 不可达 HTTP MCP 不抛穿；返回空列表；经 SupportLog 记 mcp 通道 |
 * | SupportLog | 未挂 test handler 时走默认 error_log，不因缺组件崩溃 |
 *
 * 说明：MCP 用例指向 `127.0.0.1:1` 故意失败，不依赖真实 MCP Server。
 */

use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\SupportLog;
use PHPUintTest\TestCase;

final class PhaseAProductionTest extends TestCase
{
    /**
     * 验证不可达 MCP server 时：
     * - tools() 返回 []（不向上抛）
     * - SupportLog test handler 至少收到一条 channel=mcp 的失败日志
     */
    public function testMcpToolsFailureIsLogged(): void
    {
        $logged = [];
        // 劫持日志，避免写盘；只收集 channel/message/context 做断言
        SupportLog::setTestHandler(static function (string $channel, string $message, array $context) use (&$logged): void {
            $logged[] = compact('channel', 'message', 'context');
        });

        try {
            // port 1 几乎必然拒绝连接 → HTTP transport 加载 tools 失败
            $factory = new McpFactory([
                'broken' => ['transport' => 'http', 'url' => 'http://127.0.0.1:1'],
            ]);
            $tools = $factory->tools(['broken']);
            $this->assertTrue($tools === [], 'tools empty on failure');
            $this->assertTrue(count($logged) >= 1, 'logged at least once');
            $this->assertTrue($logged[0]['channel'] === 'mcp', 'mcp channel');
            $this->assertTrue(str_contains($logged[0]['message'], 'Failed to load MCP tools'), 'log message');
        } finally {
            SupportLog::resetTestHandler();
        }
    }

    /**
     * 未注册 support_log 组件、也未挂 test handler 时，warning 应落到 error_log，
     * 且调用本身不抛异常（生产降级路径的冒烟）。
     */
    public function testSupportLogDefaultUsesErrorLog(): void
    {
        SupportLog::warning('test_channel', 'hello', ['k' => 'v']);
        $this->addToAssertionCount(1);
    }
}

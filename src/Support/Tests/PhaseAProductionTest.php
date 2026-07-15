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

/**
 * Phase A 生产加固测试 —— MCP 失败可观测性 / SupportLog 默认路径。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | McpFactory::tools | 不可达 HTTP MCP 不抛穿；返回空列表；经 SupportLog 记 mcp 通道 |
 * | SupportLog | 未挂 test handler 时走默认 error_log，不因缺组件崩溃 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Tests/PhaseAProductionTest.php
 * # 或（若 composer scripts 已配置）
 * composer test:phase-a
 * ```
 *
 * 说明：MCP 用例指向 `127.0.0.1:1` 故意失败，不依赖真实 MCP Server。
 */

use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\SupportLog;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 打印通过标记，便于 CLI 逐条扫结果 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// MCP：tools() 失败应被吞掉并记日志，避免拖垮 Agent 启动
// ---------------------------------------------------------------------------

/**
 * 验证不可达 MCP server 时：
 * - tools() 返回 []（不向上抛）
 * - SupportLog test handler 至少收到一条 channel=mcp 的失败日志
 */
function testMcpToolsFailureIsLogged(): void
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
        assertTrue($tools === [], 'tools empty on failure');
        assertTrue(count($logged) >= 1, 'logged at least once');
        assertTrue($logged[0]['channel'] === 'mcp', 'mcp channel');
        assertTrue(str_contains($logged[0]['message'], 'Failed to load MCP tools'), 'log message');
    } finally {
        SupportLog::resetTestHandler();
    }

    pass('mcp tools failure is logged');
}

// ---------------------------------------------------------------------------
// SupportLog：默认路径（无组件 / 无 test handler）仍可调用
// ---------------------------------------------------------------------------

/**
 * 未注册 support_log 组件、也未挂 test handler 时，warning 应落到 error_log，
 * 且调用本身不抛异常（生产降级路径的冒烟）。
 */
function testSupportLogDefaultUsesErrorLog(): void
{
    SupportLog::warning('test_channel', 'hello', ['k' => 'v']);
    pass('support log default path');
}

$tests = [
    'mcp tools failure is logged' => 'testMcpToolsFailureIsLogged',
    'support log default' => 'testSupportLogDefaultUsesErrorLog',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} Phase A production tests passed.\n";

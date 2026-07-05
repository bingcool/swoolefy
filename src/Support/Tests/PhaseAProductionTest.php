<?php

declare(strict_types=1);

/**
 * Phase A 生产加固测试 —— MCP / ChatHistory 失败日志等。
 *
 * 运行：php src/Support/Tests/PhaseAProductionTest.php
 */

use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\SupportLog;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

function testMcpToolsFailureIsLogged(): void
{
    $logged = [];
    SupportLog::setTestHandler(static function (string $channel, string $message, array $context) use (&$logged): void {
        $logged[] = compact('channel', 'message', 'context');
    });

    try {
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

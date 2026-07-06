<?php

declare(strict_types=1);

/**
 * MCP 模块回归测试。
 *
 * 覆盖：McpFactory 静态/仓储解析、disabled stub、listServers 脱敏、
 * InMemory 仓储租户隔离、McpProcessRunner 限流、Neuron MCP 传输类型推断。
 *
 * 运行：php src/Support/Mcp/Tests/McpModuleTest.php
 * 或：composer test:mcp
 */

use Swoolefy\Support\Mcp\InMemoryMcpServerConfigRepository;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpProcessLimitException;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Mcp\McpServerConfig;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function testServerNamesMergeStaticAndRepository(): void
{
    $repo = new InMemoryMcpServerConfigRepository();
    $repo->upsert(new McpServerConfig(
        id: 'tenant-docs',
        tenantId: 't1',
        config: ['transport' => 'http', 'url' => 'http://example.test'],
    ));

    $factory = new McpFactory(
        servers: ['static-docs' => ['transport' => 'http', 'url' => 'http://static.test']],
        repository: $repo,
    );

    $names = $factory->serverNames('t1');
    assertTrue(in_array('static-docs', $names, true), 'static server listed');
    assertTrue(in_array('tenant-docs', $names, true), 'tenant server listed');
}

function testMissingServerReturnsDisabledConnector(): void
{
    $factory = new McpFactory();
    $connector = $factory->connector('not-configured');
    assertTrue($connector !== null, 'disabled stub connector');
}

function testToolsForMissingServerReturnsEmpty(): void
{
    $factory = new McpFactory();
    $tools = $factory->tools(['ghost']);
    assertTrue($tools === [], 'missing server tools empty');
}

function testListServersPublicView(): void
{
    $factory = new McpFactory([
        'docs' => ['transport' => 'http', 'url' => 'http://example.test', 'token' => 'secret'],
    ]);

    $list = $factory->listServers();
    assertTrue(count($list) === 1, 'one server');
    assertTrue($list[0]['id'] === 'docs', 'id');
    assertTrue($list[0]['enabled'] === true, 'enabled');
    assertTrue($list[0]['transport'] === 'http', 'transport');
}

function testRepositoryMasksSecretsInPublicArray(): void
{
    $config = new McpServerConfig(
        id: 'secure',
        tenantId: null,
        config: [
            'transport' => 'http',
            'token' => 'super-secret',
            'url' => 'http://x',
            'headers' => [
                'Authorization' => 'Bearer remote-secret',
                'x-api-key' => 'header-secret',
                'x-custom-header' => 'kept',
            ],
        ],
        description: 'prod',
    );
    $public = $config->toPublicArray();
    assertTrue(($public['config']['token'] ?? '') === '***', 'token masked');
    assertTrue(($public['config']['url'] ?? '') === 'http://x', 'url kept');
    assertTrue(($public['config']['headers']['Authorization'] ?? '') === '***', 'authorization header masked');
    assertTrue(($public['config']['headers']['x-api-key'] ?? '') === '***', 'api key header masked');
    assertTrue(($public['config']['headers']['x-custom-header'] ?? '') === 'kept', 'non-sensitive header kept');
}

function testRepositoryTenantIsolation(): void
{
    $repo = new InMemoryMcpServerConfigRepository();
    $repo->upsert(new McpServerConfig('docs', 't1', ['transport' => 'http']));
    $repo->upsert(new McpServerConfig('docs', 't2', ['transport' => 'stdio', 'command' => 'echo']));

    $t1 = $repo->find('docs', 't1');
    $t2 = $repo->find('docs', 't2');
    assertTrue($t1 !== null && ($t1->config['transport'] ?? '') === 'http', 't1 config');
    assertTrue($t2 !== null && ($t2->config['transport'] ?? '') === 'stdio', 't2 config');

    $listT1 = $repo->list('t1');
    assertTrue(count($listT1) === 1, 'list t1 only own');
}

function testProcessRunnerLimit(): void
{
    McpProcessRunner::reset();
    $runner = new McpProcessRunner(2);
    $runner->acquire('s1');
    $runner->acquire('s2');

    try {
        $runner->acquire('s3');
        assertTrue(false, 'should hit limit');
    } catch (McpProcessLimitException) {
        assertTrue(true, 'limit ok');
    } finally {
        $runner->release();
        $runner->release();
    }
}

function testIsLocalStdioConfig(): void
{
    assertTrue(McpProcessRunner::isLocalStdioConfig([
        'transport' => 'stdio',
        'command' => 'npx',
    ]), 'stdio is local');
    assertTrue(!McpProcessRunner::isLocalStdioConfig([
        'transport' => 'http',
        'url' => 'http://x',
    ]), 'http is not local');
}

function testDetectTransportCoversNeuronMcpModes(): void
{
    assertTrue(McpServerConfig::detectTransport([
        'url' => 'https://mcp.example.com',
        'token' => 'secret',
    ]) === 'http', 'url maps to http');
    assertTrue(McpServerConfig::detectTransport([
        'url' => 'https://mcp.example.com',
        'async' => true,
    ]) === 'sse', 'url + async maps to sse');
    assertTrue(McpServerConfig::detectTransport([
        'command' => 'php',
        'args' => ['/tmp/mcp_server.php'],
    ]) === 'stdio', 'command maps to stdio');
    assertTrue(McpServerConfig::detectTransport([
        'transport' => 'sse',
    ]) === 'sse', 'explicit sse transport kept');
    assertTrue(McpServerConfig::detectTransport([
        'transport' => 'disabled',
    ]) === 'disabled', 'disabled transport kept');
}

function testToolFilterNormalization(): void
{
    $factory = new McpFactory();
    $method = new ReflectionMethod(McpFactory::class, 'normalizeToolFilter');
    $method->setAccessible(true);

    $normalized = $method->invoke($factory, ['search', '', 'read', 'search', 123, null]);

    assertTrue($normalized === ['search', 'read'], 'tool filter should keep unique non-empty strings');
}

$tests = [
    'server names merge' => 'testServerNamesMergeStaticAndRepository',
    'disabled connector stub' => 'testMissingServerReturnsDisabledConnector',
    'tools empty for missing' => 'testToolsForMissingServerReturnsEmpty',
    'list servers public' => 'testListServersPublicView',
    'public array masks secrets' => 'testRepositoryMasksSecretsInPublicArray',
    'tenant isolation' => 'testRepositoryTenantIsolation',
    'process runner limit' => 'testProcessRunnerLimit',
    'local stdio detect' => 'testIsLocalStdioConfig',
    'detect transport modes' => 'testDetectTransportCoversNeuronMcpModes',
    'tool filter normalization' => 'testToolFilterNormalization',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} MCP module tests passed.\n";

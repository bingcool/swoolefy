<?php

declare(strict_types=1);

/**
 * MCP 模块回归测试。
 *
 * 覆盖：McpFactory 静态/仓储解析、disabled stub、listServers 脱敏、
 * DbMcpServerConfigRepository 全局配置与软删、McpPdoResolver / McpComponentFactory、
 * InMemory 仓储 upsert/find、
 * McpProcessRunner 限流、Neuron MCP 传输类型推断。
 *
 * 运行：php src/Support/Mcp/Tests/McpModuleTest.php
 * 或：composer test:mcp
 */

use Swoolefy\Core\Application;
use Swoolefy\Support\Mcp\DbMcpServerConfigRepository;
use Swoolefy\Support\Mcp\InMemoryMcpServerConfigRepository;
use Swoolefy\Support\Mcp\McpComponentFactory;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpPdoResolver;
use Swoolefy\Support\Mcp\McpProcessLimitException;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Mcp\McpServerConfig;
use Swoolefy\Support\Neuron\NeuronAiConfig;

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
        server_id: 'repo-docs',
        config: ['transport' => 'http', 'url' => 'http://example.test'],
    ));

    $factory = new McpFactory(
        servers: ['static-docs' => ['transport' => 'http', 'url' => 'http://static.test']],
        repository: $repo,
    );

    $names = $factory->serverNames();
    assertTrue(in_array('static-docs', $names, true), 'static server listed');
    assertTrue(in_array('repo-docs', $names, true), 'repository server listed');
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
    assertTrue($list[0]['server_id'] === 'docs', 'server_id');
    assertTrue($list[0]['enabled'] === true, 'enabled');
    assertTrue($list[0]['transport'] === 'http', 'transport');
    assertTrue(!array_key_exists('tenantId', $list[0]), 'no tenantId in public view');
    assertTrue(!array_key_exists('id', $list[0]), 'no legacy id in public view');
}

function testRepositoryMasksSecretsInPublicArray(): void
{
    $config = new McpServerConfig(
        server_id: 'secure',
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
    assertTrue(!array_key_exists('tenantId', $public), 'no tenantId field');
    assertTrue(!array_key_exists('id', $public), 'no legacy id field');
}

function testInMemoryRepositoryUpsertFind(): void
{
    $repo = new InMemoryMcpServerConfigRepository();
    $repo->upsert(new McpServerConfig(
        server_id: 'docs',
        config: ['transport' => 'http', 'url' => 'http://first.test'],
    ));
    $repo->upsert(new McpServerConfig(
        server_id: 'docs',
        config: ['transport' => 'http', 'url' => 'http://second.test'],
    ));

    $found = $repo->find('docs');
    assertTrue($found !== null, 'config found');
    assertTrue(($found->config['url'] ?? '') === 'http://second.test', 'same server_id is overwritten');
    assertTrue(count($repo->list()) === 1, 'one server id in list');
}

function testDbMcpRepositoryGlobalConfig(): void
{
    $pdo = new PDO('sqlite::memory:');
    $repo = new DbMcpServerConfigRepository($pdo, autoMigrate: true);
    $repo->list(); // trigger autoMigrate

    $stmt = $pdo->prepare(
        "INSERT INTO mcp_server_configs (server_id, config_json, enabled, description)
         VALUES ('global_docs', :json, 1, 'prod docs')",
    );
    $stmt->execute([':json' => json_encode([
        'transport' => 'http',
        'url' => 'https://mcp.example.com',
    ])]);

    $found = $repo->find('global_docs');
    assertTrue($found !== null, 'global config found');
    assertTrue($found->server_id === 'global_docs', 'server_id');
    assertTrue(($found->config['transport'] ?? '') === 'http', 'transport');
    assertTrue($found->description === 'prod docs', 'description');

    $list = $repo->list();
    assertTrue(count($list) === 1, 'one enabled global row');
    assertTrue($list[0]->server_id === 'global_docs', 'listed server_id');
}

function testDbMcpRepositorySkipsSoftDeletedRows(): void
{
    $pdo = new PDO('sqlite::memory:');
    $repo = new DbMcpServerConfigRepository($pdo, autoMigrate: true);
    $repo->list();

    $stmt = $pdo->prepare(
        "INSERT INTO mcp_server_configs (server_id, config_json, enabled, deleted_at)
         VALUES ('active_srv', :active_json, 1, NULL),
                ('removed_srv', :removed_json, 1, '2026-01-01 00:00:00')",
    );
    $stmt->execute([
        ':active_json' => json_encode(['transport' => 'http', 'url' => 'http://active.test']),
        ':removed_json' => json_encode(['transport' => 'http', 'url' => 'http://removed.test']),
    ]);

    assertTrue($repo->find('active_srv') !== null, 'active row visible');
    assertTrue($repo->find('removed_srv') === null, 'soft-deleted row hidden');

    $ids = array_map(static fn (McpServerConfig $c): string => $c->server_id, $repo->list());
    assertTrue(in_array('active_srv', $ids, true), 'active in list');
    assertTrue(!in_array('removed_srv', $ids, true), 'soft-deleted not in list');
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

function testMcpDbComponentFromConfig(): void
{
    $config = NeuronAiConfig::fromArray([
        'mcp' => ['db_component' => 'pg'],
    ]);
    assertTrue($config->mcpDbComponent() === 'pg', 'db_component from config');

    $default = NeuronAiConfig::fromArray(['mcp' => []]);
    assertTrue($default->mcpDbComponent() === 'db', 'db_component default');
}

function testMcpPdoResolverRequiresApplication(): void
{
    try {
        McpPdoResolver::resolve('db');
        assertTrue(false, 'should require application');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'Application context'), 'application required message');
    }
}

function testMcpPdoResolverFromDbComponent(): void
{
    $pdo = new PDO('sqlite::memory:');
    $container = new class ($pdo) {
        public function __construct(private readonly PDO $pdo)
        {
        }

        public function getObject(): PDO
        {
            return $this->pdo;
        }
    };

    $resolved = McpPdoResolver::resolveFromContainer($container, 'db');
    assertTrue($resolved instanceof PDO, 'pdo resolved from db component');
    assertTrue($resolved->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'errmode exception');
}

function testMcpComponentFactoryDbRepository(): void
{
    $pdo = new PDO('sqlite::memory:');
    $config = NeuronAiConfig::fromArray([
        'mcp' => [
            'db_component' => 'db',
            'max_local_processes' => 2,
        ],
        'security' => [
            'outbound_url_allowlist' => [],
            'allow_private_networks' => true,
        ],
    ]);

    $repo = new DbMcpServerConfigRepository($pdo, autoMigrate: true);
    $repo->list();

    $stmt = $pdo->prepare(
        "INSERT INTO mcp_server_configs (server_id, config_json, enabled, description)
         VALUES ('factory_docs', :json, 1, 'from factory')",
    );
    $stmt->execute([':json' => json_encode(['transport' => 'disabled', 'name' => 'factory_docs'])]);

    $found = $repo->find('factory_docs');
    assertTrue($found !== null, 'factory repo finds row');
    assertTrue($found->server_id === 'factory_docs', 'server_id from factory repo');

    $factory = McpComponentFactory::factory([], $config, $repo);
    assertTrue(in_array('factory_docs', $factory->serverNames(), true), 'factory lists db server');

    $injectedRepo = McpComponentFactory::dbRepository($config, $pdo);
    assertTrue($injectedRepo instanceof DbMcpServerConfigRepository, 'dbRepository accepts injected pdo');
}

$tests = [
    'server names merge' => 'testServerNamesMergeStaticAndRepository',
    'disabled connector stub' => 'testMissingServerReturnsDisabledConnector',
    'tools empty for missing' => 'testToolsForMissingServerReturnsEmpty',
    'list servers public' => 'testListServersPublicView',
    'public array masks secrets' => 'testRepositoryMasksSecretsInPublicArray',
    'inmemory upsert find' => 'testInMemoryRepositoryUpsertFind',
    'db global config' => 'testDbMcpRepositoryGlobalConfig',
    'db skips soft deleted' => 'testDbMcpRepositorySkipsSoftDeletedRows',
    'mcp db component config' => 'testMcpDbComponentFromConfig',
    'mcp pdo resolver requires app' => 'testMcpPdoResolverRequiresApplication',
    'mcp pdo resolver from component' => 'testMcpPdoResolverFromDbComponent',
    'mcp component factory db repo' => 'testMcpComponentFactoryDbRepository',
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

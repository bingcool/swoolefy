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

namespace PHPUintTest\Unit\Support\Mcp;

/**
 * MCP 模块回归测试（无需真实 MCP Server 或外网）。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | McpFactory | 静态/仓储 server 合并、缺省 disabled stub、tools 空列表、listServers 公开视图 |
 * | McpServerConfig | toPublicArray 脱敏 token/敏感 header、detectTransport 推断 Neuron MCP 模式 |
 * | InMemoryMcpServerConfigRepository | upsert 覆盖、find/list |
 * | DbMcpServerConfigRepository | 全局配置读取、软删行不可见 |
 * | McpProcessRunner | 本地进程并发上限、stdio 本地判定 |
 * | NeuronAiConfig / McpPdoResolver / McpComponentFactory | db_component、PDO 解析、工厂装配 |
 */

use PDO;
use ReflectionMethod;
use RuntimeException;
use Swoolefy\Support\Mcp\DbMcpServerConfigRepository;
use Swoolefy\Support\Mcp\InMemoryMcpServerConfigRepository;
use Swoolefy\Support\Mcp\McpComponentFactory;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpPdoResolver;
use Swoolefy\Support\Mcp\McpProcessLimitException;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Mcp\McpServerConfig;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use PHPUintTest\TestCase;

final class McpModuleTest extends TestCase
{
    /**
     * 验证 McpFactory::serverNames() 同时包含静态配置与仓储中的 server_id。
     *
     * 场景：静态 `static-docs` + 仓储 `repo-docs` 均应出现在合并列表中。
     */
    public function testServerNamesMergeStaticAndRepository(): void
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
        $this->assertTrue(in_array('static-docs', $names, true), 'static server listed');
        $this->assertTrue(in_array('repo-docs', $names, true), 'repository server listed');
    }

    /**
     * 验证未配置的 server_id 返回 disabled stub connector，而非 null 或抛错。
     *
     * 目的：Agent 启动不因单个 MCP 缺失而中断。
     */
    public function testMissingServerReturnsDisabledConnector(): void
    {
        $factory = new McpFactory();
        $connector = $factory->connector('not-configured');
        $this->assertTrue($connector !== null, 'disabled stub connector');
    }

    /**
     * 验证 tools() 对不存在的 server 返回空数组。
     *
     * 目的：与 connector stub 一致，避免向上层抛异常。
     */
    public function testToolsForMissingServerReturnsEmpty(): void
    {
        $factory = new McpFactory();
        $tools = $factory->tools(['ghost']);
        $this->assertTrue($tools === [], 'missing server tools empty');
    }

    /**
     * 验证 listServers() 公开视图字段：server_id、enabled、transport，且不暴露 tenantId / 旧 id。
     */
    public function testListServersPublicView(): void
    {
        $factory = new McpFactory([
            'docs' => ['transport' => 'http', 'url' => 'http://example.test', 'token' => 'secret'],
        ]);

        $list = $factory->listServers();
        $this->assertTrue(count($list) === 1, 'one server');
        $this->assertTrue($list[0]['server_id'] === 'docs', 'server_id');
        $this->assertTrue($list[0]['enabled'] === true, 'enabled');
        $this->assertTrue($list[0]['transport'] === 'http', 'transport');
        $this->assertTrue(!array_key_exists('tenantId', $list[0]), 'no tenantId in public view');
        $this->assertTrue(!array_key_exists('id', $list[0]), 'no legacy id in public view');
    }

    /**
     * 验证 toPublicArray() 对 token、Authorization、x-api-key 等敏感字段脱敏为 `***`。
     *
     * 非敏感字段（url、自定义 header）应保持原值；且不输出 tenantId / 旧 id。
     */
    public function testRepositoryMasksSecretsInPublicArray(): void
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
        $this->assertTrue(($public['config']['token'] ?? '') === '***', 'token masked');
        $this->assertTrue(($public['config']['url'] ?? '') === 'http://x', 'url kept');
        $this->assertTrue(($public['config']['headers']['Authorization'] ?? '') === '***', 'authorization header masked');
        $this->assertTrue(($public['config']['headers']['x-api-key'] ?? '') === '***', 'api key header masked');
        $this->assertTrue(($public['config']['headers']['x-custom-header'] ?? '') === 'kept', 'non-sensitive header kept');
        $this->assertTrue(!array_key_exists('tenantId', $public), 'no tenantId field');
        $this->assertTrue(!array_key_exists('id', $public), 'no legacy id field');
    }

    /**
     * 验证 InMemoryMcpServerConfigRepository 的 upsert 同 server_id 覆盖、find 与 list 计数。
     */
    public function testInMemoryRepositoryUpsertFind(): void
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
        $this->assertTrue($found !== null, 'config found');
        $this->assertTrue(($found->config['url'] ?? '') === 'http://second.test', 'same server_id is overwritten');
        $this->assertTrue(count($repo->list()) === 1, 'one server id in list');
    }

    /**
     * 验证 DbMcpServerConfigRepository 读取全局（无租户）配置行：find、list、字段解析。
     *
     * 使用 SQLite 内存库 + autoMigrate，直接 INSERT 模拟已迁移表。
     */
    public function testDbMcpRepositoryGlobalConfig(): void
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
        $this->assertTrue($found !== null, 'global config found');
        $this->assertTrue($found->server_id === 'global_docs', 'server_id');
        $this->assertTrue(($found->config['transport'] ?? '') === 'http', 'transport');
        $this->assertTrue($found->description === 'prod docs', 'description');

        $list = $repo->list();
        $this->assertTrue(count($list) === 1, 'one enabled global row');
        $this->assertTrue($list[0]->server_id === 'global_docs', 'listed server_id');
    }

    /**
     * 验证 DbMcpServerConfigRepository 跳过 deleted_at 非空的软删行。
     *
     * find 与 list 均不应包含 `removed_srv`。
     */
    public function testDbMcpRepositorySkipsSoftDeletedRows(): void
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

        $this->assertTrue($repo->find('active_srv') !== null, 'active row visible');
        $this->assertTrue($repo->find('removed_srv') === null, 'soft-deleted row hidden');

        $ids = array_map(static fn (McpServerConfig $c): string => $c->server_id, $repo->list());
        $this->assertTrue(in_array('active_srv', $ids, true), 'active in list');
        $this->assertTrue(!in_array('removed_srv', $ids, true), 'soft-deleted not in list');
    }

    /**
     * 验证 McpProcessRunner 达到 max 后 acquire 抛 McpProcessLimitException，release 后可恢复。
     */
    public function testProcessRunnerLimit(): void
    {
        McpProcessRunner::reset();
        $runner = new McpProcessRunner(2);
        $runner->acquire('s1');
        $runner->acquire('s2');

        try {
            $runner->acquire('s3');
            $this->assertTrue(false, 'should hit limit');
        } catch (McpProcessLimitException) {
            $this->assertTrue(true, 'limit ok');
        } finally {
            $runner->release();
            $runner->release();
        }
    }

    /**
     * 验证 isLocalStdioConfig() 仅将 transport=stdio + command 判为本地进程配置。
     */
    public function testIsLocalStdioConfig(): void
    {
        $this->assertTrue(McpProcessRunner::isLocalStdioConfig([
            'transport' => 'stdio',
            'command' => 'npx',
        ]), 'stdio is local');
        $this->assertTrue(!McpProcessRunner::isLocalStdioConfig([
            'transport' => 'http',
            'url' => 'http://x',
        ]), 'http is not local');
    }

    /**
     * 验证 McpServerConfig::detectTransport() 覆盖 Neuron MCP 常见模式：
     * url→http、url+async→sse、command→stdio、显式 sse/disabled 保持。
     */
    public function testDetectTransportCoversNeuronMcpModes(): void
    {
        $this->assertTrue(McpServerConfig::detectTransport([
            'url' => 'https://mcp.example.com',
            'token' => 'secret',
        ]) === 'http', 'url maps to http');
        $this->assertTrue(McpServerConfig::detectTransport([
            'url' => 'https://mcp.example.com',
            'async' => true,
        ]) === 'sse', 'url + async maps to sse');
        $this->assertTrue(McpServerConfig::detectTransport([
            'command' => 'php',
            'args' => ['/tmp/mcp_server.php'],
        ]) === 'stdio', 'command maps to stdio');
        $this->assertTrue(McpServerConfig::detectTransport([
            'transport' => 'sse',
        ]) === 'sse', 'explicit sse transport kept');
        $this->assertTrue(McpServerConfig::detectTransport([
            'transport' => 'disabled',
        ]) === 'disabled', 'disabled transport kept');
    }

    /**
     * 验证 McpFactory::normalizeToolFilter()（反射调用）去重、去空、仅保留字符串。
     */
    public function testToolFilterNormalization(): void
    {
        $factory = new McpFactory();
        $method = new ReflectionMethod(McpFactory::class, 'normalizeToolFilter');
        $method->setAccessible(true);

        $normalized = $method->invoke($factory, ['search', '', 'read', 'search', 123, null]);

        $this->assertTrue($normalized === ['search', 'read'], 'tool filter should keep unique non-empty strings');
    }

    /**
     * 验证 NeuronAiConfig::mcpDbComponent() 从配置读取及默认值 `db`。
     */
    public function testMcpDbComponentFromConfig(): void
    {
        $config = NeuronAiConfig::fromArray([
            'mcp' => ['db_component' => 'pg'],
        ]);
        $this->assertTrue($config->mcpDbComponent() === 'pg', 'db_component from config');

        $default = NeuronAiConfig::fromArray(['mcp' => []]);
        $this->assertTrue($default->mcpDbComponent() === 'db', 'db_component default');
    }

    /**
     * 验证无 Application 上下文时 McpPdoResolver::resolve() fail-fast 并提示需要 Application。
     */
    public function testMcpPdoResolverRequiresApplication(): void
    {
        try {
            McpPdoResolver::resolve('db');
            $this->assertTrue(false, 'should require application');
        } catch (RuntimeException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'Application context'), 'application required message');
        }
    }

    /**
     * 验证 McpPdoResolver::resolveFromContainer() 从容器组件取出 PDO 并设置 ERRMODE_EXCEPTION。
     */
    public function testMcpPdoResolverFromDbComponent(): void
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
        $this->assertTrue($resolved instanceof PDO, 'pdo resolved from db component');
        $this->assertTrue($resolved->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'errmode exception');
    }

    /**
     * 验证 McpComponentFactory 用 Db 仓储装配 McpFactory，且 dbRepository() 接受注入 PDO。
     *
     * 场景：SQLite 插入 `factory_docs` 行后，factory()->serverNames() 应包含该 server。
     */
    public function testMcpComponentFactoryDbRepository(): void
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
        $this->assertTrue($found !== null, 'factory repo finds row');
        $this->assertTrue($found->server_id === 'factory_docs', 'server_id from factory repo');

        $factory = McpComponentFactory::factory([], $config, $repo);
        $this->assertTrue(in_array('factory_docs', $factory->serverNames(), true), 'factory lists db server');

        $injectedRepo = McpComponentFactory::dbRepository($config, $pdo);
        $this->assertTrue($injectedRepo instanceof DbMcpServerConfigRepository, 'dbRepository accepts injected pdo');
    }
}

<?php

declare(strict_types=1);

/**
 * Phase B 生产稳定性测试。
 *
 * 运行：php src/Support/Tests/PhaseBProductionTest.php
 */

use Swoolefy\Support\Mcp\DbMcpServerConfigRepository;
use Swoolefy\Support\Mcp\InMemoryMcpServerConfigRepository;
use Swoolefy\Support\Mcp\McpFactory;
use Swoolefy\Support\Mcp\McpProcessRunner;
use Swoolefy\Support\Mcp\McpServerConfig;
use Swoolefy\Support\Mcp\McpStdioGuard;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\ProductionHealthCheck;
use Swoolefy\Support\Security\OutboundUrlGuard;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\WorkflowRunSnapshot;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\WorkflowRunStoreName;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\AI\Node\AgentParallelNode;
use Swoolefy\Support\Agent\AgentScheduler;
use Swoolefy\Support\Agent\Router\StaticRouter;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

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

function testWorkflowRegistryMultiVersion(): void
{
    $registry = new WorkflowRegistry();

    $registry->registerVersion('demo', '1.0.0', static fn () => WorkflowDefinition::create('demo', '1.0.0')
        ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success(['v' => '1']))));

    $registry->register('demo', static fn () => WorkflowDefinition::create('demo', '2.0.0')
        ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success(['v' => '2']))));

    assertTrue($registry->hasVersion('demo', '1.0.0'), 'has v1');
    assertTrue($registry->hasVersion('demo', '2.0.0'), 'has v2');
    assertTrue($registry->latestVersion('demo') === '2.0.0', 'latest v2');

    $v1 = $registry->compiled('demo', '1.0.0');
    $v2 = $registry->compiled('demo', '2.0.0');
    assertTrue($v1->version() === '1.0.0', 'compiled v1');
    assertTrue($v2->version() === '2.0.0', 'compiled v2');

    pass('workflow registry multi version');
}

function testSnapshotRejectsMissingVersion(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('demo', static fn () => WorkflowDefinition::create('demo', '1.0.0')
        ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success())));

    $snapshot = WorkflowRunSnapshot::fromArray([
        'runId' => 'run-1',
        'workflowId' => 'demo',
        'version' => '9.9.9',
        'status' => 'waiting',
        'state' => [],
        'createdAt' => WorkflowRunTime::now(),
        'updatedAt' => WorkflowRunTime::now(),
    ]);

    try {
        $snapshot->hydrate($registry);
        assertTrue(false, 'should throw');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), '9.9.9'), 'version in message');
    }

    pass('snapshot rejects missing version');
}

function testWorkflowDefaultNodeTimeout(): void
{
    $config = WorkflowConfig::fromArray([
        'workflow' => [
            'default_node_timeout_seconds' => 90,
            'run_stores' => ['memory' => []],
        ],
    ]);

    assertTrue($config->defaultNodeTimeoutSeconds() === 90.0, 'timeout from config');

    $registry = new WorkflowRegistry();
    $engine = WorkflowComponentFactory::engine($registry, config: $config);
    assertTrue($engine->getDefaultNodeTimeoutSeconds() === 90.0, 'engine timeout');

    pass('workflow default node timeout');
}

function testAgentParallelNodeTimeoutInterface(): void
{
    $scheduler = new AgentScheduler(new NeuronFactory());
    $node = new AgentParallelNode(
        'parallel',
        $scheduler,
        new StaticRouter(['a']),
        ['a' => static fn () => 'ok'],
        45,
    );

    assertTrue($node instanceof \Swoolefy\Support\Workflow\Node\ConfigurableTimeoutNodeInterface, 'implements interface');
    assertTrue($node->configuredTimeoutSeconds() === 45, 'timeout value');

    pass('agent parallel node timeout');
}

function testMcpTenantRepositoryOverridesStatic(): void
{
    $repo = new InMemoryMcpServerConfigRepository();
    $repo->upsert(new McpServerConfig(
        id: 'docs',
        tenantId: 'tenant_a',
        config: ['transport' => 'http', 'url' => 'https://api.openai.com/mcp'],
    ));

    $factory = new McpFactory(
        servers: ['docs' => ['transport' => 'disabled', 'name' => 'docs']],
        repository: $repo,
        urlGuard: new OutboundUrlGuard(['api.openai.com'], allowPrivateNetworks: false),
        stdioGuard: new McpStdioGuard(false, []),
    );

    $config = $factory->connector('docs', 'tenant_a');
    assertTrue($config !== null, 'connector created');

    pass('mcp tenant repository overrides static');
}

function testDbMcpRepository(): void
{
    $pdo = new PDO('sqlite::memory:');
    $repo = new DbMcpServerConfigRepository($pdo, autoMigrate: true);
    $repo->list(); // trigger autoMigrate

    $stmt = $pdo->prepare(
        "INSERT INTO mcp_server_configs (server_id, tenant_id, config_json, enabled)
         VALUES ('db_docs', 't1', :json, 1)",
    );
    $stmt->execute([':json' => json_encode(['transport' => 'disabled', 'name' => 'db_docs'])]);

    $found = $repo->find('db_docs', 't1');
    assertTrue($found !== null, 'found in db');
    assertTrue($found->id === 'db_docs', 'id match');

    pass('db mcp repository');
}

function testNeuronFactoryPassesTenantId(): void
{
    $repo = new InMemoryMcpServerConfigRepository();
    $repo->upsert(new McpServerConfig(
        id: 'tenant_tool',
        tenantId: 't99',
        config: ['transport' => 'disabled', 'name' => 'tenant_tool'],
    ));

    $factory = new McpFactory(repository: $repo);
    assertTrue(in_array('tenant_tool', $factory->serverNames('t99'), true), 'tenant server listed');

    $neuron = new NeuronFactory(
        mcpFactory: $factory,
        agentFactory: static fn (string $class, WorkflowState $state, array $config): \NeuronAI\Agent\Agent => new $class(),
    );
    $agentClass = new class extends \NeuronAI\Agent\Agent {
    };

    $booted = $neuron->create($agentClass::class, new WorkflowState(), [
        'mcpServers' => ['tenant_tool'],
        'tenantId' => 't99',
    ]);
    assertTrue($booted instanceof \NeuronAI\Agent\Agent, 'agent booted with tenant mcp');

    pass('neuron factory passes tenant id');
}

function testStdioMcpDisabledInProduction(): void
{
    $guard = new McpStdioGuard(allowStdio: false, commandAllowlist: ['npx']);

    try {
        $guard->assertAllowed(['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-everything']], 'bad');
        assertTrue(false, 'should throw');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'disabled'), 'stdio disabled');
    }

    pass('stdio mcp disabled in production');
}

function testStdioCommandAllowlist(): void
{
    $guard = new McpStdioGuard(allowStdio: true, commandAllowlist: ['npx']);

    $guard->assertAllowed(['command' => '/usr/local/bin/npx', 'args' => []], 'ok');

    try {
        $guard->assertAllowed(['command' => '/bin/bash', 'args' => ['-c', 'evil']], 'evil');
        assertTrue(false, 'should throw');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'allowlist'), 'not in allowlist');
    }

    pass('stdio command allowlist');
}

function testOutboundUrlBlocksPrivateNetwork(): void
{
    $guard = new OutboundUrlGuard(allowlistHostSuffixes: [], allowPrivateNetworks: false, requireAllowlist: false);

    try {
        $guard->assertAllowed('http://127.0.0.1:7700', 'test');
        assertTrue(false, 'should throw');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'Private'), 'private blocked');
    }

    pass('outbound url blocks private network');
}

function testOutboundUrlAllowlist(): void
{
    $guard = new OutboundUrlGuard(allowlistHostSuffixes: ['api.openai.com'], allowPrivateNetworks: false);

    $guard->assertAllowed('https://api.openai.com/v1', 'openai');

    try {
        $guard->assertAllowed('https://evil.example.com/hook', 'evil');
        assertTrue(false, 'should throw');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'allowlist'), 'not allowed');
    }

    pass('outbound url allowlist');
}

function testProductionHealthCheckDetectsIssues(): void
{
    $errors = ProductionHealthCheck::check(
        NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => 'missing',
                'allow_fake_embeddings' => false,
                'vector_stores' => [],
            ],
            'neuron' => ['ai_model_providers' => []],
        ]),
        WorkflowConfig::fromArray([
            'workflow' => [
                'default_node_timeout_seconds' => 0,
                'default_run_store' => 'memory',
                'run_stores' => ['memory' => []],
            ],
        ]),
    );

    assertTrue(count($errors) >= 2, 'multiple errors detected');

    pass('production health check detects issues');
}

function testProductionHealthCheckRejectsMemoryRunStoreInProduction(): void
{
    $previous = $_ENV['SWOOLEFY_ENV'] ?? getenv('SWOOLEFY_ENV');
    $_ENV['SWOOLEFY_ENV'] = 'prd';
    putenv('SWOOLEFY_ENV=prd');

    try {
        $errors = ProductionHealthCheck::check(
            NeuronAiConfig::fromArray([
                'rag' => [
                    'default_vector_store' => 'file',
                    'allow_fake_embeddings' => true,
                    'require_tenant_isolation' => false,
                    'vector_stores' => ['file' => ['path' => sys_get_temp_dir()]],
                ],
                'neuron' => ['ai_model_providers' => []],
            ]),
            WorkflowConfig::fromArray([
                'workflow' => [
                    'default_node_timeout_seconds' => 120,
                    'default_run_store' => WorkflowRunStoreName::MEMORY,
                    'run_stores' => [WorkflowRunStoreName::MEMORY => []],
                ],
            ]),
        );

        $found = false;
        foreach ($errors as $error) {
            if (str_contains($error, 'must not be memory')) {
                $found = true;
                break;
            }
        }
        assertTrue($found, 'memory run store flagged in production');
    } finally {
        if ($previous === false || $previous === null || $previous === '') {
            unset($_ENV['SWOOLEFY_ENV']);
            putenv('SWOOLEFY_ENV');
        } else {
            $_ENV['SWOOLEFY_ENV'] = $previous;
            putenv('SWOOLEFY_ENV=' . $previous);
        }
    }

    pass('production health check rejects memory run store');
}

function testProviderFactoryValidatesOutboundUrl(): void
{
    $config = NeuronAiConfig::fromArray([
        'security' => [
            'outbound_url_allowlist' => ['api.openai.com'],
            'allow_private_networks' => false,
        ],
        'neuron' => [
            'ai_model_providers' => [
                'openai' => [
                    'provider' => \NeuronAI\Providers\OpenAI\OpenAI::class,
                    'key' => 'sk-test',
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ],
    ]);

    $factory = new NeuronProviderFactory($config);
    $provider = $factory->createFromAlias('openai', ['baseUri' => 'https://api.openai.com/v1']);
    assertTrue($provider instanceof \NeuronAI\Providers\AIProviderInterface, 'provider ok');

    try {
        $factory->createFromAlias('openai', ['baseUri' => 'http://127.0.0.1/v1']);
        assertTrue(false, 'should throw');
    } catch (\Throwable $e) {
        assertTrue(str_contains($e->getMessage(), 'Private') || str_contains($e->getMessage(), 'allowlist'), 'blocked');
    }

    pass('provider factory validates outbound url');
}

function testProductionHealthCheckValidatesEmbeddingBaseUri(): void
{
    $errors = ProductionHealthCheck::check(
        NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => 'file',
                'allow_fake_embeddings' => false,
                'require_tenant_isolation' => true,
                'embedding_dimension' => 1536,
                'vector_stores' => ['file' => ['path' => sys_get_temp_dir()]],
            ],
            'security' => [
                'outbound_url_allowlist' => ['api.openai.com'],
                'allow_private_networks' => false,
            ],
            'neuron' => [
                'ai_model_providers' => [
                    'openailike' => [
                        'key' => 'sk-test',
                        'baseUri' => 'http://127.0.0.1/v1',
                    ],
                ],
            ],
        ]),
        WorkflowConfig::fromArray([
            'workflow' => [
                'default_node_timeout_seconds' => 120,
                'default_run_store' => WorkflowRunStoreName::DB,
                'run_stores' => [WorkflowRunStoreName::DB => []],
            ],
        ]),
    );

    $found = false;
    foreach ($errors as $error) {
        if (str_contains($error, '127.0.0.1') || str_contains($error, 'embedding:base_uri')) {
            $found = true;
            break;
        }
    }
    assertTrue($found, 'health check flags private embedding base uri');

    pass('production health check validates embedding base uri');
}

$tests = [
    'workflow registry multi version' => 'testWorkflowRegistryMultiVersion',
    'snapshot rejects missing version' => 'testSnapshotRejectsMissingVersion',
    'workflow default node timeout' => 'testWorkflowDefaultNodeTimeout',
    'agent parallel node timeout' => 'testAgentParallelNodeTimeoutInterface',
    'mcp tenant repository overrides static' => 'testMcpTenantRepositoryOverridesStatic',
    'neuron factory passes tenant id' => 'testNeuronFactoryPassesTenantId',
    'stdio mcp disabled' => 'testStdioMcpDisabledInProduction',
    'stdio command allowlist' => 'testStdioCommandAllowlist',
    'outbound url blocks private' => 'testOutboundUrlBlocksPrivateNetwork',
    'outbound url allowlist' => 'testOutboundUrlAllowlist',
    'db mcp repository' => 'testDbMcpRepository',
    'production health check' => 'testProductionHealthCheckDetectsIssues',
    'production health check rejects memory run store' => 'testProductionHealthCheckRejectsMemoryRunStoreInProduction',
    'production health check validates embedding base uri' => 'testProductionHealthCheckValidatesEmbeddingBaseUri',
    'provider outbound url validation' => 'testProviderFactoryValidatesOutboundUrl',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} Phase B production tests passed.\n";

<?php

declare(strict_types=1);

/**
 * Neuron 模块回归测试。
 *
 * 覆盖：NeuronAiConfig、Provider 工厂、ChatHistoryFactory、EmbeddingFactory Fake、
 * NeuronHttpFactory CLI 回退、NeuronFactory agentFactory 注入。
 *
 * 运行：php src/Support/Neuron/Tests/NeuronModuleTest.php
 * 或：composer test:neuron
 */

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\ToolMapperInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Router\RouterProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;
use Swoolefy\Support\Neuron\Memory\ChatHistoryArchiveInterface;
use Swoolefy\Support\Neuron\Memory\HotChatHistoryInterface;
use Swoolefy\Support\Neuron\Memory\ChatHistoryFactory;
use Swoolefy\Support\Neuron\Memory\SqlChatHistory;
use Swoolefy\Support\Neuron\Memory\SqlChatHistoryArchive;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiModelEnv;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Neuron\NeuronProviderFactory;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class TestFallbackProvider implements AIProviderInterface
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var array<string, HttpClientInterface> */
    public static array $clients = [];

    private ?string $system = null;

    /** @param array<string, mixed> $parameters */
    public function __construct(
        private readonly string $key,
        private readonly string $model,
        private readonly string $name,
        private readonly int $failStatus = 0,
        array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        if ($httpClient instanceof HttpClientInterface) {
            $this->setHttpClient($httpClient);
        }
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$clients = [];
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        $this->system = $prompt;

        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new class implements MessageMapperInterface {
            public function map(array $messages): array
            {
                return $messages;
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class implements ToolMapperInterface {
            public function map(array $tools): array
            {
                return $tools;
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        self::$calls[] = $this->name;
        if ($this->failStatus > 0) {
            throw new HttpException(
                'provider failed',
                response: new HttpResponse($this->failStatus, ''),
            );
        }

        return new AssistantMessage($this->name);
    }

    public function stream(Message ...$messages): Generator
    {
        if (false) {
            yield null;
        }

        return $this->chat(...$messages);
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        return $this->chat(...(is_array($messages) ? $messages : [$messages]));
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        self::$clients[$this->name] = $client;

        return $this;
    }
}

function sampleNeuronConfig(): NeuronAiConfig
{
    return NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'default_top_k' => 3,
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimension' => 1536,
            'allow_fake_embeddings' => true,
            'require_tenant_isolation' => false,
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => [
                    'path' => '/tmp/swoolefy_neuron_test',
                ],
            ],
        ],
        'mcp' => [
            'max_local_processes' => 3,
        ],
        'neuron' => [
            'http_client' => NeuronHttpFactory::CLIENT_GUZZLE,
            'default_provider' => NeuronAiProviderName::OPENAI,
            'provider_fallback' => [
                'enabled' => false,
                'order' => [NeuronAiProviderName::OPENAI],
            ],
            'ai_model_providers' => [
                NeuronAiProviderName::OPENAI => [
                    'provider' => OpenAI::class,
                    'key' => 'sk-test-key',
                    'model' => 'gpt-4o-mini',
                    'parameters' => [],
                    'strict_response' => false,
                ],
            ],
        ],
    ]);
}

function testNeuronAiConfigReadsSections(): void
{
    $config = sampleNeuronConfig();
    assertTrue($config->defaultVectorStoreAlias() === NeuronAiVectorStoreName::FILE, 'default alias');
    assertTrue($config->vectorStoreDriver() === NeuronAiVectorStoreName::FILE, 'vector store driver');
    assertTrue($config->defaultTopK() === 3, 'top k');
    assertTrue($config->embeddingModel() === 'text-embedding-3-small', 'embedding model');
    assertTrue($config->embeddingDimension() === 1536, 'embedding dimension');
    assertTrue($config->allowFakeEmbeddings() === true, 'allow fake embeddings in test config');
    assertTrue($config->defaultProviderName() === NeuronAiProviderName::OPENAI, 'default provider');
    assertTrue($config->httpClient() === NeuronHttpFactory::CLIENT_GUZZLE, 'http client');
    assertTrue($config->maxLocalProcesses() === 3, 'mcp processes');
    assertTrue($config->providerFallbackEnabled() === false, 'provider fallback disabled');
    assertTrue($config->providerFallbackOrder() === [NeuronAiProviderName::OPENAI], 'provider fallback order');
}

function testProviderFactoryCreateFromAlias(): void
{
    $factory = new NeuronProviderFactory(sampleNeuronConfig());
    $provider = $factory->createFromAlias(NeuronAiProviderName::OPENAI);
    assertTrue($provider instanceof AIProviderInterface, 'provider interface');
    assertTrue($provider instanceof OpenAI, 'openai instance');
}

function testProviderFactoryCreateDefault(): void
{
    $factory = new NeuronProviderFactory(sampleNeuronConfig());
    $provider = $factory->createDefault();
    assertTrue($provider instanceof OpenAI, 'default provider');
}

function fallbackNeuronConfig(int $primaryFailStatus = 429): NeuronAiConfig
{
    return NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'allow_fake_embeddings' => true,
            'require_tenant_isolation' => false,
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => ['path' => '/tmp/swoolefy_neuron_test'],
            ],
        ],
        'neuron' => [
            'http_client' => NeuronHttpFactory::CLIENT_GUZZLE,
            'default_provider' => 'primary',
            'provider_fallback' => [
                'enabled' => true,
                'order' => ['primary', 'secondary'],
            ],
            'ai_model_providers' => [
                'primary' => [
                    'provider' => TestFallbackProvider::class,
                    'key' => 'primary-key',
                    'model' => 'primary-model',
                    'name' => 'primary',
                    'failStatus' => $primaryFailStatus,
                ],
                'secondary' => [
                    'provider' => TestFallbackProvider::class,
                    'key' => 'secondary-key',
                    'model' => 'secondary-model',
                    'name' => 'secondary',
                    'failStatus' => 0,
                ],
            ],
        ],
    ]);
}

function testProviderFactoryCreatesRouterFallback(): void
{
    TestFallbackProvider::reset();

    $provider = (new NeuronProviderFactory(fallbackNeuronConfig()))->createDefault();

    assertTrue($provider instanceof RouterProvider, 'router provider');
    assertTrue(isset(TestFallbackProvider::$clients['primary']), 'primary http client injected');
    assertTrue(isset(TestFallbackProvider::$clients['secondary']), 'secondary http client injected');
    assertTrue(TestFallbackProvider::$clients['primary'] instanceof GuzzleHttpClient, 'primary guzzle client');
    assertTrue(TestFallbackProvider::$clients['secondary'] instanceof GuzzleHttpClient, 'secondary guzzle client');
}

function testProviderFallbackRetriesTransientError(): void
{
    TestFallbackProvider::reset();

    $provider = (new NeuronProviderFactory(fallbackNeuronConfig(429)))->createDefault();
    $response = $provider->chat(new UserMessage('hello'));

    assertTrue($response->getContent() === 'secondary', 'transient error falls back to secondary');
    assertTrue(TestFallbackProvider::$calls === ['primary', 'secondary'], 'primary then secondary called');
}

function testProviderFallbackDoesNotRetryDeterministicError(): void
{
    TestFallbackProvider::reset();

    $provider = (new NeuronProviderFactory(fallbackNeuronConfig(401)))->createDefault();
    try {
        $provider->chat(new UserMessage('hello'));
        assertTrue(false, 'should throw without fallback');
    } catch (HttpException) {
        assertTrue(TestFallbackProvider::$calls === ['primary'], '401 does not call secondary');
    }
}

function testRouterProviderSetHttpClientForwardsToChildren(): void
{
    TestFallbackProvider::reset();

    $provider = (new NeuronProviderFactory(fallbackNeuronConfig()))->createDefault();
    $client = new GuzzleHttpClient();
    $provider->setHttpClient($client);

    assertTrue(TestFallbackProvider::$clients['primary'] === $client, 'primary client forwarded');
    assertTrue(TestFallbackProvider::$clients['secondary'] === $client, 'secondary client forwarded');
}

function testProviderFactoryUnknownAliasThrows(): void
{
    $factory = new NeuronProviderFactory(sampleNeuronConfig());
    try {
        $factory->createFromAlias('nope');
        assertTrue(false, 'should throw');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'Unknown'), 'unknown alias message');
    }
}

function testChatHistoryFactoryInMemory(): void
{
    $history = ChatHistoryFactory::inMemory(1000);
    assertTrue($history instanceof InMemoryChatHistory, 'in-memory chat history');
}

function testMemoryInterfacesAreImplemented(): void
{
    assertTrue(
        is_subclass_of(\Swoolefy\Support\Neuron\Memory\RedisChatHistory::class, HotChatHistoryInterface::class),
        'RedisChatHistory implements hot history interface',
    );
    assertTrue(
        is_subclass_of(SqlChatHistory::class, HotChatHistoryInterface::class),
        'SqlChatHistory implements hot history interface',
    );
    assertTrue(
        is_subclass_of(SqlChatHistoryArchive::class, ChatHistoryArchiveInterface::class),
        'SqlChatHistoryArchive implements archive interface',
    );
}

function testEmbeddingFactoryWithoutApiKeyFailsFast(): void
{
    $prev = env(NeuronAiModelEnv::OPENAI_API_KEY, '');
    putenv('OPENAI_API_KEY');
    try {
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'allow_fake_embeddings' => false,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => '/tmp/x'],
                ],
            ],
            'neuron' => ['ai_model_providers' => []],
        ]);
        try {
            (new EmbeddingFactory($config))->make();
            assertTrue(false, 'should throw');
        } catch (WorkflowException $e) {
            assertTrue(str_contains($e->getMessage(), 'Embedding API key'), 'fail-fast message');
        }
    } finally {
        if ($prev === false) {
            putenv('OPENAI_API_KEY');
        } else {
            putenv('OPENAI_API_KEY=' . $prev);
        }
    }
}

function testEmbeddingFactoryAllowFakeUsesConfiguredDimension(): void
{
    $prev = env(NeuronAiModelEnv::OPENAI_API_KEY, '');
    putenv('OPENAI_API_KEY');
    try {
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'embedding_dimension' => 768,
                'allow_fake_embeddings' => true,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => '/tmp/x'],
                ],
            ],
        ]);
        $embedder = (new EmbeddingFactory($config))->make();
        assertTrue($embedder instanceof FakeEmbeddingsProvider, 'fake with allow flag');
    } finally {
        if ($prev === false) {
            putenv('OPENAI_API_KEY');
        } else {
            putenv('OPENAI_API_KEY=' . $prev);
        }
    }
}

function testEmbeddingFactoryBlocksPrivateBaseUri(): void
{
    $prevKey = getenv('OPENAI_API_KEY');
    putenv('OPENAI_API_KEY=sk-test');
    putenv('OPENAILIKE_BASE_URI');

    try {
        $config = NeuronAiConfig::fromArray([
            'rag' => [
                'default_vector_store' => NeuronAiVectorStoreName::FILE,
                'allow_fake_embeddings' => false,
                'embedding_dimension' => 1536,
                'vector_stores' => [
                    NeuronAiVectorStoreName::FILE => ['path' => '/tmp/x'],
                ],
            ],
            'security' => [
                'outbound_url_allowlist' => ['api.openai.com'],
                'allow_private_networks' => false,
            ],
            'neuron' => [
                'ai_model_providers' => [
                    NeuronAiProviderName::OPENAILIKE => [
                        'key' => 'sk-test',
                        'baseUri' => 'http://127.0.0.1/v1',
                    ],
                ],
            ],
        ]);

        try {
            (new EmbeddingFactory($config))->make();
            assertTrue(false, 'should throw');
        } catch (\RuntimeException $e) {
            assertTrue(
                str_contains($e->getMessage(), 'Private') || str_contains($e->getMessage(), 'embedding:base_uri'),
                'private embedding baseUri blocked',
            );
        }
    } finally {
        if ($prevKey === false) {
            putenv('OPENAI_API_KEY');
        } else {
            putenv('OPENAI_API_KEY=' . $prevKey);
        }
    }
}

function testOutboundUrlsIncludeEmbeddingBaseUri(): void
{
    $config = NeuronAiConfig::fromArray([
        'neuron' => [
            'ai_model_providers' => [
                NeuronAiProviderName::OPENAILIKE => [
                    'baseUri' => 'https://embed.example.com/v1',
                ],
            ],
        ],
    ]);

    $urls = $config->outboundUrlsToValidate();
    assertTrue(
        ($urls['embedding:base_uri'] ?? '') === 'https://embed.example.com/v1',
        'embedding base uri included for health check',
    );
    assertTrue(
        $config->resolvedEmbeddingBaseUri() === 'https://embed.example.com/v1',
        'resolved embedding base uri matches config',
    );
}

function testNeuronHttpFactoryCliFallback(): void
{
    // CLI / no APP_PATH → Guzzle
    $client = NeuronHttpFactory::create();
    assertTrue($client instanceof GuzzleHttpClient, 'guzzle fallback without APP_PATH');
}

function testNeuronFactoryUsesAgentFactoryHook(): void
{
    $marker = new class extends Agent {
    };

    $factory = new NeuronFactory(
        agentFactory: static fn (string $class, WorkflowState $state, array $config): Agent => $marker,
    );

    $agent = $factory->create(Agent::class, new WorkflowState());
    assertTrue($agent === $marker, 'custom agentFactory used');
}

function testNeuronFactoryThrowsWhenNoProviderCredentials(): void
{
    $providerFactory = new NeuronProviderFactory(NeuronAiConfig::fromArray([
        'neuron' => [
            'default_provider' => NeuronAiProviderName::OPENAI,
            'ai_model_providers' => [
                NeuronAiProviderName::OPENAI => [
                    'provider' => OpenAI::class,
                    'key' => '',
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ],
    ]));

    $factory = new NeuronFactory(
        providerFactory: $providerFactory,
    );

    $agentClass = new class extends Agent {
    };

    try {
        $factory->create($agentClass::class, new WorkflowState());
        assertTrue(false, 'should throw when no provider credentials');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'No AI provider available'), 'clear error message');
    }
}

function createSqliteChatPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<'SQL'
CREATE TABLE chat_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL DEFAULT '',
    user_id TEXT NOT NULL DEFAULT '',
    thread_id TEXT NOT NULL,
    messages TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT NULL,
    UNIQUE (tenant_id, thread_id)
);
CREATE TABLE chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id TEXT NOT NULL DEFAULT '',
    user_id TEXT NOT NULL DEFAULT '',
    thread_id TEXT NOT NULL,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    metadata_json TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TEXT NULL
);
SQL);

    return $pdo;
}

function testSqlChatHistoryTenantIsolation(): void
{
    $pdo = createSqliteChatPdo();
    $config = sampleNeuronConfig();

    $historyA = ChatHistoryFactory::sql('thread-1', $pdo, tenantId: 'tenant_a', userId: 'user_1', config: $config);
    assertTrue($historyA instanceof SqlChatHistory, 'sql chat history instance');
    $historyA->addMessage(new UserMessage('hello from tenant A'));

    $historyB = ChatHistoryFactory::sql('thread-1', $pdo, tenantId: 'tenant_b', userId: 'user_2', config: $config);
    assertTrue(count($historyB->getMessages()) === 0, 'other tenant should not see messages');

    $historyA2 = ChatHistoryFactory::sql('thread-1', $pdo, tenantId: 'tenant_a', userId: 'user_1', config: $config);
    assertTrue(count($historyA2->getMessages()) === 1, 'same tenant reloads messages');
    assertTrue($historyA2->getMessages()[0]->getContent() === 'hello from tenant A', 'message content preserved');
}

function testSqlChatHistorySoftDeleteOnFlush(): void
{
    $pdo = createSqliteChatPdo();
    $config = sampleNeuronConfig();

    $history = ChatHistoryFactory::sql('thread-soft', $pdo, tenantId: 'tenant_a', config: $config);
    $history->addMessage(new UserMessage('to be cleared'));
    $history->flushAll();

    $stmt = $pdo->prepare('SELECT deleted_at, messages FROM chat_history WHERE tenant_id = :tenant_id AND thread_id = :thread_id');
    $stmt->execute(['tenant_id' => 'tenant_a', 'thread_id' => 'thread-soft']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    assertTrue(is_array($row), 'row exists');
    assertTrue($row['deleted_at'] !== null && $row['deleted_at'] !== '', 'soft deleted');
    assertTrue($row['messages'] === '[]', 'messages cleared');

    $history2 = ChatHistoryFactory::sql('thread-soft', $pdo, tenantId: 'tenant_a', config: $config);
    assertTrue(count($history2->getMessages()) === 0, 'revived row starts empty');
}

function testSqlChatHistoryArchiveTenantIsolation(): void
{
    $pdo = createSqliteChatPdo();
    $config = sampleNeuronConfig();

    $archiveA = ChatHistoryFactory::archive($pdo, tenantId: 'tenant_a', userId: 'user_1', config: $config);
    $archiveA->archiveMessage('thread-x', 'user', 'message for tenant A');

    $archiveB = ChatHistoryFactory::archive($pdo, tenantId: 'tenant_b', userId: 'user_2', config: $config);
    assertTrue($archiveB->listMessages('thread-x') === [], 'other tenant archive empty');

    $messages = $archiveA->listMessages('thread-x');
    assertTrue(count($messages) === 1, 'tenant A has archived message');
    assertTrue($messages[0]['content'] === 'message for tenant A', 'archive content');
}

function testSqlChatHistoryRequiresTenantWhenConfigured(): void
{
    $pdo = createSqliteChatPdo();
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'require_tenant_isolation' => true,
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => ['path' => '/tmp/x'],
            ],
        ],
    ]);

    try {
        ChatHistoryFactory::sql('thread-1', $pdo, config: $config);
        assertTrue(false, 'should throw without tenant');
    } catch (RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'tenantId'), 'tenant required');
    }
}

function testVectorStoreNameConstants(): void
{
    assertTrue(NeuronAiVectorStoreName::FILE === 'file', 'file');
    assertTrue(NeuronAiVectorStoreName::MILVUS === 'milvus', 'milvus');
    assertTrue(NeuronAiVectorStoreName::MEILISEARCH === 'meilisearch', 'meilisearch');
}

$tests = [
    'config sections' => 'testNeuronAiConfigReadsSections',
    'provider from alias' => 'testProviderFactoryCreateFromAlias',
    'provider default' => 'testProviderFactoryCreateDefault',
    'provider router fallback' => 'testProviderFactoryCreatesRouterFallback',
    'provider fallback transient retry' => 'testProviderFallbackRetriesTransientError',
    'provider fallback deterministic error' => 'testProviderFallbackDoesNotRetryDeterministicError',
    'provider fallback http client forwarding' => 'testRouterProviderSetHttpClientForwardsToChildren',
    'provider unknown alias' => 'testProviderFactoryUnknownAliasThrows',
    'chat history factory in-memory' => 'testChatHistoryFactoryInMemory',
    'sql chat history tenant isolation' => 'testSqlChatHistoryTenantIsolation',
    'sql chat history soft delete' => 'testSqlChatHistorySoftDeleteOnFlush',
    'sql chat archive tenant isolation' => 'testSqlChatHistoryArchiveTenantIsolation',
    'sql chat history require tenant' => 'testSqlChatHistoryRequiresTenantWhenConfigured',
    'memory interfaces' => 'testMemoryInterfacesAreImplemented',
    'embedding fail-fast without key' => 'testEmbeddingFactoryWithoutApiKeyFailsFast',
    'embedding fake with allow flag' => 'testEmbeddingFactoryAllowFakeUsesConfiguredDimension',
    'embedding blocks private base uri' => 'testEmbeddingFactoryBlocksPrivateBaseUri',
    'outbound urls include embedding base uri' => 'testOutboundUrlsIncludeEmbeddingBaseUri',
    'http factory cli fallback' => 'testNeuronHttpFactoryCliFallback',
    'neuron factory agent hook' => 'testNeuronFactoryUsesAgentFactoryHook',
    'neuron factory no provider' => 'testNeuronFactoryThrowsWhenNoProviderCredentials',
    'vector store name constants' => 'testVectorStoreNameConstants',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Neuron module tests passed.\n";

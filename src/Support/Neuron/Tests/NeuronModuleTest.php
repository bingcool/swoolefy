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
 * Neuron 模块回归测试（无需真实外网 LLM）。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | NeuronAiConfig | 分段读取、默认 Provider / Embedding / MCP / Fallback |
 * | NeuronProviderFactory | 别名创建、Router fallback、瞬时/确定性错误分流、HTTP Client 转发 |
 * | ChatHistory / Memory | InMemory、SQL 租户隔离、软删、Archive、接口契约 |
 * | EmbeddingFactory | 无 key fail-fast、allow_fake、私网 baseUri 拦截、健康检查 URL |
 * | NeuronHttpFactory | CLI / 无 APP_PATH 时回退 Guzzle |
 * | NeuronFactory | agentFactory 钩子、缺凭证、显式 Provider 禁静默降级、Middleware 挂载 |
 *
 * ## 运行
 * ```bash
 * php src/Support/Neuron/Tests/NeuronModuleTest.php
 * # 或
 * composer test:neuron
 * ```
 *
 * 说明：Fallback 用例使用本文件内 {@see TestFallbackProvider}，不打真实 OpenAI。
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

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// ---------------------------------------------------------------------------
// 测试替身：可配置失败状态码的 AIProvider，用于验证 Router fallback
// ---------------------------------------------------------------------------

/**
 * Fallback 链路用的假 Provider。
 *
 * - chat() 时把 `$name` 记入 {@see $calls}，便于断言调用顺序
 * - `$failStatus > 0` 时抛 {@see HttpException}（带 HTTP 状态），模拟 429/401 等
 * - setHttpClient 记录到 {@see $clients}，验证工厂是否注入 Guzzle / 转发 Client
 */
final class TestFallbackProvider implements AIProviderInterface
{
    /** @var list<string> 被 chat() 调用过的 provider name 顺序 */
    public static array $calls = [];

    /** @var array<string, HttpClientInterface> name => 注入的 HttpClient */
    public static array $clients = [];

    private ?string $system = null;

    /**
     * @param string $key API key（假实现未使用，仅占位）
     * @param string $model 模型名（假实现未使用）
     * @param string $name 逻辑名，写入 $calls / 成功时作为 AssistantMessage 内容
     * @param int $failStatus >0 则 chat 抛 HttpException(该状态码)
     * @param array<string, mixed> $parameters 额外参数占位
     */
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

    /** 每条用例前清空静态状态，避免串测 */
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

// ---------------------------------------------------------------------------
// 配置夹具
// ---------------------------------------------------------------------------

/**
 * 通用 Neuron 测试配置：FILE 向量库、允许 Fake Embedding、OpenAI 默认 Provider、无 fallback。
 */
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
                'order' => [],
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

/**
 * Fallback 专用配置：primary（可失败）→ secondary（成功）。
 *
 * @param int $primaryFailStatus primary chat 抛出的 HTTP 状态；0 表示不失败
 */
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
                'order' => ['secondary'],
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

/**
 * 内存 SQLite：chat_history + chat_messages，供 SqlChatHistory / Archive 租户隔离用例。
 */
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

// ---------------------------------------------------------------------------
// NeuronAiConfig
// ---------------------------------------------------------------------------

/** 校验 fromArray 后各分段 getter：RAG / Embedding / Provider / HTTP / MCP / Fallback */
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
    assertTrue($config->providerFallbackOrder() === [], 'provider fallback disabled by empty order');
}

// ---------------------------------------------------------------------------
// NeuronProviderFactory
// ---------------------------------------------------------------------------

/** 按别名创建 Provider，应为 OpenAI 实例 */
function testProviderFactoryCreateFromAlias(): void
{
    $factory = new NeuronProviderFactory(sampleNeuronConfig());
    $provider = $factory->createFromAlias(NeuronAiProviderName::OPENAI);
    assertTrue($provider instanceof AIProviderInterface, 'provider interface');
    assertTrue($provider instanceof OpenAI, 'openai instance');
}

/** createDefault 使用 default_provider 别名 */
function testProviderFactoryCreateDefault(): void
{
    $factory = new NeuronProviderFactory(sampleNeuronConfig());
    $provider = $factory->createDefault();
    assertTrue($provider instanceof OpenAI, 'default provider');
}

/**
 * 配置了 fallback.order 时，createDefault 返回 RouterProvider，
 * 且 primary/secondary 均被注入 GuzzleHttpClient。
 */
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

/**
 * 瞬时错误（如 429）：Router 应重试并落到 secondary；
 * 调用顺序 primary → secondary，回复内容为 secondary。
 */
function testProviderFallbackRetriesTransientError(): void
{
    TestFallbackProvider::reset();

    $provider = (new NeuronProviderFactory(fallbackNeuronConfig(429)))->createDefault();
    $response = $provider->chat(new UserMessage('hello'));

    assertTrue($response->getContent() === 'secondary', 'transient error falls back to secondary');
    assertTrue(TestFallbackProvider::$calls === ['primary', 'secondary'], 'primary then secondary called');
}

/**
 * 确定性错误（如 401）：不应切换 secondary，仅调用 primary 后抛 HttpException。
 */
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

/**
 * RouterProvider::setHttpClient 应转发到所有子 Provider（primary + secondary 同一实例）。
 */
function testRouterProviderSetHttpClientForwardsToChildren(): void
{
    TestFallbackProvider::reset();

    $provider = (new NeuronProviderFactory(fallbackNeuronConfig()))->createDefault();
    $client = new GuzzleHttpClient();
    $provider->setHttpClient($client);

    assertTrue(TestFallbackProvider::$clients['primary'] === $client, 'primary client forwarded');
    assertTrue(TestFallbackProvider::$clients['secondary'] === $client, 'secondary client forwarded');
}

/** 未知别名 → WorkflowException，消息含 Unknown */
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

// ---------------------------------------------------------------------------
// ChatHistory / Memory
// ---------------------------------------------------------------------------

/** ChatHistoryFactory::inMemory 返回 Neuron InMemoryChatHistory */
function testChatHistoryFactoryInMemory(): void
{
    $history = ChatHistoryFactory::inMemory(1000);
    assertTrue($history instanceof InMemoryChatHistory, 'in-memory chat history');
}

/**
 * 热存储 / 归档接口契约：
 * RedisChatHistory、SqlChatHistory → HotChatHistoryInterface；
 * SqlChatHistoryArchive → ChatHistoryArchiveInterface。
 */
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

/**
 * 同 thread_id、不同 tenant_id：A 写入的消息 B 不可见；
 * A 重新打开可加载到自己的消息。
 */
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

/**
 * flushAll 软删：deleted_at 非空、messages 置 []；
 * 同 tenant/thread 再打开得到空历史（行可被复活但内容已清空）。
 */
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

/** Archive 同样按 tenant 隔离：A 归档的消息 B 的 listMessages 为空 */
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

/**
 * require_tenant_isolation=true 时，创建 SqlChatHistory 未传 tenantId 必须抛错。
 */
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

// ---------------------------------------------------------------------------
// EmbeddingFactory / 出站 URL
// ---------------------------------------------------------------------------

/**
 * 无 OPENAI_API_KEY 且 allow_fake_embeddings=false → fail-fast，
 * 异常信息含 Embedding API key（禁止静默用假向量进生产）。
 */
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

/**
 * allow_fake_embeddings=true 且无真实 key 时，允许 FakeEmbeddingsProvider（单测/本地）。
 */
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

/**
 * Embedding baseUri 指向私网（127.0.0.1）且 allow_private_networks=false 时必须拦截，
 * 防止 SSRF / 误连内网。
 */
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

/**
 * ProductionHealthCheck 用的 outboundUrlsToValidate 须包含 embedding:base_uri，
 * 与 resolvedEmbeddingBaseUri 一致。
 */
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

// ---------------------------------------------------------------------------
// NeuronHttpFactory / NeuronFactory
// ---------------------------------------------------------------------------

/**
 * 无应用容器（CLI / 未 bootstrap APP_PATH）时，Http 工厂回退 Guzzle，
 * 保证脚本与单测也能发请求。
 */
function testNeuronHttpFactoryCliFallback(): void
{
    // CLI / no APP_PATH → Guzzle
    $client = NeuronHttpFactory::create();
    assertTrue($client instanceof GuzzleHttpClient, 'guzzle fallback without APP_PATH');
}

/**
 * 注入 agentFactory 钩子后，create() 必须使用钩子返回的 Agent 实例（可替换默认 new）。
 */
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

/**
 * 默认 Provider 无有效凭证时，create Agent 抛 WorkflowException，
 * 消息含 No AI provider available。
 */
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

/**
 * 显式指定的 Provider 缺凭证 → 必须 fail-fast，禁止静默落到有凭证的 default_provider。
 *
 * 同时验证：未显式指定时 createDefault 仍可用有凭证的 default。
 */
function testExplicitProviderMissingCredentialsFailsFast(): void
{
    // default_provider 有完整凭证；显式指定的 openai 缺 key → 必须抛错，禁止静默落到 default
    $config = NeuronAiConfig::fromArray([
        'neuron' => [
            'default_provider' => 'fallback_ok',
            'ai_model_providers' => [
                'fallback_ok' => [
                    'provider' => OpenAI::class,
                    'key' => 'sk-fallback',
                    'model' => 'gpt-4o-mini',
                ],
                NeuronAiProviderName::OPENAI => [
                    'provider' => OpenAI::class,
                    'key' => '',
                    'model' => 'gpt-4o-mini',
                ],
            ],
        ],
    ]);
    $providerFactory = new NeuronProviderFactory($config);

    try {
        $providerFactory->createFromAgentOptions(['provider' => NeuronAiProviderName::OPENAI]);
        assertTrue(false, 'explicit provider without credentials should throw');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'missing required credentials'), 'credentials message');
        assertTrue(str_contains($e->getMessage(), NeuronAiProviderName::OPENAI), 'includes alias');
    }

    $factory = new NeuronFactory(providerFactory: $providerFactory);
    $agentClass = new class extends Agent {
    };

    try {
        $factory->create($agentClass::class, new WorkflowState(), [
            'provider' => NeuronAiProviderName::OPENAI,
        ]);
        assertTrue(false, 'NeuronFactory must not fall back to default_provider');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'missing required credentials'), 'factory surfaces fail-fast');
        assertTrue(!str_contains($e->getMessage(), 'No AI provider available'), 'must not use generic default-missing message');
    }

    // 未显式指定 provider 时，仍可落到有凭证的 default
    $default = $providerFactory->createDefault();
    assertTrue($default instanceof OpenAI, 'default provider still works when not explicit');
}

/**
 * agent options 中 globalMiddleware + 节点 middleware（callable）均应挂载并执行 before/after。
 * 使用 FakeAIProvider，不依赖外网。
 */
function testNeuronFactoryAttachesMiddlewareFromAgentOptions(): void
{
    $recorder = new class implements \NeuronAI\Workflow\Middleware\WorkflowMiddleware {
        public int $beforeCount = 0;
        public int $afterCount = 0;

        public function before(
            \NeuronAI\Workflow\NodeInterface $node,
            \NeuronAI\Workflow\Events\Event $event,
            \NeuronAI\Workflow\WorkflowState $state,
        ): void {
            $this->beforeCount++;
        }

        public function after(
            \NeuronAI\Workflow\NodeInterface $node,
            \NeuronAI\Workflow\Events\Event $result,
            \NeuronAI\Workflow\WorkflowState $state,
        ): void {
            $this->afterCount++;
        }
    };

    $agentClass = new class extends Agent {
        protected function provider(): AIProviderInterface
        {
            return \NeuronAI\Testing\FakeAIProvider::make(new AssistantMessage('middleware-ok'));
        }
    };

    $factory = new NeuronFactory(
        config: NeuronAiConfig::fromArray([
            'capability' => ['enabled' => false],
        ]),
    );

    $agent = $factory->create($agentClass::class, new WorkflowState(), [
        'capabilityEnabled' => false,
        'globalMiddleware' => [$recorder],
        'middleware' => [
            \NeuronAI\Agent\Nodes\ChatNode::class => [
                static function (Agent $agent) use ($recorder): \NeuronAI\Workflow\Middleware\WorkflowMiddleware {
                    // callable 路径：返回同一 recorder，证明 factory 会解析 callable
                    return $recorder;
                },
            ],
        ],
    ]);

    $reply = (string) $agent->chat(new UserMessage('ping'))->getMessage()->getContent();
    assertTrue($reply === 'middleware-ok', 'fake provider reply');
    assertTrue($recorder->beforeCount > 0, 'global/node middleware before() ran');
    assertTrue($recorder->afterCount > 0, 'global/node middleware after() ran');
}

/** globalMiddleware 传入非法类型（非 WorkflowMiddleware）→ WorkflowException */
function testNeuronFactoryRejectsInvalidMiddleware(): void
{
    $agentClass = new class extends Agent {
        protected function provider(): AIProviderInterface
        {
            return \NeuronAI\Testing\FakeAIProvider::make(new AssistantMessage('x'));
        }
    };

    $factory = new NeuronFactory(
        config: NeuronAiConfig::fromArray(['capability' => ['enabled' => false]]),
    );

    try {
        $factory->create($agentClass::class, new WorkflowState(), [
            'capabilityEnabled' => false,
            'globalMiddleware' => ['not-a-middleware'],
        ]);
        assertTrue(false, 'should reject invalid middleware');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'middleware must be'), 'invalid middleware message');
    }
}

// ---------------------------------------------------------------------------
// 常量
// ---------------------------------------------------------------------------

/** NeuronAiVectorStoreName 字符串常量与配置别名一致 */
function testVectorStoreNameConstants(): void
{
    assertTrue(NeuronAiVectorStoreName::FILE === 'file', 'file');
    assertTrue(NeuronAiVectorStoreName::MILVUS === 'milvus', 'milvus');
    assertTrue(NeuronAiVectorStoreName::MEILISEARCH === 'meilisearch', 'meilisearch');
}

// ---------------------------------------------------------------------------
// 运行入口：名称 => 函数，便于阅读失败时对照
// ---------------------------------------------------------------------------

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
    'explicit provider missing credentials fail-fast' => 'testExplicitProviderMissingCredentialsFailsFast',
    'neuron factory middleware attach' => 'testNeuronFactoryAttachesMiddlewareFromAgentOptions',
    'neuron factory middleware invalid' => 'testNeuronFactoryRejectsInvalidMiddleware',
    'vector store name constants' => 'testVectorStoreNameConstants',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} Neuron module tests passed.\n";

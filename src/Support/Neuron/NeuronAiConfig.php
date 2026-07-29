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

namespace Swoolefy\Support\Neuron;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Mcp\McpStdioGuard;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;
use Swoolefy\Support\Security\OutboundUrlGuard;

/**
 * Neuron AI / RAG / MCP / Capability 模块配置加载器。
 *
 * 读取 APP_PATH/Config/neuron_ai.php（可选），环境变量优先（env > 配置文件 > 默认值）。
 *
 * 配置分段：
 * - rag         — 向量库、Embedding、入库模式
 * - mcp         — MCP Server 安全与 DB 组件
 * - security    — 出站 URL 白名单
 * - capability  — CapabilityCenter Tool 动态筛选
 * - neuron      — LLM Provider、HTTP Client、Fallback
 *
 * RAG 向量库配置结构：
 *   rag.default_vector_store   — 默认使用的向量库别名（env RAG_VECTOR_STORE 可覆盖）
 *   rag.vector_stores[alias]   — 各向量库连接参数；可选 driver 字段，缺省时别名即驱动名
 */
final class NeuronAiConfig
{
    /** @param array<string, mixed> $config neuron_ai.php 解析后的完整配置数组 */
    private function __construct(
        private readonly array $config,
    ) {
    }

    /** 从 APP_PATH/Config/neuron_ai.php 加载配置（文件不存在时返回空数组；不依赖 application.yaml）。 */
    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('neuron_ai.php'));
    }

    /**
     * 从数组构造配置实例。
     *
     * @param array<string, mixed> $config
     *
     * @internal 单测 / 脚本注入，可只传部分段覆盖默认行为
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /** 返回 rag 配置段（向量库、Embedding、入库等）。 */
    /** @return array<string, mixed> */
    public function ragSection(): array
    {
        return (array) ($this->config['rag'] ?? []);
    }

    /** 返回 rag.ingestion 配置段（sync / queue 入库模式）。 */
    /** @return array<string, mixed> */
    public function ragIngestionSection(): array
    {
        return (array) ($this->ragSection()['ingestion'] ?? []);
    }

    /**
     * RAG 入库模式。
     *
     * - sync：同步入库，保持旧行为；
     * - queue：通过配置化 producer 异步入库。
     */
    public function ragIngestMode(): string
    {
        // env RAG_INGEST_MODE 优先于配置文件
        $mode = strtolower(ApplicationConfig::pickStringEnvFirst(
            $this->ragIngestionSection(),
            'mode',
            'RAG_INGEST_MODE',
            'sync',
        ));

        // 非法值回退 sync，避免配置拼写错误导致运行时异常
        return in_array($mode, ['sync', 'queue'], true) ? $mode : 'sync';
    }

    /** 返回 queue 模式下的 producer / consumer 配置。 */
    /** @return array<string, mixed> */
    public function ragIngestQueueConfig(): array
    {
        $queue = $this->ragIngestionSection()['queue'] ?? [];

        return is_array($queue) ? $queue : [];
    }

    /** 返回 mcp 配置段（stdio 守卫、DB 组件、进程并发等）。 */
    /** @return array<string, mixed> */
    public function mcpSection(): array
    {
        return (array) ($this->config['mcp'] ?? []);
    }

    /** 返回 neuron 配置段（Provider、HTTP Client、Fallback 等）。 */
    /** @return array<string, mixed> */
    public function neuronSection(): array
    {
        return (array) ($this->config['neuron'] ?? []);
    }

    /** 返回 capability 配置段（CapabilityCenter Tool 动态筛选）。 */
    /** @return array<string, mixed> */
    public function capabilitySection(): array
    {
        $section = $this->config['capability'] ?? [];

        return is_array($section) ? $section : [];
    }

    /** 返回 skills 配置段（本地 SKILL.md 根目录等）。 */
    /** @return array<string, mixed> */
    public function skillsSection(): array
    {
        $section = $this->config['skills'] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * 本地 Skill 扫描根目录（skills.paths）。
     *
     * 空数组表示由 {@see \Swoolefy\Support\Neuron\Skill\SkillLoader::defaultRoots()} 决定
     * （APP_PATH/Skills、ROOT_PATH/Skills）。agentOptions['skillPaths'] 可 per-call 覆盖。
     *
     * @return list<string>
     */
    public function skillPaths(): array
    {
        $raw = $this->skillsSection()['paths'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $paths = [];
        foreach ($raw as $path) {
            if (is_string($path) && $path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * CapabilityCenter 总开关。
     *
     * 默认 false：关闭时 NeuronFactory 保持旧 McpFactory::tools() 全量挂载逻辑。
     * 可通过 env CAPABILITY_ENABLED 覆盖。
     */
    public function capabilityEnabled(): bool
    {
        $fromConfig = $this->capabilitySection()['enabled'] ?? false;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                ['enabled' => $fromConfig ? '1' : '0'],
                'enabled',
                NeuronAiCapabilityEnv::ENABLED,
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Capability 默认 Top-K 候选数。
     *
     * 每轮动态筛选的普通工具上限；pinnedTools 不占该 quota。
     */
    public function capabilityDefaultTopK(): int
    {
        return max(0, ApplicationConfig::pickIntEnvFirst(
            $this->capabilitySection(),
            'default_top_k',
            NeuronAiCapabilityEnv::DEFAULT_TOP_K,
            12,
        ));
    }

    /**
     * 注入给 LLM schema 的最大工具数兜底。
     *
     * 即使 Resolver 选出更多候选，materialize 后也会按该上限截断，防止 token 暴涨。
     */
    public function capabilityMaxSchemaTools(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->capabilitySection(),
            'max_schema_tools',
            NeuronAiCapabilityEnv::MAX_SCHEMA_TOOLS,
            20,
        ));
    }

    /**
     * Agent boot 时是否同步 MCP tool descriptor 到 Registry。
     *
     * true 时 CapabilityComponentFactory 构建 CapabilityCenter 前会调用 McpCapabilitySync。
     */
    public function capabilityMcpSyncOnBoot(): bool
    {
        $fromConfig = $this->capabilitySection()['mcp_sync_on_boot'] ?? true;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                ['mcp_sync_on_boot' => $fromConfig ? '1' : '0'],
                'mcp_sync_on_boot',
                NeuronAiCapabilityEnv::MCP_SYNC_ON_BOOT,
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /** 是否输出 capability.resolve / materialize 等调试日志。 */
    public function capabilityDebug(): bool
    {
        $fromConfig = $this->capabilitySection()['debug'] ?? false;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                ['debug' => $fromConfig ? '1' : '0'],
                'debug',
                NeuronAiCapabilityEnv::DEBUG,
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Capability 出错时的失败策略。
     *
     * false（默认）：fail-open，回退旧 MCP 全量挂载，适合灰度；
     * true：fail-closed，直接抛错，适合严格生产策略。
     */
    public function capabilityFailClosed(): bool
    {
        $fromConfig = $this->capabilitySection()['fail_closed'] ?? false;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                ['fail_closed' => $fromConfig ? '1' : '0'],
                'fail_closed',
                NeuronAiCapabilityEnv::FAIL_CLOSED,
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * 默认向量库别名（rag.default_vector_store）。
     *
     * 未配置时回退为 file；环境变量 RAG_VECTOR_STORE 可覆盖。
     */
    public function defaultVectorStoreAlias(): string
    {
        $alias = ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'default_vector_store',
            NeuronAiRagEnv::VECTOR_STORE,
            '',
        );

        // 空别名统一回退 file 驱动，保证总有可用默认值
        return $alias !== '' ? $alias : NeuronAiVectorStoreName::FILE;
    }

    /**
     * 已声明的向量库配置表（rag.vector_stores）。
     *
     * 键为别名；值须为数组。可选字段 driver（缺省时别名即驱动类型）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function vectorStores(): array
    {
        $raw = $this->ragSection()['vector_stores'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        // 过滤非法条目：别名须为非空字符串，值须为数组
        $stores = [];
        foreach ($raw as $alias => $section) {
            if (!is_string($alias) || $alias === '' || !is_array($section)) {
                continue;
            }
            $stores[$alias] = $section;
        }

        return $stores;
    }

    /**
     * 解析别名对应的驱动类型（NeuronAiVectorStoreName::*）。
     *
     * 优先读 section.driver；否则别名本身作为驱动名。
     *
     * @param string|null $alias null 时使用 defaultVectorStoreAlias()
     */
    public function vectorStoreDriver(?string $alias = null): string
    {
        $alias ??= $this->defaultVectorStoreAlias();
        $section = $this->vectorStoreSection($alias);
        $driver = $section['driver'] ?? $alias;

        return is_string($driver) && $driver !== '' ? $driver : NeuronAiVectorStoreName::FILE;
    }

    /**
     * 指定别名的连接配置段（rag.vector_stores[alias]）。
     *
     * @param string|null $alias null 时使用 defaultVectorStoreAlias()
     *
     * @return array<string, mixed>
     */
    public function vectorStoreSection(?string $alias = null): array
    {
        $alias ??= $this->defaultVectorStoreAlias();
        $section = $this->vectorStores()[$alias] ?? null;

        return is_array($section) ? $section : [];
    }

    /** 是否已在 rag.vector_stores 中声明该别名。 */
    public function hasVectorStoreAlias(string $alias): bool
    {
        return isset($this->vectorStores()[$alias]);
    }

    /**
     * file 驱动根目录（rag.vector_stores[alias].path）。
     *
     * 实际存储路径为 {path}/{knowledgeBase}/；env RAG_FILE_STORE_PATH 可覆盖 path。
     */
    public function fileStorePath(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->vectorStoreSection($alias),
            'path',
            NeuronAiRagEnv::FILE_STORE_PATH,
            sys_get_temp_dir() . '/swoolefy_rag',
        );
    }

    /** RAG 检索默认 Top-K（相似度搜索返回条数）。 */
    public function defaultTopK(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->ragSection(),
            'default_top_k',
            NeuronAiRagEnv::DEFAULT_TOP_K,
            5,
        );
    }

    /** Embedding 模型名（如 text-embedding-3-small）。 */
    public function embeddingModel(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'embedding_model',
            NeuronAiRagEnv::EMBEDDING_MODEL,
            'text-embedding-3-small',
        );
    }

    /**
     * Embedding 输出维度（rag.embedding_dimension）。
     *
     * 默认 1536（text-embedding-3-small）；须与各 vector_stores.*.dimension 一致。
     */
    public function embeddingDimension(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->ragSection(),
            'embedding_dimension',
            NeuronAiRagEnv::EMBEDDING_DIMENSION,
            1536,
        ));
    }

    /**
     * 是否强制多租户隔离（rag.require_tenant_isolation / RAG_REQUIRE_TENANT_ISOLATION）。
     *
     * 为 true 时 RAG 知识库与 Redis ChatHistory 须携带 tenantId（显式参数或 x-tenant-id 头），
     * 否则 fail-fast。生产默认 true；单测可显式关闭。
     */
    public function requireTenantIsolation(): bool
    {
        $fromConfig = $this->ragSection()['require_tenant_isolation'] ?? true;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                ['require_tenant_isolation' => $fromConfig ? '1' : '0'],
                'require_tenant_isolation',
                NeuronAiRagEnv::REQUIRE_TENANT_ISOLATION,
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * 无 API Key 时是否允许 FakeEmbeddings（rag.allow_fake_embeddings / NEURON_ALLOW_FAKE_EMBEDDINGS）。
     *
     * 生产默认 false；单测显式开启。
     */
    public function allowFakeEmbeddings(): bool
    {
        $fromConfig = $this->ragSection()['allow_fake_embeddings'] ?? false;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                ['allow_fake_embeddings' => $fromConfig],
                'allow_fake_embeddings',
                NeuronAiRagEnv::ALLOW_FAKE_EMBEDDINGS,
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * 校验 default_vector_store 别名已在 vector_stores 中声明。
     *
     * 启动健康检查或生产装配时调用，避免运行期才发现别名未配置。
     *
     * @throws \RuntimeException 别名未声明时抛出
     */
    public function assertDefaultVectorStoreDeclared(): void
    {
        $alias = $this->defaultVectorStoreAlias();
        if (!$this->hasVectorStoreAlias($alias)) {
            throw new \RuntimeException(
                "Unknown default_vector_store alias [{$alias}]; declare it under rag.vector_stores",
            );
        }
    }

    /** Meilisearch 向量库配置段。 */
    /** @return array<string, mixed> */
    public function meilisearchSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::MEILISEARCH, $alias);
    }

    /** Meilisearch 服务地址。 */
    public function meilisearchHost(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection($alias),
            'host',
            NeuronAiRagEnv::MEILISEARCH_HOST,
            'http://localhost:7700',
        );
    }

    /** Meilisearch API Key；空字符串时返回 null。 */
    public function meilisearchKey(?string $alias = null): ?string
    {
        $key = ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection($alias),
            'key',
            NeuronAiRagEnv::MEILISEARCH_KEY,
            '',
        );

        return $key !== '' ? $key : null;
    }

    /** Meilisearch embedder 名称。 */
    public function meilisearchEmbedder(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection($alias),
            'embedder',
            NeuronAiRagEnv::MEILISEARCH_EMBEDDER,
            'default',
        );
    }

    /** Meilisearch 向量维度。 */
    public function meilisearchDimension(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->meilisearchSection($alias),
            'dimension',
            NeuronAiRagEnv::MEILISEARCH_DIMENSION,
            1024,
        );
    }

    /** PHPVector 纯 PHP HNSW 向量库配置段。 */
    /** @return array<string, mixed> */
    public function phpvectorSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::PHP_VECTOR, $alias);
    }

    /** PHPVector 本地存储根目录。 */
    public function phpvectorPath(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->phpvectorSection($alias),
            'path',
            NeuronAiRagEnv::PHPVECTOR_PATH,
            sys_get_temp_dir() . '/swoolefy_phpvector',
        );
    }

    /** MariaDB VECTOR 向量库配置段。 */
    /** @return array<string, mixed> */
    public function mariadbSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::MARIADB, $alias);
    }

    /** MariaDB 使用的 database.php 组件别名。 */
    public function mariadbComponent(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->mariadbSection($alias),
            'component',
            NeuronAiRagEnv::MARIADB_COMPONENT,
            'db',
        );
    }

    /** MariaDB 物理表名前缀（实际表名 = {table_name}_{knowledgeBase}）。 */
    public function mariadbTableName(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->mariadbSection($alias),
            'table_name',
            NeuronAiRagEnv::MARIADB_TABLE_NAME,
            'rag_documents',
        );
    }

    /** PostgreSQL + pgvector 向量库配置段。 */
    /** @return array<string, mixed> */
    public function pgvectorSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::PGVECTOR, $alias);
    }

    /** pgvector 使用的 database.php 组件别名。 */
    public function pgvectorComponent(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pgvectorSection($alias),
            'component',
            NeuronAiRagEnv::PGVECTOR_COMPONENT,
            'pg',
        );
    }

    /** pgvector 物理表名前缀（实际表名 = {table_name}_{tenantId}_{knowledgeBase}）。 */
    public function pgvectorTableName(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pgvectorSection($alias),
            'table_name',
            NeuronAiRagEnv::PGVECTOR_TABLE_NAME,
            'rag_documents',
        );
    }

    /** pgvector 向量维度；须与 Embedding 输出一致。 */
    public function pgvectorDimension(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->pgvectorSection($alias),
            'dimension',
            NeuronAiRagEnv::PGVECTOR_DIMENSION,
            1536,
        );
    }

    /** pgvector 距离度量：cosine | l2 | ip。 */
    public function pgvectorMetric(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pgvectorSection($alias),
            'metric',
            NeuronAiRagEnv::PGVECTOR_METRIC,
            'cosine',
        );
    }

    /** Pinecone 向量库配置段。 */
    /** @return array<string, mixed> */
    public function pineconeSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::PINECONE, $alias);
    }

    /** Pinecone API Key。 */
    public function pineconeKey(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection($alias),
            'key',
            NeuronAiRagEnv::PINECONE_KEY,
            '',
        );
    }

    /** Pinecone Index URL。 */
    public function pineconeIndexUrl(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection($alias),
            'index_url',
            NeuronAiRagEnv::PINECONE_INDEX_URL,
            '',
        );
    }

    /** Pinecone API 版本。 */
    public function pineconeVersion(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection($alias),
            'version',
            NeuronAiRagEnv::PINECONE_VERSION,
            '2025-04',
        );
    }

    /** Qdrant 向量库配置段。 */
    /** @return array<string, mixed> */
    public function qdrantSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::QDRANT, $alias);
    }

    /** Qdrant 服务 base URL。 */
    public function qdrantBaseUrl(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->qdrantSection($alias),
            'base_url',
            NeuronAiRagEnv::QDRANT_BASE_URL,
            'http://localhost:6333',
        );
    }

    /** Qdrant API Key；空字符串时返回 null。 */
    public function qdrantKey(?string $alias = null): ?string
    {
        $key = ApplicationConfig::pickStringEnvFirst(
            $this->qdrantSection($alias),
            'key',
            NeuronAiRagEnv::QDRANT_KEY,
            '',
        );

        return $key !== '' ? $key : null;
    }

    /** Qdrant 向量维度。 */
    public function qdrantDimension(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->qdrantSection($alias),
            'dimension',
            NeuronAiRagEnv::QDRANT_DIMENSION,
            1536,
        );
    }

    /** Milvus 向量库配置段。 */
    /** @return array<string, mixed> */
    public function milvusSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::MILVUS, $alias);
    }

    /** Milvus 服务 URI。 */
    public function milvusUri(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection($alias),
            'uri',
            NeuronAiRagEnv::MILVUS_URI,
            'http://localhost:19530',
        );
    }

    /** Milvus 用户名；空字符串时返回 null。 */
    public function milvusUser(?string $alias = null): ?string
    {
        $val = ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection($alias),
            'user',
            NeuronAiRagEnv::MILVUS_USER,
            '',
        );

        return $val !== '' ? $val : null;
    }

    /** Milvus 密码；空字符串时返回 null。 */
    public function milvusPassword(?string $alias = null): ?string
    {
        $val = ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection($alias),
            'password',
            NeuronAiRagEnv::MILVUS_PASSWORD,
            '',
        );

        return $val !== '' ? $val : null;
    }

    /** Milvus Token 鉴权；与 user/password 二选一，空字符串时返回 null。 */
    public function milvusToken(?string $alias = null): ?string
    {
        $val = ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection($alias),
            'token',
            NeuronAiRagEnv::MILVUS_TOKEN,
            '',
        );

        return $val !== '' ? $val : null;
    }

    /** Milvus 数据库名。 */
    public function milvusDbName(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection($alias),
            'db_name',
            NeuronAiRagEnv::MILVUS_DB_NAME,
            'default',
        );
    }

    /** Milvus 向量维度；须与 Embedding 输出一致。 */
    public function milvusDimension(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->milvusSection($alias),
            'dimension',
            NeuronAiRagEnv::MILVUS_DIMENSION,
            1536,
        );
    }

    /**
     * 本地 stdio MCP 子进程最大并发数。
     *
     * 由 McpProcessRunner 在 acquire/release 间限制，防止 Worker 内 stdio 进程过多。
     */
    public function maxLocalProcesses(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->mcpSection(),
            'max_local_processes',
            'MCP_MAX_LOCAL_PROCESSES',
            2,
        ));
    }

    /**
     * 是否允许本地 stdio MCP。
     *
     * 生产默认 false；开发环境可通过 MCP_ALLOW_STDIO=1 开启。
     */
    public function mcpAllowStdio(): bool
    {
        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                $this->mcpSection(),
                'allow_stdio',
                'MCP_ALLOW_STDIO',
                '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /** MCP DB 仓储组件别名（mcp.db_component / MCP_DATABASE_COMPONENT）。 */
    public function mcpDbComponent(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->mcpSection(),
            'db_component',
            NeuronAiMcpEnv::DATABASE_COMPONENT,
            'db',
        );
    }

    /**
     * stdio MCP 允许的 command 白名单。
     *
     * 仅 allow_stdio=true 时生效；不在名单内的 command 会被 McpStdioGuard 拒绝。
     *
     * @return list<string>
     */
    public function mcpStdioCommandAllowlist(): array
    {
        $raw = $this->mcpSection()['stdio_command_allowlist'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $cmd) {
            if (is_string($cmd) && $cmd !== '') {
                $list[] = $cmd;
            }
        }

        return $list;
    }

    /** 组装 MCP stdio 安全守卫（allow_stdio + command allowlist）。 */
    public function mcpStdioGuard(): McpStdioGuard
    {
        return new McpStdioGuard(
            allowStdio: $this->mcpAllowStdio(),
            commandAllowlist: $this->mcpStdioCommandAllowlist(),
        );
    }

    /** 返回 security 配置段（出站 URL 白名单、私网访问等）。 */
    /** @return array<string, mixed> */
    public function securitySection(): array
    {
        return (array) ($this->config['security'] ?? []);
    }

    /**
     * 是否允许访问私网 / loopback 出站地址。
     *
     * 生产默认 false，防止 SSRF；内网调试可显式开启。
     */
    public function allowPrivateOutboundNetworks(): bool
    {
        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                $this->securitySection(),
                'allow_private_networks',
                'NEURON_ALLOW_PRIVATE_NETWORKS',
                '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * 出站 URL host 后缀白名单。
     *
     * @return list<string>
     */
    public function outboundUrlAllowlist(): array
    {
        $raw = $this->securitySection()['outbound_url_allowlist'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $host) {
            if (is_string($host) && $host !== '') {
                $list[] = $host;
            }
        }

        return $list;
    }

    /** 组装出站 URL 安全守卫（白名单 + 私网策略 + fail-closed）。 */
    public function outboundUrlGuard(): OutboundUrlGuard
    {
        return new OutboundUrlGuard(
            allowlistHostSuffixes: $this->outboundUrlAllowlist(),
            allowPrivateNetworks: $this->allowPrivateOutboundNetworks(),
            requireAllowlist: $this->requireOutboundAllowlist(),
        );
    }

    /**
     * 白名单为空时是否 fail-closed。
     *
     * 生产默认 true：未配置 allowlist 时拒绝所有出站，避免误连未知地址。
     */
    public function requireOutboundAllowlist(): bool
    {
        $fromConfig = $this->securitySection()['require_outbound_allowlist'] ?? true;

        return filter_var(
            ApplicationConfig::pickStringEnvFirst(
                $this->securitySection(),
                'require_outbound_allowlist',
                'NEURON_REQUIRE_OUTBOUND_ALLOWLIST',
                $fromConfig ? '1' : '0',
            ),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * Embedding API 实际使用的 baseUri。
     *
     * 解析优先级：env OPENAILIKE_BASE_URI > openailike provider 配置 > OpenAI 默认地址。
     * 与 {@see EmbeddingFactory} 保持一致。
     */
    public function resolvedEmbeddingBaseUri(): string
    {
        // 1. 环境变量优先
        $fromEnv = env(NeuronAiModelEnv::OPENAILIKE_BASE_URI, '');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        // 2. openailike provider 段中的 baseUri / base_uri
        $openAiLike = $this->providerConfig(NeuronAiProviderName::OPENAILIKE);
        if (is_array($openAiLike)) {
            $baseUri = $openAiLike['baseUri'] ?? $openAiLike['base_uri'] ?? '';
            if (is_string($baseUri) && $baseUri !== '') {
                return $baseUri;
            }
        }

        // 3. 回退 OpenAI 官方地址
        return 'https://api.openai.com/v1';
    }

    /**
     * 启动健康检查需校验的出站 URL 列表。
     *
     * 聚合 Embedding baseUri、各 Provider baseUri、各向量库 HTTP 端点，
     * 供启动时探测连通性与白名单配置是否正确。
     *
     * @return array<string, string> label => url
     */
    public function outboundUrlsToValidate(): array
    {
        $urls = [];
        $urls['embedding:base_uri'] = $this->resolvedEmbeddingBaseUri();

        // 收集所有 LLM Provider 的 baseUri
        foreach ($this->aiModelProviders() as $alias => $section) {
            if (!is_array($section)) {
                continue;
            }
            $baseUri = $section['baseUri'] ?? $section['base_uri'] ?? null;
            if (is_string($baseUri) && $baseUri !== '') {
                $urls['provider:' . $alias] = $baseUri;
            }
        }

        // 收集向量库配置中的 HTTP 端点（host / base_url / uri / index_url）
        foreach ($this->vectorStores() as $alias => $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach (['host', 'base_url', 'uri', 'index_url'] as $key) {
                $val = $section[$key] ?? null;
                // 只收集 http(s) 开头的 URL，跳过非 HTTP 配置项
                if (is_string($val) && $val !== '' && str_starts_with($val, 'http')) {
                    $urls['vector_store:' . $alias . ':' . $key] = $val;
                }
            }
        }

        return $urls;
    }

    /**
     * Neuron HTTP Client 实现类型。
     *
     * swoole：Swoole 协程 HTTP Client（生产推荐）；
     * guzzle：Guzzle 同步客户端。
     */
    public function httpClient(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->neuronSection(),
            'http_client',
            NeuronHttpFactory::ENV_HTTP_CLIENT,
            NeuronHttpFactory::CLIENT_SWOOLE,
        );
    }

    /** 默认 LLM Provider 别名（neuron.ai_model_providers 的 key）。 */
    public function defaultProviderName(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->neuronSection(),
            'default_provider',
            'NEURON_DEFAULT_PROVIDER',
            NeuronAiProviderName::ANTHROPIC,
        );
    }

    /**
     * 已声明的 LLM Provider 配置表（neuron.ai_model_providers）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function aiModelProviders(): array
    {
        $providers = $this->neuronSection()['ai_model_providers'] ?? [];

        return is_array($providers) ? $providers : [];
    }

    /**
     * 按别名读取单个 Provider 配置段。
     *
     * @return array<string, mixed>|null 未声明时返回 null
     */
    public function providerConfig(string $alias): ?array
    {
        $config = $this->aiModelProviders()[$alias] ?? null;

        return is_array($config) ? $config : null;
    }

    /** 返回 neuron.provider_fallback 配置段。 */
    /** @return array<string, mixed> */
    public function providerFallbackSection(): array
    {
        $section = $this->neuronSection()['provider_fallback'] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * Provider fallback 备用顺序。
     *
     * 为空时不启用 RouterProvider；非空时 default_provider 永远优先，order 为备用链。
     * env NEURON_PROVIDER_FALLBACK_ORDER 可覆盖（逗号分隔）。
     *
     * @return list<string>
     */
    public function providerFallbackOrder(): array
    {
        // env 优先：支持逗号分隔的 provider 别名列表
        $env = env('NEURON_PROVIDER_FALLBACK_ORDER');
        $raw = is_string($env) && $env !== ''
            ? preg_split('/\s*,\s*/', $env)
            : ($this->providerFallbackSection()['order'] ?? []);

        if (!is_array($raw)) {
            return [];
        }

        // 去重并保持顺序
        $order = [];
        foreach ($raw as $alias) {
            if (is_string($alias) && $alias !== '' && !in_array($alias, $order, true)) {
                $order[] = $alias;
            }
        }

        return $order;
    }

    /**
     * 按驱动类型解析向量库配置段。
     *
     * 解析优先级：
     * 1. 显式传入 $alias；
     * 2. 默认向量库别名且 driver 匹配；
     * 3. vector_stores 中 driver 匹配的第一条；
     * 4. 以驱动名作为别名回退。
     *
     * @return array<string, mixed>
     */
    private function sectionForDriver(string $driver, ?string $alias = null): array
    {
        // 调用方显式指定别名时直接使用
        if ($alias !== null && $alias !== '') {
            return $this->vectorStoreSection($alias);
        }

        // 默认别名对应的 driver 与目标 driver 一致时，使用默认别名配置
        $defaultAlias = $this->defaultVectorStoreAlias();
        if ($this->vectorStoreDriver($defaultAlias) === $driver) {
            return $this->vectorStoreSection($defaultAlias);
        }

        // 遍历 vector_stores，找 driver 字段匹配的第一条
        foreach ($this->vectorStores() as $storeAlias => $section) {
            $sectionDriver = $section['driver'] ?? $storeAlias;
            if ($sectionDriver === $driver) {
                return $section;
            }
        }

        // 最后回退：以驱动名本身作为别名查找
        return $this->vectorStoreSection($driver);
    }
}

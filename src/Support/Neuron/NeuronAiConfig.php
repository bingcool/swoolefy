<?php

declare(strict_types=1);

namespace Swoolefy\Support\Neuron;

use Swoolefy\Support\ApplicationConfig;
use Swoolefy\Support\Mcp\McpStdioGuard;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;
use Swoolefy\Support\Security\OutboundUrlGuard;

/**
 * Neuron AI / RAG / MCP 模块配置加载器。
 *
 * 读取 APP_PATH/config/neuron_ai.php（可选），环境变量优先。
 *
 * RAG 向量库配置结构：
 *   rag.default_vector_store   — 默认使用的向量库别名（env RAG_VECTOR_STORE 可覆盖）
 *   rag.vector_stores[alias]   — 各向量库连接参数；可选 driver 字段，缺省时别名即驱动名
 *
 * 业务指定别名示例：VectorStoreFactory::make($kb, storeAlias: 'milvus_prod')
 */
final class NeuronAiConfig
{
    /** @param array<string, mixed> $config */
    private function __construct(
        private readonly array $config,
    ) {
    }

    public static function load(): self
    {
        return new self(ApplicationConfig::loadPhpConfig('neuron_ai.php'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal 单测 / 脚本注入
     */
    public static function fromArray(array $config): self
    {
        return new self($config);
    }

    /** @return array<string, mixed> */
    public function ragSection(): array
    {
        return (array) ($this->config['rag'] ?? []);
    }

    /** @return array<string, mixed> */
    public function mcpSection(): array
    {
        return (array) ($this->config['mcp'] ?? []);
    }

    /** @return array<string, mixed> */
    public function neuronSection(): array
    {
        return (array) ($this->config['neuron'] ?? []);
    }

    /**
     * 默认向量库别名（rag.default_vector_store）。
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
     * 实际路径为 {path}/{knowledgeBase}/；env RAG_FILE_STORE_PATH 可覆盖 path。
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

    public function defaultTopK(): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->ragSection(),
            'default_top_k',
            NeuronAiRagEnv::DEFAULT_TOP_K,
            5,
        );
    }

    public function embeddingModel(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->ragSection(),
            'embedding_model',
            NeuronAiRagEnv::EMBEDDING_MODEL,
            'text-embedding-3-small',
        );
    }

    /** Embedding 输出维度（rag.embedding_dimension）；默认 1536（text-embedding-3-small）。 */
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

    /** default_vector_store 须在 vector_stores 中声明。 */
    public function assertDefaultVectorStoreDeclared(): void
    {
        $alias = $this->defaultVectorStoreAlias();
        if (!$this->hasVectorStoreAlias($alias)) {
            throw new \RuntimeException(
                "Unknown default_vector_store alias [{$alias}]; declare it under rag.vector_stores",
            );
        }
    }

    /** @return array<string, mixed> */
    public function meilisearchSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::MEILISEARCH, $alias);
    }

    public function meilisearchHost(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection($alias),
            'host',
            NeuronAiRagEnv::MEILISEARCH_HOST,
            'http://localhost:7700',
        );
    }

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

    public function meilisearchEmbedder(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->meilisearchSection($alias),
            'embedder',
            NeuronAiRagEnv::MEILISEARCH_EMBEDDER,
            'default',
        );
    }

    public function meilisearchDimension(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->meilisearchSection($alias),
            'dimension',
            NeuronAiRagEnv::MEILISEARCH_DIMENSION,
            1024,
        );
    }

    /** @return array<string, mixed> */
    public function phpvectorSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::PHP_VECTOR, $alias);
    }

    public function phpvectorPath(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->phpvectorSection($alias),
            'path',
            NeuronAiRagEnv::PHPVECTOR_PATH,
            sys_get_temp_dir() . '/swoolefy_phpvector',
        );
    }

    /** @return array<string, mixed> */
    public function mariadbSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::MARIADB, $alias);
    }

    public function mariadbComponent(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->mariadbSection($alias),
            'component',
            NeuronAiRagEnv::MARIADB_COMPONENT,
            'db',
        );
    }

    public function mariadbTableName(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->mariadbSection($alias),
            'table_name',
            NeuronAiRagEnv::MARIADB_TABLE_NAME,
            'rag_documents',
        );
    }

    /** @return array<string, mixed> */
    public function pineconeSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::PINECONE, $alias);
    }

    public function pineconeKey(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection($alias),
            'key',
            NeuronAiRagEnv::PINECONE_KEY,
            '',
        );
    }

    public function pineconeIndexUrl(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection($alias),
            'index_url',
            NeuronAiRagEnv::PINECONE_INDEX_URL,
            '',
        );
    }

    public function pineconeVersion(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->pineconeSection($alias),
            'version',
            NeuronAiRagEnv::PINECONE_VERSION,
            '2025-04',
        );
    }

    /** @return array<string, mixed> */
    public function qdrantSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::QDRANT, $alias);
    }

    public function qdrantBaseUrl(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->qdrantSection($alias),
            'base_url',
            NeuronAiRagEnv::QDRANT_BASE_URL,
            'http://localhost:6333',
        );
    }

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

    public function qdrantDimension(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->qdrantSection($alias),
            'dimension',
            NeuronAiRagEnv::QDRANT_DIMENSION,
            1536,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function milvusSection(?string $alias = null): array
    {
        return $this->sectionForDriver(NeuronAiVectorStoreName::MILVUS, $alias);
    }

    public function milvusUri(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection($alias),
            'uri',
            NeuronAiRagEnv::MILVUS_URI,
            'http://localhost:19530',
        );
    }

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

    public function milvusDbName(?string $alias = null): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->milvusSection($alias),
            'db_name',
            NeuronAiRagEnv::MILVUS_DB_NAME,
            'default',
        );
    }

    public function milvusDimension(?string $alias = null): int
    {
        return ApplicationConfig::pickIntEnvFirst(
            $this->milvusSection($alias),
            'dimension',
            NeuronAiRagEnv::MILVUS_DIMENSION,
            1536,
        );
    }

    public function maxLocalProcesses(): int
    {
        return max(1, ApplicationConfig::pickIntEnvFirst(
            $this->mcpSection(),
            'max_local_processes',
            'MCP_MAX_LOCAL_PROCESSES',
            2,
        ));
    }

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

    /** @return list<string> */
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

    public function mcpStdioGuard(): McpStdioGuard
    {
        return new McpStdioGuard(
            allowStdio: $this->mcpAllowStdio(),
            commandAllowlist: $this->mcpStdioCommandAllowlist(),
        );
    }

    /** @return array<string, mixed> */
    public function securitySection(): array
    {
        return (array) ($this->config['security'] ?? []);
    }

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

    /** @return list<string> */
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

    public function outboundUrlGuard(): OutboundUrlGuard
    {
        return new OutboundUrlGuard(
            allowlistHostSuffixes: $this->outboundUrlAllowlist(),
            allowPrivateNetworks: $this->allowPrivateOutboundNetworks(),
            requireAllowlist: $this->requireOutboundAllowlist(),
        );
    }

    /** 生产默认 true：allowlist 为空时 fail-closed。 */
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
     * 启动健康检查需校验的出站 URL。
     *
     * @return array<string, string> label => url
     */
    public function outboundUrlsToValidate(): array
    {
        $urls = [];
        foreach ($this->aiModelProviders() as $alias => $section) {
            if (!is_array($section)) {
                continue;
            }
            $baseUri = $section['baseUri'] ?? $section['base_uri'] ?? null;
            if (is_string($baseUri) && $baseUri !== '') {
                $urls['provider:' . $alias] = $baseUri;
            }
        }

        foreach ($this->vectorStores() as $alias => $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach (['host', 'base_url', 'uri', 'index_url'] as $key) {
                $val = $section[$key] ?? null;
                if (is_string($val) && $val !== '' && str_starts_with($val, 'http')) {
                    $urls['vector_store:' . $alias . ':' . $key] = $val;
                }
            }
        }

        return $urls;
    }

    public function httpClient(): string
    {
        return ApplicationConfig::pickStringEnvFirst(
            $this->neuronSection(),
            'http_client',
            NeuronHttpFactory::ENV_HTTP_CLIENT,
            NeuronHttpFactory::CLIENT_SWOOLE,
        );
    }

    /** 默认 Provider 别名（ai_model_providers 的 key）。 */
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
     * @return array<string, array<string, mixed>>
     */
    public function aiModelProviders(): array
    {
        $providers = $this->neuronSection()['ai_model_providers'] ?? [];

        return is_array($providers) ? $providers : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function providerConfig(string $alias): ?array
    {
        $config = $this->aiModelProviders()[$alias] ?? null;

        return is_array($config) ? $config : null;
    }

    /**
     * 取指定驱动的配置段：优先 $alias；否则在 vector_stores 中找 driver 匹配的第一条；
     * 再回退到以驱动名为别名的段。
     *
     * @return array<string, mixed>
     */
    private function sectionForDriver(string $driver, ?string $alias = null): array
    {
        if ($alias !== null && $alias !== '') {
            return $this->vectorStoreSection($alias);
        }

        $defaultAlias = $this->defaultVectorStoreAlias();
        if ($this->vectorStoreDriver($defaultAlias) === $driver) {
            return $this->vectorStoreSection($defaultAlias);
        }

        foreach ($this->vectorStores() as $storeAlias => $section) {
            $sectionDriver = $section['driver'] ?? $storeAlias;
            if ($sectionDriver === $driver) {
                return $section;
            }
        }

        return $this->vectorStoreSection($driver);
    }
}

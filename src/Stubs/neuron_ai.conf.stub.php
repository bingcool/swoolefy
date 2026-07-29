<?php

declare(strict_types=1);

use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\OpenAILike;
use Swoolefy\Support\Neuron\NeuronAiCapabilityEnv;
use Swoolefy\Support\Neuron\NeuronAiMcpEnv;
use Swoolefy\Support\Neuron\NeuronAiModelEnv;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Neuron\NeuronAiRagEnv;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;

/**
 * Neuron AI / RAG / MCP 配置（create 命令从 Stubs 复制到 APP_PATH/Config/neuron_ai.php）
 */
return [
    'rag' => [
        // 默认向量库别名（须对应下方 vector_stores 的 key）。
        // 如果.env配置环境变量 RAG_VECTOR_STORE 则会优先取RAG_VECTOR_STORE配置的vector_store。业务可在 Node/Factory 传入其它别名覆盖默认。
        'default_vector_store' => NeuronAiVectorStoreName::FILE,
        'default_top_k' => 5,
        // 须与 embedding_dimension 及各 vector_stores.*.dimension 一致
        'embedding_model' => 'text-embedding-3-small',
        'embedding_dimension' => (int) env('RAG_EMBEDDING_DIMENSION', 1536),
        // 生产 false；本地单测可 NEURON_ALLOW_FAKE_EMBEDDINGS=1
        'allow_fake_embeddings' => NeuronAiRagEnv::allowFakeEmbeddings(),
        // 生产 true：RAG 知识库与 Redis ChatHistory 按 x-tenant-id 隔离；单测可 RAG_REQUIRE_TENANT_ISOLATION=0
        'require_tenant_isolation' => NeuronAiRagEnv::requireTenantIsolation(),
        // 大批量 RAG 入库可切换为 queue，将标准 RagIngestJob 交给业务队列 producer。
        // consumer 侧收到 Job 后调用配置的 handler，并复用 Support 的 IngestionPipeline。
        'ingestion' => [
            'mode' => env('RAG_INGEST_MODE', 'sync'), // sync | queue
            'queue' => [
                'producer' => [
                    'class' => env('RAG_INGEST_PRODUCER_CLASS', ''),
                    'method' => env('RAG_INGEST_PRODUCER_METHOD', 'push'),
                ],
                'consumer' => [
                    'class' => env('RAG_INGEST_CONSUMER_CLASS', ''),
                    'method' => env('RAG_INGEST_CONSUMER_METHOD', 'handle'),
                ],
            ],
        ],
        // 已声明的向量库表：key = 别名；可选 driver（缺省时别名即驱动类型 NeuronAiVectorStoreName::*）
        // 业务指定：VectorStoreFactory::make($kb, storeAlias: 'milvus') 或节点配置 vectorStore
        'vector_stores' => [
            // file：根目录 path，实际路径为 {path}/{knowledgeBase}/
            NeuronAiVectorStoreName::FILE => [
                'path' => env(NeuronAiRagEnv::FILE_STORE_PATH, '/tmp/swoolefy_rag'),
            ],

            // Meilisearch：indexUid = knowledgeBase
            NeuronAiVectorStoreName::MEILISEARCH => [
                'host' => env(NeuronAiRagEnv::MEILISEARCH_HOST, 'http://localhost:7700'),
                'key' => env(NeuronAiRagEnv::MEILISEARCH_KEY),
                'embedder' => env(NeuronAiRagEnv::MEILISEARCH_EMBEDDER, 'default'),
                'dimension' => (int) env(NeuronAiRagEnv::MEILISEARCH_DIMENSION, 1536),
            ],
            // PHPVector：纯 PHP HNSW，需 composer require neuron-core/php-vector；path/{knowledgeBase}
            NeuronAiVectorStoreName::PHP_VECTOR => [
                'path' => env(NeuronAiRagEnv::PHPVECTOR_PATH, '/tmp/swoolefy_phpvector'),
            ],
            // MariaDB >= 11.7 VECTOR；component 对应 component/database.php 别名；表名 = table_name_{kb}
            NeuronAiVectorStoreName::MARIADB => [
                'component' => env(NeuronAiRagEnv::MARIADB_COMPONENT, 'db'),
                'table_name' => env(NeuronAiRagEnv::MARIADB_TABLE_NAME, 'rag_documents'),
            ],
            // PostgreSQL + pgvector；component 对应 component/database.php 别名；表名 = table_name_{kb}
            NeuronAiVectorStoreName::PGVECTOR => [
                'component' => env(NeuronAiRagEnv::PGVECTOR_COMPONENT, 'pg'),
                'table_name' => env(NeuronAiRagEnv::PGVECTOR_TABLE_NAME, 'rag_documents'),
                'dimension' => (int) env(NeuronAiRagEnv::PGVECTOR_DIMENSION, 1536),
                'metric' => env(NeuronAiRagEnv::PGVECTOR_METRIC, 'cosine'), // cosine | l2 | ip
            ],
            // Pinecone：全局 index_url，knowledgeBase 映射为 namespace
            NeuronAiVectorStoreName::PINECONE => [
                'key' => env(NeuronAiRagEnv::PINECONE_KEY),
                'index_url' => env(NeuronAiRagEnv::PINECONE_INDEX_URL),
                'version' => env(NeuronAiRagEnv::PINECONE_VERSION, '2025-04'),
            ],
            // Qdrant：collection URL = {base_url}/collections/{knowledgeBase}/
            NeuronAiVectorStoreName::QDRANT => [
                'base_url' => env(NeuronAiRagEnv::QDRANT_BASE_URL, 'http://localhost:6333'),
                'key' => env(NeuronAiRagEnv::QDRANT_KEY),
                'dimension' => (int) env(NeuronAiRagEnv::QDRANT_DIMENSION, 1536),
            ],
            // 阿里云向量检索服务 Milvus 版 / 自建 Milvus 2.x
            // 依赖：mathsgod/milvus-client-php；实现：Swoolefy\Support\Rag\Store\MilvusVectorStore
            // knowledgeBase => 独立 Collection；首次写入自动建表 + COSINE/AUTOINDEX
            // 鉴权：user+password（阿里云常见）或 token（二选一）；dimension 须与 embedding_model 一致
            NeuronAiVectorStoreName::MILVUS => [
                // 阿里云示例：http://c-xxxx.milvus.aliyuncs.com:19530
                'uri' => env(NeuronAiRagEnv::MILVUS_URI, 'http://localhost:19530'),
                'user' => env(NeuronAiRagEnv::MILVUS_USER),
                'password' => env(NeuronAiRagEnv::MILVUS_PASSWORD),
                // 与 user/password 二选一；同时配置时 client 优先使用 token
                'token' => env(NeuronAiRagEnv::MILVUS_TOKEN),
                'db_name' => env(NeuronAiRagEnv::MILVUS_DB_NAME, 'default'),
                'dimension' => (int) env(NeuronAiRagEnv::MILVUS_DIMENSION, 1536),
            ],
        ]
    ],
    'mcp' => [
        'max_local_processes' => 2,
        // 生产默认禁用 stdio MCP；开发可 MCP_ALLOW_STDIO=1 + allowlist
        'allow_stdio' => filter_var(env('MCP_ALLOW_STDIO', '0'), FILTER_VALIDATE_BOOLEAN),
        // 对应 Config/component/database.php 组件别名
        'db_component' => env(NeuronAiMcpEnv::DATABASE_COMPONENT, 'db'),
        'stdio_command_allowlist' => ['npx', 'node', 'uvx'],
    ],
    'security' => [
        // 出站 URL host 后缀白名单；空数组时仅拦截私网/loopback
        'outbound_url_allowlist' => [
            'api.openai.com',
            'api.deepseek.com',
            'localhost',
        ],
        'allow_private_networks' => filter_var(env('NEURON_ALLOW_PRIVATE_NETWORKS', '0'), FILTER_VALIDATE_BOOLEAN),
    ],
    'capability' => [
        // 默认关闭：关闭时 NeuronFactory 继续使用旧的 McpFactory::tools() 全量挂载逻辑。
        'enabled' => env(NeuronAiCapabilityEnv::ENABLED, false),
        // 每轮动态筛选的普通候选数量；pinnedTools 不占这个 quota。
        'default_top_k' => (int) env(NeuronAiCapabilityEnv::DEFAULT_TOP_K, 12),
        // Phase 3 使用 policy + tag 轻量 Resolver；embedding / pgvector 留到后续阶段。
        'resolver' => env(NeuronAiCapabilityEnv::RESOLVER, 'policy,tag'),
        'index_store' => env(NeuronAiCapabilityEnv::INDEX_STORE, 'memory'),
        // 开启 Capability 后，Agent boot 时从声明的 MCP server 同步轻量 tool descriptor。
        'mcp_sync_on_boot' => env(NeuronAiCapabilityEnv::MCP_SYNC_ON_BOOT, true),
        // 注入给 LLM schema 的最大工具数兜底，避免异常配置导致 token 暴涨。
        'max_schema_tools' => (int) env(NeuronAiCapabilityEnv::MAX_SCHEMA_TOOLS, 20),
        'debug' => env(NeuronAiCapabilityEnv::DEBUG, false),
        // false 表示 CapabilityCenter 出错时 fail-open 回退旧 MCP 链路；生产严格模式可设 true。
        'fail_closed' => env(NeuronAiCapabilityEnv::FAIL_CLOSED, false),
    ],
    // 本地 SKILL.md 根目录；空则默认 APP_PATH/Skills 与 ROOT_PATH/Skills。
    // 本地 SKILL.md 扫描根目录。加载行为由 agentOptions 控制：
    //   skills       — 要启用的 skill 名列表，如 ['weather-ops']
    //   skillsMode   — tool（默认，挂 skill_*，正文按需）| inline（正文进 instructions）| both
    //   skillsPrompt — tool：是否注入 AVAILABLE-SKILLS 短列表；inline/both：是否注入正文（默认 true）
    'skills' => [
        'paths' => [
            // APP_PATH . '/Skills',
        ],
    ],
    'neuron' => [
        'http_client' => 'swoole', // swoole | guzzle
        // Agent 未覆盖 provider() 时使用的默认别名
        'default_provider' => NeuronAiProviderName::DEEPSEEK,
        // LLM Provider 运行时故障转移：仅对网络/超时、429、5xx 等瞬时错误生效。
        // stream() 仅在首个 chunk 输出前失败时可切换；输出开始后的错误会原样抛出。
        // default_provider 永远优先；order 仅声明备用 provider，非空时启用 fallback。
        'provider_fallback' => [
            // 逗号环境变量 NEURON_PROVIDER_FALLBACK_ORDER 可覆盖，例如：openai,anthropic
            'order' => [
                NeuronAiProviderName::OPENAI,
                NeuronAiProviderName::ANTHROPIC,
            ],
        ],
        // 除 provider 外，键名与对应 Provider 构造函数参数一致
        'ai_model_providers' => [
            NeuronAiProviderName::ANTHROPIC => [
                'provider' => Anthropic::class,
                'key' => env(NeuronAiModelEnv::ANTHROPIC_API_KEY),
                'model' => env(NeuronAiModelEnv::ANTHROPIC_MODEL, 'claude-sonnet-4-20250514'),
            ],
            NeuronAiProviderName::OPENAI => [
                'provider' => OpenAI::class,
                'key' => env(NeuronAiModelEnv::OPENAI_API_KEY),
                'model' => env(NeuronAiModelEnv::OPENAI_MODEL, 'gpt-4o-mini'),
                'parameters' => [],
                'strict_response' => false,
            ],
            NeuronAiProviderName::OPENAI_RESPONSES => [
                'provider' => OpenAIResponses::class,
                'key' => env(NeuronAiModelEnv::OPENAI_API_KEY),
                'model' => env(NeuronAiModelEnv::OPENAI_RESPONSES_MODEL, 'gpt-4.1'),
                'parameters' => [],
                'strict_response' => false,
            ],
            NeuronAiProviderName::OPENAILIKE => [
                'provider' => OpenAILike::class,
                'baseUri' => env(NeuronAiModelEnv::OPENAILIKE_BASE_URI, 'https://api.together.xyz/v1'),
                'key' => env(NeuronAiModelEnv::OPENAILIKE_API_KEY),
                'model' => env(NeuronAiModelEnv::OPENAILIKE_MODEL, 'gpt-4o-mini'),
                'parameters' => [],
                'strict_response' => false,
            ],
            NeuronAiProviderName::DEEPSEEK => [
                'provider' => Deepseek::class,
                'key' => env(NeuronAiModelEnv::DEEPSEEK_API_KEY),
                'model' => env(NeuronAiModelEnv::DEEPSEEK_MODEL, 'deepseek-chat'),
                'parameters' => [],
                'strict_response' => false,
            ],
        ],
    ],
];

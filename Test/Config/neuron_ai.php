<?php

declare(strict_types=1);

use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\OpenAILike;
use Swoolefy\Support\Neuron\NeuronAiModelEnv;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Neuron\NeuronAiRagEnv;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;

/**
 * Neuron AI / RAG / MCP 配置（复制到 APP_PATH/config/neuron_ai.php）
 */
return [
    'rag' => [
        // 当前启用的向量库驱动（NeuronAiVectorStoreName::*）；环境变量 RAG_VECTOR_STORE 可覆盖
        'vector_store' => NeuronAiVectorStoreName::FILE,
        // file 模式根目录：实际路径为 {file_store_path}/{knowledgeBase}/
        'file_store_path' => '/tmp/swoolefy_rag',
        'default_top_k' => 5,
        // 须与下方各向量库 dimension 一致（如 text-embedding-3-small => 1536）
        'embedding_model' => 'text-embedding-3-small',

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
    ],
    'mcp' => [
        'max_local_processes' => 2,
    ],
    'neuron' => [
        'http_client' => 'swoole', // swoole | guzzle
        // Agent 未覆盖 provider() 时使用的默认别名
        'default_provider' => NeuronAiProviderName::DEEPSEEK,
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

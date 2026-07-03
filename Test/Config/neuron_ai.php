<?php

declare(strict_types=1);

/**
 * Neuron AI / RAG / MCP 配置（复制到 APP_PATH/config/neuron_ai.php）
 */
return [
    'rag' => [
        'vector_store' => 'file', // file | meilisearch
        'file_store_path' => '/tmp/swoolefy_rag',
        'default_top_k' => 5,
        'embedding_model' => 'text-embedding-3-small',
    ],
    'mcp' => [
        'max_local_processes' => 2,
    ],
    'neuron' => [
        'http_client' => 'swoole', // swoole | guzzle
    ],
];

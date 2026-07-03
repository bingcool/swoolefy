<?php

declare(strict_types=1);

use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\OpenAILike;
use Swoolefy\Support\Neuron\NeuronAiModelEnv;
use Swoolefy\Support\Neuron\NeuronAiProviderName;

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
        // Agent 未覆盖 provider() 时使用的默认别名
        'default_provider' => NeuronAiProviderName::ANTHROPIC,
        // 除 provider 外，键名与对应 Provider 构造函数参数一致
        'ai_model_providers' => [
            NeuronAiProviderName::ANTHROPIC => [
                'provider' => Anthropic::class,
                'key' => env(NeuronAiModelEnv::ANTHROPIC_API_KEY),
                'model' => env(NeuronAiModelEnv::ANTHROPIC_MODEL),
            ],
            NeuronAiProviderName::OPENAI => [
                'provider' => OpenAI::class,
                'key' => env(NeuronAiModelEnv::OPENAI_API_KEY),
                'model' => env(NeuronAiModelEnv::OPENAI_MODEL),
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
                'model' => env(NeuronAiModelEnv::OPENAILIKE_MODEL),
                'parameters' => [],
                'strict_response' => false,
            ],
            NeuronAiProviderName::DEEPSEEK => [
                'provider' => Deepseek::class,
                'key' => env(NeuronAiModelEnv::DEEPSEEK_API_KEY),
                'model' => env(NeuronAiModelEnv::DEEPSEEK_MODEL),
                'parameters' => [],
                'strict_response' => false,
            ],
        ],
    ],
];

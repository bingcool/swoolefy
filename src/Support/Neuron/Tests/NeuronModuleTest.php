<?php

declare(strict_types=1);

/**
 * Neuron 模块回归测试。
 *
 * 覆盖：NeuronAiConfig、Provider 工厂、MemoryFactory 回退、EmbeddingFactory Fake、
 * NeuronHttpFactory CLI 回退、NeuronFactory agentFactory 注入。
 *
 * 运行：php src/Support/Neuron/Tests/NeuronModuleTest.php
 * 或：composer test:neuron
 */

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;
use Swoolefy\Support\Neuron\Memory\ChatHistoryArchiveInterface;
use Swoolefy\Support\Neuron\Memory\HotChatHistoryInterface;
use Swoolefy\Support\Neuron\Memory\MemoryFactory;
use Swoolefy\Support\Neuron\Memory\MemoryFactoryInterface;
use Swoolefy\Support\Neuron\Memory\SqlChatHistoryArchive;
use Swoolefy\Support\Neuron\NeuronAiConfig;
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

function sampleNeuronConfig(): NeuronAiConfig
{
    return NeuronAiConfig::fromArray([
        'rag' => [
            'vector_store' => NeuronAiVectorStoreName::FILE,
            'file_store_path' => '/tmp/swoolefy_neuron_test',
            'default_top_k' => 3,
            'embedding_model' => 'text-embedding-3-small',
        ],
        'mcp' => [
            'max_local_processes' => 3,
        ],
        'neuron' => [
            'http_client' => NeuronHttpFactory::CLIENT_GUZZLE,
            'default_provider' => NeuronAiProviderName::OPENAI,
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
    assertTrue($config->vectorStoreDriver() === NeuronAiVectorStoreName::FILE, 'vector store');
    assertTrue($config->defaultTopK() === 3, 'top k');
    assertTrue($config->embeddingModel() === 'text-embedding-3-small', 'embedding model');
    assertTrue($config->defaultProviderName() === NeuronAiProviderName::OPENAI, 'default provider');
    assertTrue($config->httpClient() === NeuronHttpFactory::CLIENT_GUZZLE, 'http client');
    assertTrue($config->maxLocalProcesses() === 3, 'mcp processes');
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

function testMemoryFactoryFallsBackToInMemory(): void
{
    $factory = new MemoryFactory();
    assertTrue($factory instanceof MemoryFactoryInterface, 'implements MemoryFactoryInterface');
    $history = $factory->forThread('thread-1', 1000);
    assertTrue($history instanceof InMemoryChatHistory, 'in-memory fallback without redis');
}

function testMemoryInterfacesAreImplemented(): void
{
    assertTrue(
        is_subclass_of(MemoryFactory::class, MemoryFactoryInterface::class),
        'MemoryFactory implements factory interface',
    );
    assertTrue(
        is_subclass_of(\Swoolefy\Support\Neuron\Memory\RedisChatHistory::class, HotChatHistoryInterface::class),
        'RedisChatHistory implements hot history interface',
    );
    assertTrue(
        is_subclass_of(SqlChatHistoryArchive::class, ChatHistoryArchiveInterface::class),
        'SqlChatHistoryArchive implements archive interface',
    );
}

function testEmbeddingFactoryWithoutApiKeyUsesFake(): void
{
    $prev = getenv('OPENAI_API_KEY');
    putenv('OPENAI_API_KEY');
    try {
        $embedder = (new EmbeddingFactory())->make();
        assertTrue($embedder instanceof EmbeddingsProviderInterface, 'embeddings interface');
        assertTrue($embedder instanceof FakeEmbeddingsProvider, 'fake without key');
    } finally {
        if ($prev === false) {
            putenv('OPENAI_API_KEY');
        } else {
            putenv('OPENAI_API_KEY=' . $prev);
        }
    }
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
        memoryFactory: new MemoryFactory(),
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
        memoryFactory: new MemoryFactory(),
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
    'provider unknown alias' => 'testProviderFactoryUnknownAliasThrows',
    'memory in-memory fallback' => 'testMemoryFactoryFallsBackToInMemory',
    'memory interfaces' => 'testMemoryInterfacesAreImplemented',
    'embedding fake without key' => 'testEmbeddingFactoryWithoutApiKeyUsesFake',
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

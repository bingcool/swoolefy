<?php

declare(strict_types=1);

/**
 * RAG 模块回归测试。
 *
 * 覆盖：VectorStoreFactory file 模式、IngestionPipeline、RetrievalService、
 * RagFactory 一致性、MilvusVectorStore::make 参数装配（不连真实服务）。
 *
 * 运行：php src/Support/Rag/Tests/RagModuleTest.php
 * 或：composer test:rag
 */

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Rag\Store\MilvusVectorStore;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeRagFactory(?string $basePath = null): RagFactory
{
    $path = $basePath ?? (sys_get_temp_dir() . '/swoolefy_rag_module_' . getmypid());
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'default_top_k' => 5,
            'embedding_model' => 'text-embedding-3-small',
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => ['path' => $path],
            ],
        ],
    ]);

    return new RagFactory(new VectorStoreFactory($config, $path), new EmbeddingFactory());
}

function testVectorStoreFactoryFileMode(): void
{
    $path = sys_get_temp_dir() . '/swoolefy_rag_vs_' . getmypid();
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'default_top_k' => 4,
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => ['path' => $path],
            ],
        ],
    ]);
    $factory = new VectorStoreFactory($config, $path);

    assertTrue($factory->storeType() === NeuronAiVectorStoreName::FILE, 'store type file');
    assertTrue($factory->storeAlias() === NeuronAiVectorStoreName::FILE, 'store alias file');
    $store = $factory->make('kb_demo');
    assertTrue($store instanceof FileVectorStore, 'file vector store');
}

function testVectorStoreSanitizesKnowledgeBaseName(): void
{
    $path = sys_get_temp_dir() . '/swoolefy_rag_sanitize_' . getmypid();
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => ['path' => $path],
            ],
        ],
    ]);
    $factory = new VectorStoreFactory($config, $path);
    $store = $factory->make('../evil/kb name!');
    assertTrue($store instanceof FileVectorStore, 'sanitized store created');
}

function testVectorStoreCustomAliasWithDriver(): void
{
    $path = sys_get_temp_dir() . '/swoolefy_rag_alias_' . getmypid();
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => 'primary_file',
            'vector_stores' => [
                'primary_file' => [
                    'driver' => NeuronAiVectorStoreName::FILE,
                    'path' => $path . '/primary',
                ],
                'milvus_prod' => [
                    'driver' => NeuronAiVectorStoreName::MILVUS,
                    'uri' => 'http://milvus.example:19530',
                    'user' => 'root',
                    'password' => 'secret',
                    'dimension' => 1024,
                ],
            ],
        ],
    ]);

    assertTrue($config->defaultVectorStoreAlias() === 'primary_file', 'default alias');
    assertTrue($config->vectorStoreDriver() === NeuronAiVectorStoreName::FILE, 'default driver');
    assertTrue($config->vectorStoreDriver('milvus_prod') === NeuronAiVectorStoreName::MILVUS, 'alias driver');
    assertTrue($config->milvusUri('milvus_prod') === 'http://milvus.example:19530', 'alias uri');

    $factory = new VectorStoreFactory($config);
    $store = $factory->make('kb1');
    assertTrue($store instanceof FileVectorStore, 'default alias uses file');
    $store2 = $factory->make('kb1', storeAlias: 'primary_file');
    assertTrue($store2 instanceof FileVectorStore, 'explicit alias file');
}

function testIngestTextsAndRetrieve(): void
{
    $rag = makeRagFactory();
    $kb = 'product_kb_' . getmypid();

    $result = $rag->ingestionPipeline()->ingestTexts($kb, [
        'Swoolefy is a PHP coroutine framework based on Swoole.',
        'RAG retrieves relevant documents for the LLM.',
    ]);

    assertTrue($result->documentCount === 2, 'ingested 2 docs');
    assertTrue($result->knowledgeBase === $kb, 'kb name');

    $hits = (new RetrievalService($rag))->retrieve($kb, 'coroutine framework', 3);
    assertTrue(count($hits) >= 1, 'retrieval finds content');
    assertTrue(isset($hits[0]['content'], $hits[0]['score']), 'hit shape');
}

function testIngestEmptyReturnsZero(): void
{
    $rag = makeRagFactory();
    $result = $rag->ingestionPipeline()->ingestTexts('empty_kb', ['', '  ', "\n"]);
    assertTrue($result->documentCount === 0, 'empty texts skipped');
}

function testIngestDocumentsDirectly(): void
{
    $rag = makeRagFactory();
    $kb = 'docs_kb_' . getmypid();
    $docs = [new Document('Neuron AI structured output'), new Document('Milvus vector search')];
    $result = $rag->ingestionPipeline()->ingest($kb, $docs);
    assertTrue($result->documentCount === 2, 'document ingest count');
}

function testRagFactoryRetrievalInterface(): void
{
    $rag = makeRagFactory();
    $retrieval = $rag->retrieval('any_kb', 2);
    assertTrue($retrieval !== null, 'similarity retrieval built');
    assertTrue($rag->embeddings() instanceof \NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface, 'embeddings');
}

function testMilvusVectorStoreMakeParams(): void
{
    $store = MilvusVectorStore::make([
        'uri' => 'http://127.0.0.1:19530',
        'user' => 'root',
        'password' => 'pass',
        'db_name' => 'default',
        'collection_name' => 'kb_test',
        'dimension' => 1536,
        'top_k' => 3,
        'auto_create_collection' => false,
    ]);

    assertTrue($store instanceof MilvusVectorStore, 'milvus store');
    assertTrue($store->getCollectionName() === 'kb_test', 'collection name');
}

function testMilvusConfigSectionInNeuronAiConfig(): void
{
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::MILVUS,
            'vector_stores' => [
                NeuronAiVectorStoreName::MILVUS => [
                    'uri' => 'http://c-demo.milvus.aliyuncs.com:19530',
                    'user' => 'root',
                    'password' => 'secret',
                    'db_name' => 'rag',
                    'dimension' => 1024,
                ],
            ],
        ],
    ]);

    assertTrue($config->defaultVectorStoreAlias() === NeuronAiVectorStoreName::MILVUS, 'alias');
    assertTrue($config->vectorStoreDriver() === NeuronAiVectorStoreName::MILVUS, 'driver');
    assertTrue($config->milvusUri() === 'http://c-demo.milvus.aliyuncs.com:19530', 'uri');
    assertTrue($config->milvusUser() === 'root', 'user');
    assertTrue($config->milvusPassword() === 'secret', 'password');
    assertTrue($config->milvusDbName() === 'rag', 'db');
    assertTrue($config->milvusDimension() === 1024, 'dimension');
}

$tests = [
    'vector store file mode' => 'testVectorStoreFactoryFileMode',
    'sanitize knowledge base' => 'testVectorStoreSanitizesKnowledgeBaseName',
    'custom alias with driver' => 'testVectorStoreCustomAliasWithDriver',
    'ingest texts + retrieve' => 'testIngestTextsAndRetrieve',
    'ingest empty' => 'testIngestEmptyReturnsZero',
    'ingest documents' => 'testIngestDocumentsDirectly',
    'rag factory retrieval' => 'testRagFactoryRetrievalInterface',
    'milvus make params' => 'testMilvusVectorStoreMakeParams',
    'milvus config section' => 'testMilvusConfigSectionInNeuronAiConfig',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} RAG module tests passed.\n";

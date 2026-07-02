<?php

declare(strict_types=1);

/**
 * RAG 文档入库 CLI。
 *
 * 用法：
 *   php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --path=/data/docs
 *   php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --text="hello world"
 *
 * 环境：OPENAI_API_KEY（embed）、RAG_VECTOR_STORE、RAG_FILE_STORE_PATH
 */

use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Ingestion\FileDocumentLoader;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Ingestion\StringDocumentLoader;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function parseArgs(array $argv): array
{
    $options = ['kb' => 'default', 'path' => null, 'text' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--kb=')) {
            $options['kb'] = substr($arg, 5);
        } elseif (str_starts_with($arg, '--path=')) {
            $options['path'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--text=')) {
            $options['text'] = substr($arg, 7);
        }
    }

    return $options;
}

$opts = parseArgs($argv);
$kb = (string) $opts['kb'];

$ragFactory = new RagFactory(VectorStoreFactory::fromEnv(), new EmbeddingFactory());
$pipeline = new IngestionPipeline($ragFactory);

if (is_string($opts['path']) && $opts['path'] !== '') {
    $documents = FileDocumentLoader::fromPath($opts['path']);
    if ($documents === []) {
        fwrite(STDERR, "No documents found at {$opts['path']}\n");
        exit(1);
    }
    $result = $pipeline->ingest($kb, $documents);
} elseif (is_string($opts['text']) && $opts['text'] !== '') {
    $result = $pipeline->ingest($kb, StringDocumentLoader::fromTexts([$opts['text']]));
} else {
    fwrite(STDERR, "Usage: ingest_documents.php --kb=NAME (--path=DIR|FILE | --text=STRING)\n");
    exit(1);
}

echo json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

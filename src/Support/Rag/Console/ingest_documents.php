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
 * RAG 文档入库 CLI。
 *
 * 用法：
 *   php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --path=/data/docs --tenant-id=tenant_a
 *   php src/Support/Rag/Console/ingest_documents.php --kb=product_kb --text="hello world" --tenant-id=tenant_a
 *
 * 环境：OPENAI_API_KEY（embed）、RAG_VECTOR_STORE、RAG_FILE_STORE_PATH（覆盖 vector_stores.file.path）
 *       NEURON_TENANT_ID（可选，等同 --tenant-id）
 */

use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiRagEnv;
use Swoolefy\Support\Neuron\NeuronAiVectorStoreName;
use Swoolefy\Support\Rag\Factory\RagFactory;
use Swoolefy\Support\Rag\Factory\VectorStoreFactory;
use Swoolefy\Support\Rag\Ingestion\FileDocumentLoader;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;
use Swoolefy\Support\Rag\Ingestion\StringDocumentLoader;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function parseArgs(array $argv): array
{
    $options = ['kb' => 'default', 'path' => null, 'text' => null, 'tenant-id' => null];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--kb=')) {
            $options['kb'] = substr($arg, 5);
        } elseif (str_starts_with($arg, '--path=')) {
            $options['path'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--text=')) {
            $options['text'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--tenant-id=')) {
            $options['tenant-id'] = substr($arg, 12);
        }
    }

    return $options;
}

$opts = parseArgs($argv);
$kb = (string) $opts['kb'];
$tenantId = is_string($opts['tenant-id'] ?? null) && $opts['tenant-id'] !== ''
    ? (string) $opts['tenant-id']
    : (getenv('NEURON_TENANT_ID') ?: null);

$config = NeuronAiConfig::load();
if ($config->vectorStores() === []) {
    $basePath = env('RAG_FILE_STORE_PATH', null) ?? sys_get_temp_dir() . '/swoolefy_rag';
    $config = NeuronAiConfig::fromArray([
        'rag' => [
            'default_vector_store' => NeuronAiVectorStoreName::FILE,
            'embedding_dimension' => 1536,
            'allow_fake_embeddings' => NeuronAiRagEnv::allowFakeEmbeddings(),
            'require_tenant_isolation' => NeuronAiRagEnv::requireTenantIsolation(false),
            'vector_stores' => [
                NeuronAiVectorStoreName::FILE => ['path' => $basePath],
            ],
        ],
    ]);
}

$ragFactory = new RagFactory(new VectorStoreFactory($config), new EmbeddingFactory($config));
$pipeline = new IngestionPipeline($ragFactory);

if (is_string($opts['path']) && $opts['path'] !== '') {
    $documents = FileDocumentLoader::fromPath($opts['path']);
    if ($documents === []) {
        fwrite(STDERR, "No documents found at {$opts['path']}\n");
        exit(1);
    }
    $result = $pipeline->ingest($kb, $documents, tenantId: is_string($tenantId) ? $tenantId : null);
} elseif (is_string($opts['text']) && $opts['text'] !== '') {
    $result = $pipeline->ingest($kb, StringDocumentLoader::fromTexts([$opts['text']]), tenantId: is_string($tenantId) ? $tenantId : null);
} else {
    fwrite(STDERR, "Usage: ingest_documents.php --kb=NAME (--path=DIR|FILE | --text=STRING) [--tenant-id=TENANT]\n");
    exit(1);
}

echo json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

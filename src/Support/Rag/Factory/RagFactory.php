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

namespace Swoolefy\Support\Rag\Factory;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Swoolefy\Support\Neuron\Embedding\EmbeddingFactory;
use Swoolefy\Support\Rag\Ingestion\IngestionPipeline;

/**
 * RAG 组件工厂 —— 统一组装 VectorStore + Embeddings + SimilarityRetrieval + Ingestion。
 *
 * 设计原则：
 * - 同一 RagFactory 实例保证 embed 与 store 配置一致（避免入库/检索用不同模型）
 * - 各 Node / Service 通过构造函数注入本工厂，便于单测替换 FakeEmbeddings
 *
 * @see docs/SwoolefyAI.md §4.10
 */
final class RagFactory
{
    public function __construct(
        private readonly VectorStoreFactory $vectorStoreFactory,
        private readonly EmbeddingFactory $embeddingFactory,
    ) {
    }

    /**
     * 按知识库名获取 VectorStore。
     *
     * @param string|null $storeAlias 向量库别名（rag.vector_stores 的 key）；null 用 default_vector_store
     */
    public function vectorStore(
        string $knowledgeBase,
        ?int $topK = null,
        ?string $storeAlias = null,
        ?string $tenantId = null,
    ): VectorStoreInterface {
        return $this->vectorStoreFactory->make($knowledgeBase, $topK, $storeAlias, $tenantId);
    }

    /** 获取 Embeddings 提供者（无 Key 时 fail-fast；单测可 rag.allow_fake_embeddings）。 */
    public function embeddings(): EmbeddingsProviderInterface
    {
        return $this->embeddingFactory->make();
    }

    /**
     * 构建 SimilarityRetrieval —— RagRetrieveNode / RetrievalTool 共用。
     *
     * query 向量化 → vectorStore 相似度搜索 → TopK Document
     *
     * @param string|null $storeAlias 向量库别名；null 用 default_vector_store
     */
    public function retrieval(
        string $knowledgeBase,
        ?int $topK = null,
        ?string $storeAlias = null,
        ?string $tenantId = null,
    ): RetrievalInterface {
        return new SimilarityRetrieval(
            $this->vectorStore($knowledgeBase, $topK, $storeAlias, $tenantId),
            $this->embeddings(),
        );
    }

    /** 文档入库管线（embed → addDocuments）。 */
    public function ingestionPipeline(): IngestionPipeline
    {
        return new IngestionPipeline($this);
    }
}

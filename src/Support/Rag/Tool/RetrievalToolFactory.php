<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Tool;

use NeuronAI\Tools\Toolkits\RetrievalTool;
use Swoolefy\Support\Rag\Factory\RagFactory;

/**
 * Agent 按需检索 Tool 工厂 —— RAG 模式 C。
 *
 * 与 RagRetrieveNode（模式 B）区别：
 * - RagRetrieveNode：Workflow 强制检索，结果写 state.data.retrievedDocs
 * - RetrievalTool：LLM 自主决定何时调用 context_retrieval，结果作为 Tool 返回值
 *
 * 用法：在 Agent::tools() 或 NeuronFactory 挂载：
 *   $factory->make('product_kb', topK: 5)
 *
 * @see docs/swoolefyAI.md §4.10.4 模式 C
 */
final class RetrievalToolFactory
{
    public function __construct(
        private readonly RagFactory $ragFactory,
    ) {
    }

    /**
     * 构建绑定指定知识库的 Neuron RetrievalTool。
     *
     * @param string      $knowledgeBase 知识库名称
     * @param int|null    $topK          检索 TopK，null 使用 VectorStore 默认值
     * @param string|null $storeAlias    向量库别名；null 用 default_vector_store
     */
    public function make(string $knowledgeBase, ?int $topK = null, ?string $storeAlias = null): RetrievalTool
    {
        return new RetrievalTool(
            $this->ragFactory->retrieval($knowledgeBase, $topK, $storeAlias),
        );
    }
}

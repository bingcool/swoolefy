<?php

declare(strict_types=1);

namespace Test\Module\Rag\Workflow;

use Swoolefy\Support\Rag\Node\RagRetrieveNode;
use Swoolefy\Support\Rag\Retrieval\RetrievalService;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Test\Module\Rag\RagService;

/**
 * RAG 问答工作流（workflowId: rag_qa，version: 1.0.0）。
 *
 * DAG：
 *
 *   retrieve（RagRetrieveNode）
 *      │  从 vector store 检索，写入 state.retrievedDocs
 *      ▼
 *   answer（ClosureNode）
 *      │  抽取式拼接命中片段 → state.answer（离线可用，不依赖 LLM）
 *      ▼
 *   completed
 *
 * 入参 state：
 *   - question / query：查询文本（queryKey 默认 question，兼容 query）
 *   - knowledgeBase：可选，默认 demo_kb
 *
 * 节点配置可通过 definition() 参数覆盖 knowledgeBase、topK、vectorStore 别名。
 *
 * @see Test\Module\Rag\README.md
 */
final class RagQaWorkflow
{
    /**
     * @param RetrievalService $retrievalService 检索服务
     * @param string           $knowledgeBase    默认知识库
     * @param int              $topK             检索条数
     * @param string|null      $storeAlias       向量库别名；null 用 default_vector_store
     */
    public static function definition(
        RetrievalService $retrievalService,
        string $knowledgeBase = RagService::DEFAULT_KNOWLEDGE_BASE,
        int $topK = 5,
        ?string $storeAlias = null,
    ): WorkflowDefinition {
        $retrieveConfig = [
            'knowledgeBase' => $knowledgeBase,
            'queryKey' => 'question',
            'outputKey' => 'retrievedDocs',
            'topK' => $topK,
        ];
        if ($storeAlias !== null && $storeAlias !== '') {
            $retrieveConfig['vectorStore'] = $storeAlias;
        }

        return WorkflowDefinition::create('rag_qa', '1.0.0')
            ->metadata([
                'owner' => 'rag-team',
                'description' => 'Retrieve documents then build extractive answer',
            ])
            ->addNode('retrieve', new RagRetrieveNode('retrieve', $retrieveConfig, $retrievalService))
            // 抽取式回答：不依赖 LLM，保证演示环境稳定可跑
            ->addNode('answer', new ClosureNode('answer', static function ($ctx, $state): NodeExecutionResult {
                unset($ctx);
                $question = (string) ($state->get('question') ?: $state->get('query') ?: '');
                $docs = $state->get('retrievedDocs', []);
                $docs = is_array($docs) ? $docs : [];

                if ($docs === []) {
                    $answer = 'No relevant documents found for: ' . $question;
                } else {
                    $parts = [];
                    foreach ($docs as $i => $doc) {
                        if (!is_array($doc)) {
                            continue;
                        }
                        $content = trim((string) ($doc['content'] ?? ''));
                        if ($content === '') {
                            continue;
                        }
                        $score = isset($doc['score']) ? number_format((float) $doc['score'], 4) : 'n/a';
                        $parts[] = '[' . ($i + 1) . '] (score=' . $score . ') ' . $content;
                    }
                    $answer = $parts === []
                        ? 'No relevant documents found for: ' . $question
                        : "Based on retrieved context for \"{$question}\":\n\n" . implode("\n\n", $parts);
                }

                $state->set('answer', $answer);
                $state->set('answerMode', 'extractive');

                return NodeExecutionResult::success([
                    'answer' => $answer,
                    'answerMode' => 'extractive',
                    'hitCount' => count($docs),
                ]);
            }))
            ->addEdge('retrieve', 'answer');
    }
}

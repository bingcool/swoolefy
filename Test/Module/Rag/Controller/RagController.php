<?php

declare(strict_types=1);

namespace Test\Module\Rag\Controller;

use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Test\Module\Rag\RagService;
use Test\Module\Rag\Workflow\RagQaWorkflow;
use Test\Module\Workflow\WorkflowService;

/**
 * RAG 检索演示控制器 —— 入库、种子数据、相似度检索、问答与工作流。
 *
 * 路由（见 Test/Router/Common/Api.php，前缀 /api）：
 *
 *   GET  /api/v1/rag/config
 *        当前 default_vector_store、embedding、已声明 vector_stores
 *
 *   GET  /api/v1/rag/stores
 *        向量库别名列表
 *
 *   POST /api/v1/rag/seed
 *        写入演示语料。Body: { "knowledgeBase": "demo_kb", "vectorStore": "file" }
 *
 *   POST /api/v1/rag/ingest
 *        自定义文本入库。Body:
 *        { "knowledgeBase": "demo_kb", "texts": ["..."], "vectorStore": "file" }
 *
 *   POST /api/v1/rag/retrieve
 *        相似度检索。Body:
 *        { "query": "...", "knowledgeBase": "demo_kb", "topK": 5, "vectorStore": "file" }
 *
 *   POST /api/v1/rag/ask
 *        检索增强问答。Body:
 *        {
 *          "query": "...",
 *          "knowledgeBase": "demo_kb",
 *          "topK": 5,
 *          "vectorStore": "file",
 *          "useAgent": false   // true 时走 DemoKnowledgeRag
 *        }
 *
 *   POST /api/v1/rag/workflow/qa
 *        工作流：retrieve → answer。Body 同 retrieve，另支持 question 字段。
 *
 * 推荐演示顺序：seed → retrieve → ask → workflow/qa
 *
 * @see \Test\Module\Rag\README.md
 */
final class RagController extends BController
{
    /**
     * 当前 RAG 配置摘要。
     *
     * GET /api/v1/rag/config
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return RagService::instance()->configSummary();
    }

    /**
     * 已声明向量库别名。
     *
     * GET /api/v1/rag/stores
     *
     * @return array<string, mixed>
     */
    public function stores(): array
    {
        $service = RagService::instance();

        return [
            'defaultVectorStore' => $service->config()->defaultVectorStoreAlias(),
            'stores' => $service->listStores(),
        ];
    }

    /**
     * 写入演示语料。
     *
     * POST /api/v1/rag/seed
     * 
     curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/seed' -H 'Content-Type: application/json' -d '{"knowledgeBase":"demo_kb"}' | jq .
     *
     * @return array<string, mixed>
     */
    public function seed(RequestInput $requestInput): array
    {
        $knowledgeBase = $this->knowledgeBase($requestInput);
        $storeAlias = $this->storeAlias($requestInput);

        return RagService::instance()->seed($knowledgeBase, $storeAlias);
    }

    /**
     * 自定义文本入库。
     *
     * POST /api/v1/rag/ingest
     *
     * @return array<string, mixed>
     */
    public function ingest(RequestInput $requestInput): array
    {
        $knowledgeBase = $this->knowledgeBase($requestInput);
        $storeAlias = $this->storeAlias($requestInput);
        $texts = $requestInput->input('texts', []);
        if (!is_array($texts) || $texts === []) {
            throw new SystemException('texts must be a non-empty array of strings', 400);
        }

        $normalized = [];
        foreach ($texts as $text) {
            if (is_string($text) && trim($text) !== '') {
                $normalized[] = $text;
            }
        }
        if ($normalized === []) {
            throw new SystemException('texts must contain at least one non-empty string', 400);
        }

        return RagService::instance()->ingest($knowledgeBase, $normalized, $storeAlias);
    }

    /**
     * 相似度检索。
     *
     * POST /api/v1/rag/retrieve
     curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/retrieve' -H 'Content-Type: application/json' -d '{"query":"What is RAG in swoolefy?","topK":3}' | jq .
     * @return array<string, mixed>
     */
    public function retrieve(RequestInput $requestInput): array
    {
        $query = $this->requireQuery($requestInput);
        $knowledgeBase = $this->knowledgeBase($requestInput);
        $storeAlias = $this->storeAlias($requestInput);
        $topK = $this->topK($requestInput);

        return RagService::instance()->retrieve($knowledgeBase, $query, $topK, $storeAlias);
    }

    /**
     * 检索增强问答（抽取式或 Agent）。
     *
     * POST /api/v1/rag/ask
     *
     curl -s -X POST 'http://127.0.0.1:9501/api/v1/rag/ask' -H 'Content-Type: application/json' -d '{"query":"How does vector_stores alias work?"}' | jq .
     * 
     * @return array<string, mixed>
     */
    public function ask(RequestInput $requestInput): array
    {
        $query = $this->requireQuery($requestInput);
        $knowledgeBase = $this->knowledgeBase($requestInput);
        $storeAlias = $this->storeAlias($requestInput);
        $topK = $this->topK($requestInput);
        $useAgent = $this->boolInput($requestInput, 'useAgent', default: false);

        return RagService::instance()->ask($knowledgeBase, $query, $topK, $storeAlias, $useAgent);
    }

    /**
     * 工作流问答：RagRetrieveNode → 抽取式 answer。
     *
     * POST /api/v1/rag/workflow/qa
     *
     * @return array<string, mixed>
     */
    public function workflowQa(RequestInput $requestInput): array
    {
        $query = $this->requireQuery($requestInput);
        $knowledgeBase = $this->knowledgeBase($requestInput);
        $storeAlias = $this->storeAlias($requestInput);
        $topK = $this->topK($requestInput) ?? RagService::instance()->config()->defaultTopK();

        // 工作流 queryKey=question；同时写入 query 便于排查
        $input = [
            'question' => $query,
            'query' => $query,
            'knowledgeBase' => $knowledgeBase,
        ];

        $definition = RagQaWorkflow::definition(
            RagService::instance()->retrievalService(),
            $knowledgeBase,
            $topK,
            $storeAlias,
        );

        try {
            $compiled = WorkflowComponentFactory::compiler()->compile($definition);
            $engine = WorkflowService::engine(events: new StreamWorkflowEventDispatcher());
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }

        return [
            'runId' => $run->runId,
            'workflowId' => $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'knowledgeBase' => $knowledgeBase,
            'query' => $query,
            'answer' => $run->state->get('answer'),
            'answerMode' => $run->state->get('answerMode'),
            'retrievedDocs' => $run->state->get('retrievedDocs'),
            'storeAlias' => $storeAlias ?? RagService::instance()->config()->defaultVectorStoreAlias(),
            'data' => $run->state->data,
        ];
    }

    private function knowledgeBase(RequestInput $requestInput): string
    {
        $kb = $requestInput->input('knowledgeBase');
        if ($kb === null || $kb === '') {
            return RagService::DEFAULT_KNOWLEDGE_BASE;
        }

        return (string) $kb;
    }

    /**
     * 向量库别名（须存在于 rag.vector_stores）。
     * 未传则返回 null，底层使用 default_vector_store。
     */
    private function storeAlias(RequestInput $requestInput): ?string
    {
        $alias = $requestInput->input('vectorStore') ?? $requestInput->input('vector_store');
        if (!is_string($alias) || $alias === '') {
            return null;
        }

        if (!RagService::instance()->config()->hasVectorStoreAlias($alias)) {
            throw new SystemException(
                "Unknown vector store alias [{$alias}]. GET /api/v1/rag/stores for available aliases.",
                400,
            );
        }

        return $alias;
    }

    private function requireQuery(RequestInput $requestInput): string
    {
        $query = $requestInput->input('query') ?? $requestInput->input('question');
        if ($query === null || $query === '') {
            throw new SystemException('query is required (or question)', 400);
        }

        return (string) $query;
    }

    private function topK(RequestInput $requestInput): ?int
    {
        $topK = $requestInput->input('topK');
        if ($topK === null || $topK === '') {
            return null;
        }
        $value = (int) $topK;
        if ($value < 1) {
            throw new SystemException('topK must be >= 1', 400);
        }

        return $value;
    }

    private function boolInput(RequestInput $requestInput, string $key, bool $default): bool
    {
        $value = $requestInput->input($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }
}

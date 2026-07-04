<?php

declare(strict_types=1);

namespace Swoolefy\Support\Rag\Node;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\RAG;
use Swoolefy\Support\AI\Stream\StreamBridge;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Rag\Builder\RAGNodeBuilder;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\State\WorkflowState;

/**
 * RAG 问答节点 —— 委托 Neuron RAG Agent 自动检索 + 生成回答。
 */
final class RAGNode extends AbstractNode
{
    /** @param array<string, mixed> $config */
    public function __construct(
        string $nodeId,
        private readonly array $config,
        private readonly ?NeuronFactory $neuronFactory = null,
    ) {
        parent::__construct($nodeId);
    }

    public static function make(string $nodeId): RAGNodeBuilder
    {
        return RAGNodeBuilder::make($nodeId);
    }

    /** {@inheritdoc} */
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        $outputKey = (string) ($this->config['outputKey'] ?? 'answer');

        if (isset($this->config['executor']) && is_callable($this->config['executor'])) {
            $output = ($this->config['executor'])($ctx, $state);
        } else {
            $output = $this->invokeRagAgent($state);
        }

        $state->set($outputKey, $output);

        return NodeExecutionResult::success(
            [$outputKey => $output],
            metrics: ['nodeType' => 'rag_answer'],
        );
    }

    private function invokeRagAgent(WorkflowState $state): string
    {
        $ragClass = $this->config['ragAgent'] ?? null;
        if (!is_string($ragClass) || !is_subclass_of($ragClass, RAG::class)) {
            throw new WorkflowException("RAGNode {$this->nodeId} requires ragAgent extending Neuron RAG");
        }

        $agent = $this->createRagAgent($ragClass, $state);
        $prompt = $this->buildPrompt($state);

        if (($this->config['stream'] ?? false) === true) {
            $handler = $agent->stream(new UserMessage($prompt));
            $text = '';
            foreach ($handler->events() as $event) {
                if (is_object($event) && property_exists($event, 'content')) {
                    $chunk = (string) $event->content;
                    $text .= $chunk;
                    StreamBridge::emit('token', ['nodeId' => $this->nodeId, 'content' => $chunk]);
                }
            }

            return $text !== '' ? $text : $handler->getMessage()->getContent();
        }

        return $agent->chat(new UserMessage($prompt))->getMessage()->getContent();
    }

    /** @param class-string<RAG> $ragClass */
    private function createRagAgent(string $ragClass, WorkflowState $state): RAG
    {
        if ($this->neuronFactory !== null) {
            $agent = $this->neuronFactory->create($ragClass, $state, $this->config);
            if ($agent instanceof RAG) {
                return $agent;
            }
        }

        /** @var RAG $agent */
        // 会话记忆由 Agent/RAG::chatHistory() 决定
        return new $ragClass();
    }

    private function buildPrompt(WorkflowState $state): string
    {
        $promptKey = (string) ($this->config['promptKey'] ?? 'question');
        $prompt = $state->get($promptKey);

        if (is_string($prompt) && $prompt !== '') {
            return $prompt;
        }

        return 'Answer the user question using retrieved knowledge.';
    }
}

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

namespace Swoolefy\Support\Rag\Builder;

use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Rag\Node\RAGNode;

/**
 * RAGNode Fluent DSL。
 *
 * @see docs/SwoolefyAI.md §4.10
 */
final class RAGNodeBuilder
{
    /** @var array<string, mixed> */
    private array $config = [];

    private function __construct(private readonly string $nodeId)
    {
    }

    public static function make(string $nodeId): self
    {
        return new self($nodeId);
    }

    /** @param class-string<\NeuronAI\RAG\RAG> $ragAgentClass */
    public function ragAgent(string $ragAgentClass): self
    {
        $this->config['ragAgent'] = $ragAgentClass;

        return $this;
    }

    public function promptKey(string $key): self
    {
        $this->config['promptKey'] = $key;

        return $this;
    }

    public function topK(int $topK): self
    {
        $this->config['topK'] = $topK;

        return $this;
    }

    public function memory(?string $threadIdKey = 'sessionId', int $contextWindow = 50000): self
    {
        $this->config['memory'] = true;
        $this->config['threadIdKey'] = $threadIdKey;
        $this->config['contextWindow'] = $contextWindow;

        return $this;
    }

    public function stream(bool $enabled = true): self
    {
        $this->config['stream'] = $enabled;

        return $this;
    }

    /** @param callable(\Swoolefy\Support\Workflow\Engine\RunContext, \Swoolefy\Support\Workflow\State\WorkflowState): mixed $executor */
    public function executor(callable $executor): self
    {
        $this->config['executor'] = $executor;

        return $this;
    }

    public function build(?NeuronFactory $neuronFactory = null): RAGNode
    {
        return new RAGNode($this->nodeId, $this->config, $neuronFactory);
    }
}

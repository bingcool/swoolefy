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

namespace PHPUintTest\Unit\Support\AI;

use Swoolefy\Support\AI\Node\AINode;
use Swoolefy\Support\AI\Node\StructuredOutputNode;
use Swoolefy\Support\AI\Stream\CollectingStreamSink;
use Swoolefy\Support\Tests\Fixtures\DecisionDto;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use PHPUintTest\TestCase;

/**
 * AI 模块回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | AINodeBuilder | structured 配置、timeout、缺配置 fail-fast |
 * | Structured Output | executor mock 写 state 数组；stream/structured 互斥 |
 * | WorkflowState::dto | schemas 注册后数组可 hydrate 为 DTO |
 * | StructuredOutputNode | 独立节点委托写 outputKey |
 * | CollectingStreamSink | publish / events / clear |
 * | Chat executor | 默认 outputKey=output 写字符串 |
 *
 * 说明：全部用 executor 闭包 mock，不打真实 LLM。DecisionDto 来自 Support Tests Fixtures。
 */
final class AIModuleTest extends TestCase
{
    /**
     * 构造测试用 DecisionDto（executor mock 返回值）。
     *
     * @param bool $approved 是否批准
     * @param float $confidence 置信度
     */
    private function makeDecisionDto(bool $approved = true, float $confidence = 0.9): DecisionDto
    {
        $dto = new DecisionDto();
        $dto->approved = $approved;
        $dto->confidence = $confidence;
        $dto->reason = 'test decision';

        return $dto;
    }

    /**
     * 编译最小 Workflow 并 execute 单个 AINode（注册 decision schema）。
     */
    private function executeNode(AINode $node, WorkflowState $state): void
    {
        $compiled = WorkflowBootstrap::compiler()->compile(
            WorkflowDefinition::create('ai_module_test')
                ->addNode($node->id(), $node)
                ->registerSchema('decision', DecisionDto::class),
        );
        $ctx = new RunContext('run-ai-test', $compiled);
        $node->execute($ctx, $state);
    }

    /**
     * Builder：structured(DTO) + timeout 应落到节点 id 与 configuredTimeoutSeconds。
     */
    public function testBuilderStructuredConfig(): void
    {
        $node = AINode::make('ai_decision')
            ->structured(DecisionDto::class, outputKey: 'decision')
            ->executor(fn (): DecisionDto => $this->makeDecisionDto())
            ->timeout(30)
            ->build();

        $this->assertTrue($node->id() === 'ai_decision', 'node id');
        $this->assertTrue($node->configuredTimeoutSeconds() === 30, 'timeout config');
    }

    /**
     * Structured 执行后，state 中 outputKey 存的是数组（非 DTO 对象），字段可断言。
     */
    public function testStructuredOutputWritesStateArray(): void
    {
        $node = AINode::make('ai_decision')
            ->structured(DecisionDto::class, outputKey: 'decision')
            ->executor(fn (): DecisionDto => $this->makeDecisionDto(true, 0.95))
            ->build();

        $state = WorkflowState::fromInput([], ['decision' => DecisionDto::class]);
        $this->executeNode($node, $state);

        $decision = $state->get('decision');
        $this->assertTrue(is_array($decision), 'structured output stored as array');
        $this->assertTrue(($decision['approved'] ?? false) === true, 'approved field');
        $this->assertTrue(($decision['confidence'] ?? 0) === 0.95, 'confidence field');
    }

    /**
     * WorkflowState::dto() 按 schemas 把数组 hydrate 为 DecisionDto。
     */
    public function testWorkflowStateDtoHydration(): void
    {
        $state = new WorkflowState(
            data: [
                'decision' => [
                    'approved' => false,
                    'confidence' => 0.2,
                    'reason' => 'reject',
                ],
            ],
            schemas: ['decision' => DecisionDto::class],
        );

        $dto = $state->dto(DecisionDto::class);
        $this->assertTrue($dto instanceof DecisionDto, 'dto type');
        $this->assertTrue($dto->approved === false, 'dto approved');
        $this->assertTrue($dto->confidence === 0.2, 'dto confidence');
        $this->assertTrue($dto->reason === 'reject', 'dto reason');
    }

    /**
     * stream 与 structured 互斥：同时开启时 execute 抛 WorkflowException。
     */
    public function testStreamAndStructuredAreMutuallyExclusive(): void
    {
        $node = AINode::make('bad')
            ->structured(DecisionDto::class)
            ->stream(true)
            ->executor(static fn (): string => 'x')
            ->build();

        try {
            $this->executeNode($node, new WorkflowState());
            $this->assertTrue(false, 'should reject stream+structured');
        } catch (WorkflowException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'stream and structured'), 'mutex message');
        }
    }

    /**
     * 空配置 AINode（无 executor / structured / chat）execute 应 fail-fast。
     */
    public function testAiNodeRequiresConfiguration(): void
    {
        $node = AINode::make('empty')->build();

        try {
            $this->executeNode($node, new WorkflowState());
            $this->assertTrue(false, 'should require config');
        } catch (WorkflowException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'requires'), 'missing config message');
        }
    }

    /**
     * StructuredOutputNode 作为独立节点，executor 结果写入配置的 outputKey。
     */
    public function testStructuredOutputNodeDelegate(): void
    {
        $node = new StructuredOutputNode('so', DecisionDto::class, [
            'outputKey' => 'decision',
            'executor' => fn (): DecisionDto => $this->makeDecisionDto(false, 0.1),
        ]);

        $state = WorkflowState::fromInput([], ['decision' => DecisionDto::class]);
        $compiled = WorkflowBootstrap::compiler()->compile(
            WorkflowDefinition::create('so_test')
                ->addNode('so', $node)
                ->registerSchema('decision', DecisionDto::class),
        );
        $node->execute(new RunContext('run-so', $compiled), $state);

        $this->assertTrue(($state->get('decision')['approved'] ?? true) === false, 'structured node output');
    }

    /**
     * CollectingStreamSink：open → publish 两次 → events 形状正确 → clear 清空。
     */
    public function testCollectingStreamSink(): void
    {
        $sink = new CollectingStreamSink();
        $this->assertTrue($sink->isOpen(), 'sink open');
        $sink->publish('token', ['content' => 'hi']);
        $sink->publish('token', ['content' => ' there']);

        $events = $sink->events();
        $this->assertTrue(count($events) === 2, 'two events');
        $this->assertTrue($events[0]['event'] === 'token', 'event name');
        $this->assertTrue($events[0]['payload']['content'] === 'hi', 'payload');

        $sink->clear();
        $this->assertTrue($sink->events() === [], 'cleared');
    }

    /**
     * 纯 chat executor 返回字符串时，默认写入 state['output']。
     */
    public function testChatExecutorWritesStringOutput(): void
    {
        $node = AINode::make('chat')
            ->executor(static fn (): string => 'hello world')
            ->build();

        $state = new WorkflowState();
        $this->executeNode($node, $state);
        $this->assertTrue($state->get('output') === 'hello world', 'default outputKey is output');
    }
}

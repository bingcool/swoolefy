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
 * ## 运行
 * ```bash
 * php src/Support/AI/Tests/AIModuleTest.php
 * # 或
 * composer test:ai
 * ```
 *
 * 说明：全部用 executor 闭包 mock，不打真实 LLM。OrderDecisionDto 来自 Test 应用模块。
 */

use Swoolefy\Support\AI\Node\AINode;
use Swoolefy\Support\AI\Node\StructuredOutputNode;
use Swoolefy\Support\AI\Stream\CollectingStreamSink;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use Test\Module\Order\Dto\OrderDecisionDto;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** 断言为真，否则抛 RuntimeException（单测失败） */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * 构造测试用 OrderDecisionDto（executor mock 返回值）。
 *
 * @param bool $approved 是否批准
 * @param float $confidence 置信度
 */
function makeDecisionDto(bool $approved = true, float $confidence = 0.9): OrderDecisionDto
{
    $dto = new OrderDecisionDto();
    $dto->approved = $approved;
    $dto->confidence = $confidence;
    $dto->reason = 'test decision';

    return $dto;
}

/**
 * 编译最小 Workflow 并 execute 单个 AINode（注册 decision schema）。
 */
function executeNode(AINode $node, WorkflowState $state): void
{
    $compiled = WorkflowBootstrap::compiler()->compile(
        WorkflowDefinition::create('ai_module_test')
            ->addNode($node->id(), $node)
            ->registerSchema('decision', OrderDecisionDto::class),
    );
    $ctx = new RunContext('run-ai-test', $compiled);
    $node->execute($ctx, $state);
}

// ---------------------------------------------------------------------------
// AINodeBuilder / Structured Output
// ---------------------------------------------------------------------------

/**
 * Builder：structured(DTO) + timeout 应落到节点 id 与 configuredTimeoutSeconds。
 */
function testBuilderStructuredConfig(): void
{
    $node = AINode::make('ai_decision')
        ->structured(OrderDecisionDto::class, outputKey: 'decision')
        ->executor(static fn (): OrderDecisionDto => makeDecisionDto())
        ->timeout(30)
        ->build();

    assertTrue($node->id() === 'ai_decision', 'node id');
    assertTrue($node->configuredTimeoutSeconds() === 30, 'timeout config');
}

/**
 * Structured 执行后，state 中 outputKey 存的是数组（非 DTO 对象），字段可断言。
 */
function testStructuredOutputWritesStateArray(): void
{
    $node = AINode::make('ai_decision')
        ->structured(OrderDecisionDto::class, outputKey: 'decision')
        ->executor(static fn (): OrderDecisionDto => makeDecisionDto(true, 0.95))
        ->build();

    $state = WorkflowState::fromInput([], ['decision' => OrderDecisionDto::class]);
    executeNode($node, $state);

    $decision = $state->get('decision');
    assertTrue(is_array($decision), 'structured output stored as array');
    assertTrue(($decision['approved'] ?? false) === true, 'approved field');
    assertTrue(($decision['confidence'] ?? 0) === 0.95, 'confidence field');
}

/**
 * WorkflowState::dto() 按 schemas 把数组 hydrate 为 OrderDecisionDto。
 */
function testWorkflowStateDtoHydration(): void
{
    $state = new WorkflowState(
        data: [
            'decision' => [
                'approved' => false,
                'confidence' => 0.2,
                'reason' => 'reject',
            ],
        ],
        schemas: ['decision' => OrderDecisionDto::class],
    );

    $dto = $state->dto(OrderDecisionDto::class);
    assertTrue($dto instanceof OrderDecisionDto, 'dto type');
    assertTrue($dto->approved === false, 'dto approved');
    assertTrue($dto->confidence === 0.2, 'dto confidence');
    assertTrue($dto->reason === 'reject', 'dto reason');
}

/**
 * stream 与 structured 互斥：同时开启时 execute 抛 WorkflowException。
 */
function testStreamAndStructuredAreMutuallyExclusive(): void
{
    $node = AINode::make('bad')
        ->structured(OrderDecisionDto::class)
        ->stream(true)
        ->executor(static fn (): string => 'x')
        ->build();

    try {
        executeNode($node, new WorkflowState());
        assertTrue(false, 'should reject stream+structured');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'stream and structured'), 'mutex message');
    }
}

/**
 * 空配置 AINode（无 executor / structured / chat）execute 应 fail-fast。
 */
function testAiNodeRequiresConfiguration(): void
{
    $node = AINode::make('empty')->build();

    try {
        executeNode($node, new WorkflowState());
        assertTrue(false, 'should require config');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'requires'), 'missing config message');
    }
}

/**
 * StructuredOutputNode 作为独立节点，executor 结果写入配置的 outputKey。
 */
function testStructuredOutputNodeDelegate(): void
{
    $node = new StructuredOutputNode('so', OrderDecisionDto::class, [
        'outputKey' => 'decision',
        'executor' => static fn (): OrderDecisionDto => makeDecisionDto(false, 0.1),
    ]);

    $state = WorkflowState::fromInput([], ['decision' => OrderDecisionDto::class]);
    $compiled = WorkflowBootstrap::compiler()->compile(
        WorkflowDefinition::create('so_test')
            ->addNode('so', $node)
            ->registerSchema('decision', OrderDecisionDto::class),
    );
    $node->execute(new RunContext('run-so', $compiled), $state);

    assertTrue(($state->get('decision')['approved'] ?? true) === false, 'structured node output');
}

// ---------------------------------------------------------------------------
// Stream sink / Chat
// ---------------------------------------------------------------------------

/**
 * CollectingStreamSink：open → publish 两次 → events 形状正确 → clear 清空。
 */
function testCollectingStreamSink(): void
{
    $sink = new CollectingStreamSink();
    assertTrue($sink->isOpen(), 'sink open');
    $sink->publish('token', ['content' => 'hi']);
    $sink->publish('token', ['content' => ' there']);

    $events = $sink->events();
    assertTrue(count($events) === 2, 'two events');
    assertTrue($events[0]['event'] === 'token', 'event name');
    assertTrue($events[0]['payload']['content'] === 'hi', 'payload');

    $sink->clear();
    assertTrue($sink->events() === [], 'cleared');
}

/**
 * 纯 chat executor 返回字符串时，默认写入 state['output']。
 */
function testChatExecutorWritesStringOutput(): void
{
    $node = AINode::make('chat')
        ->executor(static fn (): string => 'hello world')
        ->build();

    $state = new WorkflowState();
    executeNode($node, $state);
    assertTrue($state->get('output') === 'hello world', 'default outputKey is output');
}

$tests = [
    'builder structured config' => 'testBuilderStructuredConfig',
    'structured writes state' => 'testStructuredOutputWritesStateArray',
    'state dto hydration' => 'testWorkflowStateDtoHydration',
    'stream/structured mutex' => 'testStreamAndStructuredAreMutuallyExclusive',
    'missing config error' => 'testAiNodeRequiresConfiguration',
    'structured output node' => 'testStructuredOutputNodeDelegate',
    'collecting stream sink' => 'testCollectingStreamSink',
    'chat executor string' => 'testChatExecutorWritesStringOutput',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
    echo "[PASS] {$name}\n";
}

echo "\nAll {$passed} AI module tests passed.\n";

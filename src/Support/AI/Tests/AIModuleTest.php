<?php

declare(strict_types=1);

/**
 * AI 模块回归测试。
 *
 * 覆盖：AINodeBuilder DSL、Structured Output（executor mock）、stream/structured 互斥、
 * WorkflowState::dto、StructuredOutputNode、CollectingStreamSink。
 *
 * 运行：php src/Support/AI/Tests/AIModuleTest.php
 * 或：composer test:ai
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

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeDecisionDto(bool $approved = true, float $confidence = 0.9): OrderDecisionDto
{
    $dto = new OrderDecisionDto();
    $dto->approved = $approved;
    $dto->confidence = $confidence;
    $dto->reason = 'test decision';

    return $dto;
}

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

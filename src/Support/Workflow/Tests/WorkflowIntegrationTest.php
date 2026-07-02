<?php

declare(strict_types=1);

/**
 * Phase 1–4 补充集成测试：SubWorkflow、JsonLogic、RoundRobin、ComponentFactory。
 *
 * 运行：php src/Support/Workflow/Tests/WorkflowIntegrationTest.php
 */

use Swoolefy\Support\Agent\Router\RoundRobinRouter;
use Swoolefy\Support\Agent\RouterContext;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Condition\JsonLogicEvaluator;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\SubWorkflowRunner;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\SubWorkflowNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowRegistry;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

/** JsonLogic 条件边路由。 */
function testJsonLogicRouting(): void
{
    $evaluator = new JsonLogicEvaluator();
    $scheduler = new DagScheduler(new \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator());

    $definition = WorkflowDefinition::create('jsonlogic_demo')
        ->addNode('start', new ClosureNode('start', function ($ctx, WorkflowState $state) {
            $state->set('score', 85);

            return NodeExecutionResult::success(['score' => 85]);
        }))
        ->addNode('high', new ClosureNode('high', fn () => NodeExecutionResult::success(['tier' => 'high'])))
        ->addNode('low', new ClosureNode('low', fn () => NodeExecutionResult::success(['tier' => 'low'])))
        ->addConditionalEdges('start', [
            'high' => EdgeCondition::fromJsonLogic([
                '>=' => [
                    ['var' => 'data.score'],
                    80,
                ],
            ]),
            'low' => EdgeCondition::always(),
        ]);

    $compiler = new WorkflowCompiler(new \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator());
    $compiled = $compiler->compile($definition);
    $engine = new WorkflowEngine(
        plugins: new PluginManager([]),
        scheduler: $scheduler,
    );
    $runId = $engine->start($compiled, []);
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::COMPLETED, 'JsonLogic run should complete');
    assertTrue(($run->state->get('tier') ?? '') === 'high', 'score>=80 should route to high');
    unset($evaluator);
    pass('jsonlogic routing');
}

/** SubWorkflowNode 嵌套执行。 */
function testSubWorkflowNode(): void
{
    $registry = new WorkflowRegistry();
    $registry->register('child_flow', static fn () => WorkflowDefinition::create('child_flow')
        ->addNode('child_step', new ClosureNode('child_step', function ($ctx, WorkflowState $state) {
            $value = (int) ($state->get('value', 0));

            return NodeExecutionResult::success(['doubled' => $value * 2]);
        })));

    $engine = WorkflowComponentFactory::engine($registry);
    $runner = WorkflowComponentFactory::subWorkflowRunner($registry);
    $compiler = WorkflowComponentFactory::compiler();

    $parent = WorkflowDefinition::create('parent_flow')
        ->addNode('prepare', new ClosureNode('prepare', function ($ctx, WorkflowState $state) {
            $state->set('subWorkflowInput', ['value' => 21]);

            return NodeExecutionResult::success(['subWorkflowInput' => ['value' => 21]]);
        }))
        ->addNode('run_child', new SubWorkflowNode('run_child', [
            'workflowId' => 'child_flow',
            'inputKey' => 'subWorkflowInput',
            'outputKey' => 'subWorkflowOutput',
        ], $runner, $registry))
        ->addEdge('prepare', 'run_child');

    $compiled = $compiler->compile($parent);
    $runId = $engine->start($compiled, []);
    $run = $engine->getRun($runId);

    assertTrue($run->status === RunStatus::COMPLETED, 'Parent run should complete');
    $output = $run->state->get('subWorkflowOutput');
    assertTrue(is_array($output) && ($output['doubled'] ?? null) === 42, 'Child should double value');
    pass('sub workflow node');
}

/** RoundRobinRouter 轮询。 */
function testRoundRobinRouter(): void
{
    $router = new RoundRobinRouter(['agent_a', 'agent_b', 'agent_c']);
    $ctx = new RouterContext('run-1', new WorkflowState(), ['agent_a', 'agent_b', 'agent_c']);

    $first = $router->route($ctx);
    $second = $router->route($ctx);
    $third = $router->route($ctx);
    $fourth = $router->route($ctx);

    assertTrue($first === ['agent_a'], 'First route should be agent_a');
    assertTrue($second === ['agent_b'], 'Second route should be agent_b');
    assertTrue($third === ['agent_c'], 'Third route should be agent_c');
    assertTrue($fourth === ['agent_a'], 'Fourth route should wrap to agent_a');
    pass('round robin router');
}

/** WorkflowComponentFactory 默认装配。 */
function testWorkflowComponentFactory(): void
{
    $registry = new WorkflowRegistry();
    $evaluator = WorkflowComponentFactory::conditionEvaluator();
    assertTrue($evaluator instanceof \Swoolefy\Support\Workflow\Condition\ConditionEvaluatorInterface, 'Evaluator created');

    $store = WorkflowComponentFactory::runStore($registry);
    assertTrue($store instanceof \Swoolefy\Support\Workflow\Engine\InMemoryRunStore, 'Default store is in-memory');

    $factoryDriver = ConditionEvaluatorFactory::create('symfony');
    assertTrue($factoryDriver instanceof \Swoolefy\Support\Workflow\Condition\CompositeConditionEvaluator, 'Factory symfony driver');
    pass('workflow component factory');
}

/** RAG ingest CLI（无 embedding key 时走 mock/空向量路径）。 */
function testIngestCli(): void
{
    $script = dirname(__DIR__, 2) . '/Rag/Console/ingest_documents.php';
    $cmd = sprintf(
        'php %s --kb=test_kb --text=%s 2>&1',
        escapeshellarg($script),
        escapeshellarg('integration test document'),
    );
    exec($cmd, $output, $code);
    $joined = implode("\n", $output);
    assertTrue($code === 0, "Ingest CLI failed: {$joined}");
    assertTrue(str_contains($joined, 'documentCount') || str_contains($joined, 'chunkCount'), 'Ingest should return counts');
    pass('rag ingest cli');
}

$tests = [
    'jsonlogic routing' => 'testJsonLogicRouting',
    'sub workflow node' => 'testSubWorkflowNode',
    'round robin router' => 'testRoundRobinRouter',
    'workflow component factory' => 'testWorkflowComponentFactory',
    'rag ingest cli' => 'testIngestCli',
];

foreach ($tests as $label => $fn) {
    try {
        $fn();
    } catch (Throwable $e) {
        fwrite(STDERR, "[FAIL] {$label}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo PHP_EOL . 'All ' . count($tests) . ' integration tests passed.' . PHP_EOL;

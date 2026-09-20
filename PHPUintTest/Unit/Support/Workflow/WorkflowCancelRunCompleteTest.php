<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Workflow;

use PHPUintTest\TestCase;
use ReflectionMethod;
use RuntimeException;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;

/**
 * P1-03：cancel / cancellation recovery 每个 Run 最多真正执行一轮 run.complete hook。
 */
final class WorkflowCancelRunCompleteTest extends TestCase
{
    public function testCancelWaitingFiresOnceAndPersistsFlag(): void
    {
        $counter = new WorkflowCancelRunCompleteCounterPlugin();
        $engine = $this->engine($counter);
        $runId = $engine->start($this->waitingWorkflow(), []);

        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status);
        $this->assertSame(0, $counter->count);

        $engine->cancel($runId);

        $run = $engine->getRun($runId);
        $this->assertSame(RunStatus::CANCELLED, $run->status);
        $this->assertTrue((bool) $run->state->get('_runCompleteFired', false));
        $this->assertTrue((bool) $engine->runStore()->find($runId)?->state->get('_runCompleteFired', false));
        $this->assertSame(1, $counter->count);
    }

    public function testRunningCancelThenCancelledRecoveryFiresOnce(): void
    {
        $counter = new WorkflowCancelRunCompleteCounterPlugin();
        $engine = $this->engine($counter);
        $secondNodeRan = false;

        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile(
            WorkflowDefinition::create('running_cancel', '1.0.0')
                ->addNode('a', new ClosureNode('a', static function (RunContext $ctx) use ($engine): NodeExecutionResult {
                    $engine->cancel($ctx->runId);

                    return NodeExecutionResult::success();
                }))
                ->addNode('b', new ClosureNode('b', static function () use (&$secondNodeRan): NodeExecutionResult {
                    $secondNodeRan = true;

                    return NodeExecutionResult::success();
                }))
                ->addEdge('a', 'b'),
        );

        $runId = $engine->start($compiled, []);

        $this->assertSame(RunStatus::CANCELLED, $engine->getRun($runId)->status);
        $this->assertFalse($secondNodeRan, 'cancelled run must not continue to the next node');
        $this->assertSame(1, $counter->count);
        $this->assertTrue((bool) $engine->getRun($runId)->state->get('_runCompleteFired', false));
    }

    public function testRepeatedCancelledRecoveryFiresOnce(): void
    {
        $counter = new WorkflowCancelRunCompleteCounterPlugin();
        $engine = $this->engine($counter);
        $runId = $engine->start($this->waitingWorkflow(), []);
        $engine->cancel($runId);
        $this->assertSame(1, $counter->count);

        $stored = $engine->getRun($runId);
        $stale = new WorkflowRun(
            runId: $stored->runId,
            compiled: $stored->compiled,
            status: RunStatus::RUNNING,
            state: new WorkflowState(),
            createdAt: $stored->createdAt,
            updatedAt: $stored->updatedAt,
            revision: $stored->revision,
        );

        $apply = new ReflectionMethod(WorkflowEngine::class, 'applyCancellationIfRequested');
        $apply->setAccessible(true);

        $this->assertTrue($apply->invoke($engine, $stale));
        $this->assertTrue($apply->invoke($engine, $stale));
        $this->assertSame(1, $counter->count, 'CANCELLED recovery must not fire run.complete again');
        $this->assertTrue((bool) $stale->state->get('_runCompleteFired', false));
    }

    public function testCompletedAndFailedStillFireOnce(): void
    {
        $okCounter = new WorkflowCancelRunCompleteCounterPlugin();
        $okEngine = $this->engine($okCounter);
        $okCompiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile(
            WorkflowDefinition::create('ok', '1.0.0')
                ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success())),
        );
        $okRunId = $okEngine->start($okCompiled, []);
        $this->assertSame(1, $okCounter->count);
        $this->assertSame(RunStatus::COMPLETED, $okEngine->getRun($okRunId)->status);

        $failCounter = new WorkflowCancelRunCompleteCounterPlugin();
        $failEngine = $this->engine($failCounter);
        $failCompiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile(
            WorkflowDefinition::create('fail', '1.0.0')
                ->addNode('boom', new ClosureNode('boom', static fn () => NodeExecutionResult::failed(new RuntimeException('boom')))),
        );

        try {
            $failEngine->start($failCompiled, []);
            $this->fail('expected node failure');
        } catch (WorkflowException) {
        }

        $this->assertSame(1, $failCounter->count);
    }

    public function testTerminalStatusStillCannotCancel(): void
    {
        $engine = $this->engine(new WorkflowCancelRunCompleteCounterPlugin());
        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile(
            WorkflowDefinition::create('done', '1.0.0')
                ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success())),
        );
        $runId = $engine->start($compiled, []);

        $this->expectException(WorkflowException::class);
        $engine->cancel($runId);
    }

    private function engine(WorkflowCancelRunCompleteCounterPlugin $counter): WorkflowEngine
    {
        return new WorkflowEngine(
            plugins: new PluginManager([$counter]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: new InMemoryRunStore(),
        );
    }

    private function waitingWorkflow(): CompiledWorkflow
    {
        return WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile(
            WorkflowDefinition::create('waiting_cancel', '1.0.0')
                ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
                ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops']))
                ->addEdge('start', 'pause'),
        );
    }
}

/**
 * 计数 run.complete 钩子触发次数，验证 cancel / recovery 只 fire 一轮。
 */
final class WorkflowCancelRunCompleteCounterPlugin implements WorkflowPluginInterface
{
    public int $count = 0;

    public function name(): string
    {
        return 'run_complete_counter';
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->onRunComplete(function (): void {
            ++$this->count;
        });
    }
}

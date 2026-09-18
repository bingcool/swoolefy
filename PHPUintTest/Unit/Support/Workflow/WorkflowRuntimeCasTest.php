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

namespace PHPUintTest\Unit\Support\Workflow;

use PDO;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\PauseTaskQueryableInterface;
use Swoolefy\Support\Workflow\Engine\RunContext;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\RunStoreInterface;
use Swoolefy\Support\Workflow\Engine\SubWorkflowRunner;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Engine\WorkflowRunSnapshot;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowRuntimeConflictException;
use Swoolefy\Support\Workflow\Node\AbstractNode;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\SubWorkflowNode;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use PHPUintTest\TestCase;
use Throwable;

/**
 * P0 Runtime revision CAS 与异常隔离。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | Snapshot | 缺 revision 字段兼容 0；version 仍是定义版本 |
 * | InMemory / Db | saveIfRevision、saveIfStatusAndRevision、并发 N→N+1 |
 * | Engine | conflict 不 Saga、不 onFail、不写 FAILED、不 plain save |
 * | Resume | rollback CAS；被抢写后 Conflict |
 * | 终态 | RUNNING↔WAITING / COMPLETED / CANCELLED |
 */
final class WorkflowRuntimeCasTest extends TestCase
{
    public function testSnapshotRevisionRoundTripAndLegacyDefault(): void
    {
        $registry = $this->registryWith('snap', static fn () => NodeExecutionResult::success());
        $compiled = (new WorkflowCompiler())->compile($registry->definition('snap'));
        $now = WorkflowRunTime::now();
        $run = new WorkflowRun(
            runId: 'run_snap',
            compiled: $compiled,
            status: RunStatus::RUNNING,
            state: WorkflowState::fromInput([], []),
            createdAt: $now,
            updatedAt: $now,
            revision: 7,
        );

        $array = WorkflowRunSnapshot::fromRun($run)->toArray();
        $this->assertSame(7, $array['revision']);
        $this->assertSame('1.0.0', $array['version'], 'version remains definition version');

        unset($array['revision']);
        $legacy = WorkflowRunSnapshot::fromArray($array);
        $this->assertSame(0, $legacy->revision, 'missing revision hydrates to 0');

        $hydrated = WorkflowRunSnapshot::fromRun($run)->hydrate($registry);
        $this->assertSame(7, $hydrated->revision);
        $this->assertSame('1.0.0', $hydrated->compiled->version());
    }

    public function testInMemoryRevisionCasAndDualCondition(): void
    {
        [$store, $registry] = $this->memoryStore();
        $run = $this->makeRun($registry, 'run_mem_rev', RunStatus::RUNNING, 0);
        $store->save($run);

        $this->assertTrue($store->saveIfRevision($run, 0));
        $this->assertSame(1, $run->revision);
        $this->assertSame(1, $store->find('run_mem_rev')?->revision);

        $staleRev = $run->revision;
        $this->assertFalse($store->saveIfRevision($run, 0));
        $this->assertSame($staleRev, $run->revision, 'conflict must not bump local revision');

        $run->status = RunStatus::WAITING;
        $this->assertFalse($store->saveIfStatusAndRevision($run, RunStatus::WAITING, 1), 'wrong status');
        $this->assertFalse($store->saveIfStatusAndRevision($run, RunStatus::RUNNING, 99), 'wrong revision');
        $this->assertTrue($store->saveIfStatusAndRevision($run, RunStatus::RUNNING, 1));
        $this->assertSame(2, $run->revision);
        $this->assertSame(RunStatus::WAITING, $store->find('run_mem_rev')?->status);
    }

    public function testInMemoryConcurrentSaveIfRevision(): void
    {
        [$store, $registry] = $this->memoryStore();
        $seed = $this->makeRun($registry, 'run_mem_race', RunStatus::RUNNING, 10);
        $store->save($seed);

        $a = $this->makeRun($registry, 'run_mem_race', RunStatus::RUNNING, 10);
        $a->currentNodeId = 'winner';
        $b = $this->makeRun($registry, 'run_mem_race', RunStatus::RUNNING, 10);
        $b->currentNodeId = 'loser';

        $this->assertTrue($store->saveIfRevision($a, 10));
        $this->assertFalse($store->saveIfRevision($b, 10));
        $this->assertSame(10, $b->revision, 'loser local revision unchanged');

        $found = $store->find('run_mem_race');
        $this->assertSame(11, $found?->revision);
        $this->assertSame('winner', $found?->currentNodeId);
    }

    public function testDbRunStoreRevisionCas(): void
    {
        $registry = $this->registryWith('dbcas', static fn () => NodeExecutionResult::success());
        $pdo = new PDO('sqlite::memory:');
        WorkflowRunsSchemaInstaller::install($pdo);
        $store = new DbRunStore($pdo, $registry, 'workflow_runs');
        $run = $this->makeRun($registry, 'run_db_rev', RunStatus::WAITING, 3);
        $store->save($run);

        $run->status = RunStatus::RUNNING;
        $this->assertFalse($store->saveIfStatusAndRevision($run, RunStatus::RUNNING, 3));
        $this->assertTrue($store->saveIfRevision($run, 3));
        $this->assertSame(4, $store->find('run_db_rev')?->revision);

        $run->status = RunStatus::COMPLETED;
        $this->assertTrue($store->saveIfStatusAndRevision($run, RunStatus::RUNNING, 4));
        $found = $store->find('run_db_rev');
        $this->assertSame(RunStatus::COMPLETED, $found?->status);
        $this->assertSame(5, $found?->revision);
    }

    public function testEngineConflictDoesNotFailOrSagaOrOnFail(): void
    {
        $failNode = new OnFailCountingNode('boom');
        $okNode = new CompensateCountingNode('ok');
        $registry = new WorkflowRegistry();
        $registry->register('iso', static fn () => WorkflowDefinition::create('iso', '1.0.0')
            ->enableSaga()
            ->addNode('ok', $okNode)
            ->addNode('boom', $failNode)
            ->addEdge('ok', 'boom'));

        $inner = new InMemoryRunStore();
        $store = new RecordingRunStore($inner);
        $engine = $this->engine($store);
        $compiled = (new WorkflowCompiler())->compile($registry->definition('iso'));

        try {
            $engine->start($compiled, []);
            $this->fail('conflict must abort');
        } catch (WorkflowRuntimeConflictException $e) {
            $this->assertSame(0, $e->expectedRevision);
        }

        $runs = $inner->all();
        $this->assertCount(1, $runs);
        $this->assertSame(RunStatus::RUNNING, $runs[0]->status, 'must not write FAILED');
        $this->assertSame(0, $failNode->onFailCount, 'Runtime must not onFail');
        $this->assertSame(0, $okNode->compensateCount, 'Runtime must not Saga');
        $this->assertSame(1, $store->unconditionalSaveCount, 'only create may plain save');
    }

    public function testEngineCreateStillUnconditionalSaveThenCas(): void
    {
        $registry = $this->registryWith('done', static fn () => NodeExecutionResult::success());
        $store = new RecordingRunStore(new InMemoryRunStore());
        $engine = $this->engine($store);
        $runId = $engine->start((new WorkflowCompiler())->compile($registry->definition('done')), []);
        $run = $engine->getRun($runId);

        $this->assertSame(RunStatus::COMPLETED, $run->status);
        $this->assertGreaterThan(0, $run->revision);
        $this->assertSame('save', $store->calls[0]);
        $this->assertContains('saveIfRevision', $store->calls);
        $this->assertContains('saveIfStatusAndRevision', $store->calls);
        $this->assertSame(1, $store->unconditionalSaveCount);
    }

    public function testWaitingToRunningToCompletedAndCancel(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause', new ResumeNoopNode('pause'))
            ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success(['done' => true])))
            ->addEdge('start', 'pause')
            ->addEdge('pause', 'done'));

        $store = new InMemoryRunStore();
        $engine = $this->engine($store);
        $compiled = (new WorkflowCompiler())->compile($registry->definition('hitl'));
        $runId = $engine->start($compiled, []);
        $waiting = $engine->getRun($runId);
        $this->assertSame(RunStatus::WAITING, $waiting->status);
        $waitingRev = $waiting->revision;

        $engine->resume($runId, ['approved' => true]);
        $completed = $engine->getRun($runId);
        $this->assertSame(RunStatus::COMPLETED, $completed->status);
        $this->assertGreaterThan($waitingRev, $completed->revision);

        $runId2 = $engine->start($compiled, []);
        $engine->cancel($runId2);
        $this->assertSame(RunStatus::CANCELLED, $engine->getRun($runId2)->status);
    }

    public function testResumeRollbackCasRestoresWaiting(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('rb', static fn () => WorkflowDefinition::create('rb', '1.0.0')
            ->addNode('pause', new ResumeThrowNode('pause', new WorkflowException('resume failed')))
            ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success()))
            ->addEdge('pause', 'done'));

        $store = new RecordingRunStore(new InMemoryRunStore());
        $engine = $this->engine($store);
        $runId = $engine->start((new WorkflowCompiler())->compile($registry->definition('rb')), []);
        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status);
        $savesAfterStart = $store->unconditionalSaveCount;

        try {
            $engine->resume($runId, []);
            $this->fail('resume should throw');
        } catch (WorkflowException $e) {
            $this->assertSame('resume failed', $e->getMessage());
        }

        $run = $engine->getRun($runId);
        $this->assertSame(RunStatus::WAITING, $run->status);
        $this->assertSame('pause', $run->pauseNodeId);
        $this->assertSame($savesAfterStart, $store->unconditionalSaveCount, 'rollback must not plain save');
    }

    public function testResumeRollbackConflictDoesNotPlainSave(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('rbc', static fn () => WorkflowDefinition::create('rbc', '1.0.0')
            ->addNode('pause', new ResumeThrowNode('pause', new WorkflowException('resume failed')))
            ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success()))
            ->addEdge('pause', 'done'));

        $inner = new InMemoryRunStore();
        $store = new RunningToWaitingConflictStore($inner);
        $engine = $this->engine($store);
        $runId = $engine->start((new WorkflowCompiler())->compile($registry->definition('rbc')), []);
        $savesAfterStart = $store->unconditionalSaveCount;

        try {
            $engine->resume($runId, []);
            $this->fail('rollback conflict must abort');
        } catch (WorkflowRuntimeConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($savesAfterStart, $store->unconditionalSaveCount);
        $found = $inner->find($runId);
        $this->assertNotNull($found);
        $this->assertFalse(
            $inner->saveIfStatus($found, RunStatus::WAITING),
            'persisted status remains RUNNING after failed rollback CAS',
        );
    }

    public function testSubWorkflowRuntimeDoesNotBecomeParentNodeFailed(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('child', static fn () => WorkflowDefinition::create('child', '1.0.0')
            ->addNode('c', new ClosureNode('c', static fn () => NodeExecutionResult::success())));

        $childStore = new ConflictAfterCreateStore(new InMemoryRunStore());
        $childEngine = $this->engine($childStore);
        $runner = new SubWorkflowRunner($childEngine);

        $parent = WorkflowDefinition::create('parent', '1.0.0')
            ->addNode('run_child', new SubWorkflowNode('run_child', [
                'workflowId' => 'child',
            ], $runner, $registry));

        $parentStore = new InMemoryRunStore();
        $parentEngine = $this->engine($parentStore);

        try {
            $parentEngine->start((new WorkflowCompiler())->compile($parent), []);
            $this->fail('child runtime must abort parent');
        } catch (WorkflowRuntimeConflictException) {
            $this->addToAssertionCount(1);
        }

        $runs = $parentStore->all();
        $this->assertCount(1, $runs);
        $this->assertSame(RunStatus::RUNNING, $runs[0]->status, 'parent must not be FAILED');
        $this->assertNull($runs[0]->error);
    }

    public function testBusinessFailureStillMarksFailed(): void
    {
        $registry = $this->registryWith('biz', static fn () => NodeExecutionResult::failed(new WorkflowException('boom')));
        $store = new InMemoryRunStore();
        $engine = $this->engine($store);

        try {
            $engine->start((new WorkflowCompiler())->compile($registry->definition('biz')), []);
            $this->fail('business failure must throw');
        } catch (WorkflowException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'boom'));
        }

        $run = $store->all()[0] ?? null;
        $this->assertSame(RunStatus::FAILED, $run?->status);
    }

    /**
     * @return array{0: InMemoryRunStore, 1: WorkflowRegistry}
     */
    private function memoryStore(): array
    {
        $registry = $this->registryWith('cas', static fn () => NodeExecutionResult::success());

        return [new InMemoryRunStore(), $registry];
    }

    private function engine(RunStoreInterface $store): WorkflowEngine
    {
        return new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: $store,
        );
    }

    /**
     * @param callable(RunContext, WorkflowState): NodeExecutionResult $handler
     */
    private function registryWith(string $id, callable $handler): WorkflowRegistry
    {
        $registry = new WorkflowRegistry();
        $registry->register($id, static fn () => WorkflowDefinition::create($id, '1.0.0')
            ->addNode('a', new ClosureNode('a', $handler)));

        return $registry;
    }

    private function makeRun(
        WorkflowRegistry $registry,
        string $runId,
        RunStatus $status,
        int $revision,
    ): WorkflowRun {
        $ids = $registry->ids();
        $workflowId = $ids[0] ?? 'cas';
        $compiled = (new WorkflowCompiler())->compile($registry->definition((string) $workflowId));
        $now = WorkflowRunTime::now();

        return new WorkflowRun(
            runId: $runId,
            compiled: $compiled,
            status: $status,
            state: WorkflowState::fromInput([], []),
            createdAt: $now,
            updatedAt: $now,
            revision: $revision,
        );
    }
}

final class OnFailCountingNode extends AbstractNode
{
    public int $onFailCount = 0;

    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        throw new WorkflowRuntimeConflictException($ctx->runId, 'iso', 0, '1.0.0');
    }

    public function onFail(RunContext $ctx, WorkflowState $state, ?Throwable $e): void
    {
        ++$this->onFailCount;
    }
}

final class CompensateCountingNode extends AbstractNode
{
    public int $compensateCount = 0;

    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        return NodeExecutionResult::success();
    }

    public function compensate(RunContext $ctx, WorkflowState $state): void
    {
        ++$this->compensateCount;
    }
}

final class ResumeThrowNode extends AbstractNode
{
    public function __construct(string $nodeId, private readonly Throwable $error)
    {
        parent::__construct($nodeId);
    }

    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        return NodeExecutionResult::waiting(['assignee' => 'ops']);
    }

    public function onResume(RunContext $ctx, WorkflowState $state, array $feedback): void
    {
        throw $this->error;
    }
}

final class ResumeNoopNode extends AbstractNode
{
    public function execute(RunContext $ctx, WorkflowState $state): NodeExecutionResult
    {
        return NodeExecutionResult::waiting(['assignee' => 'ops']);
    }
}

final class RecordingRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    /** @var list<string> */
    public array $calls = [];
    public int $unconditionalSaveCount = 0;

    public function __construct(private readonly InMemoryRunStore $inner)
    {
    }

    public function save(WorkflowRun $run): void
    {
        $this->calls[] = 'save';
        ++$this->unconditionalSaveCount;
        $this->inner->save($run);
    }

    public function saveIfRevision(WorkflowRun $run, int $expectedRevision): bool
    {
        $this->calls[] = 'saveIfRevision';

        return $this->inner->saveIfRevision($run, $expectedRevision);
    }

    public function saveIfStatusAndRevision(
        WorkflowRun $run,
        RunStatus $expectedStatus,
        int $expectedRevision,
    ): bool {
        $this->calls[] = 'saveIfStatusAndRevision';

        return $this->inner->saveIfStatusAndRevision($run, $expectedStatus, $expectedRevision);
    }

    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        $this->calls[] = 'saveIfStatus';

        return $this->inner->saveIfStatus($run, $expectedStatus);
    }

    public function find(string $runId): ?WorkflowRun
    {
        return $this->inner->find($runId);
    }

    public function listWaiting(?string $assignee = null): array
    {
        return $this->inner->listWaiting($assignee);
    }
}

/** 创建后第一次 revision CAS 失败，模拟并发覆盖。 */
final class ConflictAfterCreateStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    public function __construct(private readonly InMemoryRunStore $inner)
    {
    }

    public function save(WorkflowRun $run): void
    {
        $this->inner->save($run);
    }

    public function saveIfRevision(WorkflowRun $run, int $expectedRevision): bool
    {
        return false;
    }

    public function saveIfStatusAndRevision(
        WorkflowRun $run,
        RunStatus $expectedStatus,
        int $expectedRevision,
    ): bool {
        return false;
    }

    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        return $this->inner->saveIfStatus($run, $expectedStatus);
    }

    public function find(string $runId): ?WorkflowRun
    {
        return $this->inner->find($runId);
    }

    public function listWaiting(?string $assignee = null): array
    {
        return $this->inner->listWaiting($assignee);
    }
}

/**
 * 第一次 RUNNING→WAITING（start HITL）成功；第二次（resume rollback）失败。
 */
final class RunningToWaitingConflictStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    public int $unconditionalSaveCount = 0;
    private int $runningToWaiting = 0;

    public function __construct(private readonly InMemoryRunStore $inner)
    {
    }

    public function save(WorkflowRun $run): void
    {
        ++$this->unconditionalSaveCount;
        $this->inner->save($run);
    }

    public function saveIfRevision(WorkflowRun $run, int $expectedRevision): bool
    {
        return $this->inner->saveIfRevision($run, $expectedRevision);
    }

    public function saveIfStatusAndRevision(
        WorkflowRun $run,
        RunStatus $expectedStatus,
        int $expectedRevision,
    ): bool {
        if ($expectedStatus === RunStatus::RUNNING && $run->status === RunStatus::WAITING) {
            ++$this->runningToWaiting;
            if ($this->runningToWaiting >= 2) {
                return false;
            }
        }

        return $this->inner->saveIfStatusAndRevision($run, $expectedStatus, $expectedRevision);
    }

    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        return $this->inner->saveIfStatus($run, $expectedStatus);
    }

    public function find(string $runId): ?WorkflowRun
    {
        return $this->inner->find($runId);
    }

    public function listWaiting(?string $assignee = null): array
    {
        return $this->inner->listWaiting($assignee);
    }
}

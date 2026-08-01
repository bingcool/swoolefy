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
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\DbRunStore;
use Swoolefy\Support\Workflow\Engine\InMemoryRunStore;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\PauseTaskQueryableInterface;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\RunStoreInterface;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Engine\WorkflowRunPresenter;
use Swoolefy\Support\Workflow\Engine\WorkflowRunTime;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Node\PauseNode;
use Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowHitlAuth;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use PHPUintTest\TestCase;

/**
 * Phase A 生产加固测试 —— HITL 鉴权、resume CAS、Run 生命周期钩子。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | WorkflowHitlAuth | API Key / 角色校验、鉴权关闭、assignee 匹配 |
 * | 列表过滤 | resolveListAssigneeFilter、listPauseTasks 按 assignee 范围 |
 * | resume CAS | 双 resume 防护、pauseNodeId 清除、DbRunStore saveIfStatus |
 * | run.complete | WAITING 中间态不触发；cancel 释放 RateLimit |
 * | WorkflowRunPresenter | 默认脱敏 data/nodeOutputs/agentOutputs |
 */
final class WorkflowHitlAuthTest extends TestCase
{
    /**
     * 在回调执行期间临时设置/清除环境变量，结束后恢复原值。
     *
     * 用于测试 WORKFLOW_HITL_AUTH_ENABLED 等开关，避免污染进程全局状态。
     *
     * @param array<string, string|null> $vars null 表示临时 unset 该变量
     * @param callable(): void $callback 在修改后的环境中执行的测试逻辑
     */
    private function withHitlEnv(array $vars, callable $callback): void
    {
        $previous = [];
        foreach ($vars as $name => $value) {
            $previous[$name] = $_ENV[$name] ?? getenv($name);
            if ($value === null) {
                unset($_ENV[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $value;
                putenv($name . '=' . $value);
            }
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === false || $value === null || $value === '') {
                    unset($_ENV[$name]);
                    putenv($name);
                } else {
                    $_ENV[$name] = $value;
                    putenv($name . '=' . $value);
                }
            }
        }
    }

    /**
     * 构造启用 HITL 鉴权的 WorkflowConfig，支持数组覆盖默认项。
     *
     * 默认：memory store、auth_enabled、api_key、allowed_roles、require_assignee_match。
     */
    private function hitlConfig(array $overrides = []): WorkflowConfig
    {
        return WorkflowConfig::fromArray([
            'workflow' => array_merge([
                'default_run_store' => 'memory',
                'hitl' => [
                    'auth_enabled' => true,
                    'api_key' => 'secret-key',
                    'allowed_roles' => ['operator', 'admin'],
                    'require_assignee_match' => true,
                ],
            ], $overrides),
        ]);
    }

    /**
     * 验证：提供正确 API Key 时 assertAuthorizedForUser 通过（无 AuthUser 亦可）。
     *
     * 生产 API 网关常用 Key 鉴权，须支持无角色仅 Key 的场景。
     */
    public function testHitlAuthWithValidApiKey(): void
    {
        $auth = new WorkflowHitlAuth($this->hitlConfig());
        $auth->assertAuthorizedForUser(null, 'secret-key');
        $this->addToAssertionCount(1);
    }

    /**
     * 验证：AuthUser.roles 与 allowed_roles 求交时 ForUser 通过；无用户且无 Key 拒绝。
     */
    public function testHitlAuthWithValidRole(): void
    {
        $auth = new WorkflowHitlAuth($this->hitlConfig());
        $user = new AuthUser(userId: 'u1', roles: ['admin']);
        $auth->assertAuthorizedForUser($user, null);

        $this->expectException(WorkflowPermissionException::class);
        $auth->assertAuthorizedForUser(null, null);
    }

    /**
     * 验证：鉴权开启且 Key 与用户均缺失时抛出 WorkflowPermissionException。
     *
     * 防止匿名 resume/list 操作。
     */
    public function testHitlAuthRejectsMissingCredentials(): void
    {
        $auth = new WorkflowHitlAuth($this->hitlConfig());
        $this->expectException(WorkflowPermissionException::class);
        $auth->assertAuthorizedForUser(null, null);
    }

    /**
     * 验证：auth_enabled=false 或环境变量关闭时，无凭证也可通过鉴权。
     *
     * 开发/内网场景可关闭 HITL 鉴权而不改业务代码。
     */
    public function testHitlAuthDisabledAllowsAll(): void
    {
        $this->withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], function (): void {
            $auth = new WorkflowHitlAuth($this->hitlConfig(['hitl' => ['auth_enabled' => false]]));
            $auth->assertAuthorizedForUser(null, null);
            $this->addToAssertionCount(1);
        });
    }

    /**
     * 配置 auth_enabled=false（无 env）须关闭鉴权；(string)false 不得回落为默认开启。
     */
    public function testHitlAuthDisabledFromConfigBoolFalse(): void
    {
        $this->withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => null], function (): void {
            $auth = new WorkflowHitlAuth($this->hitlConfig(['hitl' => ['auth_enabled' => false]]));
            $this->assertFalse($auth->isEnabled());
            $auth->assertAuthorizedForUser(null, null);
            $this->addToAssertionCount(1);
        });
    }

    /**
     * 验证：require_assignee_match 时，resume 方 AuthUser.userId 须与 PauseNode assignee 一致。
     */
    public function testHitlAssigneeMatch(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause', new PauseNode('pause', ['assignee' => 'legal-team']))
            ->addEdge('start', 'pause'));

        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
            ->compile($registry->definition('hitl'));

        $pdo = new PDO('sqlite::memory:');
        WorkflowRunsSchemaInstaller::install($pdo);
        $store = new DbRunStore($pdo, $registry, 'workflow_runs');
        $engine = new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: $store,
        );

        $runId = $engine->start($compiled, []);
        $run = $engine->getRun($runId);
        $this->assertSame(RunStatus::WAITING, $run->status, 'waiting');

        $auth = new WorkflowHitlAuth($this->hitlConfig(['hitl' => [
            'auth_enabled' => true,
            'api_key' => 'secret-key',
            'allowed_roles' => ['operator', 'admin'],
            'require_assignee_match' => true,
        ]]));

        try {
            $auth->assertCanResumeForUser($run, new AuthUser(userId: 'wrong-team', roles: ['operator']));
            $this->assertTrue(false, 'should throw assignee mismatch');
        } catch (WorkflowPermissionException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'legal-team'), 'assignee message');
        }

        $auth->assertCanResumeForUser($run, new AuthUser(userId: 'legal-team', roles: ['operator']));
        $auth->assertCanResumeForUser($run, new AuthUser(userId: 'anyone', roles: ['admin']));
    }

    /**
     * 验证：resolveListAssigneeFilter 对非 admin 默认限定为当前 actor；admin 或鉴权关闭时可见全部。
     *
     * 列表 API 须防止普通操作员越权查看他人任务。
     */
    public function testResolveListAssigneeFilterScopesNonAdminToActor(): void
    {
        $auth = new WorkflowHitlAuth($this->hitlConfig());

        $this->assertSame('alice', $auth->resolveListAssigneeFilter(null, 'alice', 'operator'), 'non-admin defaults to actor');
        $this->assertSame('legal-team', $auth->resolveListAssigneeFilter('legal-team', 'alice', 'operator'), 'query assignee preserved');
        $this->assertSame(null, $auth->resolveListAssigneeFilter(null, 'alice', WorkflowHitlAuth::ADMIN_ROLE), 'admin sees all');
        $this->withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], function (): void {
            $this->assertSame(
                null,
                (new WorkflowHitlAuth($this->hitlConfig(['hitl' => ['auth_enabled' => false]])))
                    ->resolveListAssigneeFilter(null, 'alice', 'operator'),
                'auth disabled sees all',
            );
        });
    }

    /**
     * 验证：listPauseTasks(assignee) 按 assignee 过滤；null 返回全部。
     *
     * 与 RunStore.listWaiting 集成，覆盖多工作流多 assignee 场景。
     */
    public function testListPauseTasksScopesByAssigneeFilter(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('hitl_a', static fn () => WorkflowDefinition::create('hitl_a', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause', new PauseNode('pause', ['assignee' => 'legal-team']))
            ->addEdge('start', 'pause'));
        $registry->register('hitl_b', static fn () => WorkflowDefinition::create('hitl_b', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops-team']))
            ->addEdge('start', 'pause'));

        $compiler = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]));
        $store = new InMemoryRunStore();
        $engine = new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: $store,
        );

        $engine->start($compiler->compile($registry->definition('hitl_a')), []);
        $engine->start($compiler->compile($registry->definition('hitl_b')), []);

        $this->assertCount(2, $engine->listPauseTasks(null), 'no filter returns all');
        $this->assertCount(1, $engine->listPauseTasks('legal-team'), 'legal-team filter');
        $this->assertCount(1, $engine->listPauseTasks('ops-team'), 'ops-team filter');
        $this->assertCount(0, $engine->listPauseTasks('unknown'), 'unknown assignee empty');
    }

    /**
     * 验证：同一 WAITING run 被 CAS 认领后，第二次 saveIfStatus 失败且 engine.resume 被拒绝。
     *
     * 模拟多 Worker 并发 resume 的竞态防护。
     */
    public function testResumeCasPreventsDoubleResume(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops']))
            ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success(['done' => true])))
            ->addEdge('start', 'pause')
            ->addEdge('pause', 'done'));

        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
            ->compile($registry->definition('hitl'));

        $store = new InMemoryRunStore();
        $engine = new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: $store,
        );

        $runId = $engine->start($compiled, []);
        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status, 'waiting');

        $store->saveIfStatus($engine->getRun($runId), RunStatus::WAITING);
        $run = $engine->getRun($runId);
        $run->status = RunStatus::RUNNING;
        $run->updatedAt = WorkflowRunTime::now();
        $this->assertTrue($store->saveIfStatus($run, RunStatus::WAITING), 'first cas');

        $run2 = $engine->getRun($runId);
        $run2->status = RunStatus::RUNNING;
        $this->assertFalse($store->saveIfStatus($run2, RunStatus::WAITING), 'second cas fails');

        try {
            $engine->resume($runId, ['approved' => true]);
            $this->assertTrue(false, 'resume should fail after cas claim');
        } catch (WorkflowException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'not waiting'), 'resume blocked');
        }
    }

    /**
     * 验证：DbRunStore.saveIfStatus 在期望状态不匹配时返回 false；匹配时可更新状态。
     *
     * 覆盖已完成 run 不能按 WAITING 期望 CAS、以及 COMPLETED→WAITING 反向更新路径。
     */
    public function testDbRunStoreSaveIfStatus(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('x', static fn () => WorkflowDefinition::create('x', '1.0.0')
            ->addNode('a', new ClosureNode('a', static fn () => NodeExecutionResult::success())));

        $pdo = new PDO('sqlite::memory:');
        WorkflowRunsSchemaInstaller::install($pdo);
        $store = new DbRunStore($pdo, $registry, 'workflow_runs');
        $engine = new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: $store,
        );

        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
            ->compile($registry->definition('x'));
        $runId = $engine->start($compiled, []);
        $run = $engine->getRun($runId);

        $this->assertFalse($store->saveIfStatus($run, RunStatus::WAITING), 'completed run not waiting');
        $run->status = RunStatus::WAITING;
        $this->assertTrue($store->saveIfStatus($run, RunStatus::COMPLETED), 'cas from completed to waiting');
    }

    /**
     * 验证：engine.resume 在 CAS 持久化时将 pauseNodeId 置为 null，且 run 最终 COMPLETED。
     *
     * 通过 PauseNodeIdSpyRunStore 观测 saveIfStatus 写入时的 pauseNodeId。
     */
    public function testResumeCasPersistClearsPauseNodeId(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops']))
            ->addNode('done', new ClosureNode('done', static fn () => NodeExecutionResult::success(['done' => true])))
            ->addEdge('start', 'pause')
            ->addEdge('pause', 'done'));

        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
            ->compile($registry->definition('hitl'));

        $inner = new InMemoryRunStore();
        $store = new PauseNodeIdSpyRunStore($inner);
        $engine = new WorkflowEngine(
            plugins: new PluginManager([]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: $store,
        );

        $runId = $engine->start($compiled, []);
        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status, 'waiting');

        $engine->resume($runId, ['approved' => true]);
        $this->assertSame(null, $store->pauseNodeIdAtCas, 'cas persist clears pauseNodeId');
        $this->assertSame(RunStatus::COMPLETED, $engine->getRun($runId)->status, 'completed after resume');
    }

    /**
     * 验证：run 处于 WAITING（含多次 pause）时不触发 run.complete；最终 COMPLETED 时触发一次。
     *
     * 与 start 语义对齐：仅真正结束才计为完成，避免 WAITING 误释放资源。
     */
    public function testResumeDoesNotFireRunCompleteWhileWaiting(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause1', new PauseNode('pause1', ['assignee' => 'ops']))
            ->addNode('middle', new ClosureNode('middle', static fn () => NodeExecutionResult::success()))
            ->addNode('pause2', new PauseNode('pause2', ['assignee' => 'ops']))
            ->addEdge('start', 'pause1')
            ->addEdge('pause1', 'middle')
            ->addEdge('middle', 'pause2'));

        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
            ->compile($registry->definition('hitl'));

        $counter = new RunCompleteCounterPlugin();
        $engine = new WorkflowEngine(
            plugins: new PluginManager([$counter]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: new InMemoryRunStore(),
        );

        $runId = $engine->start($compiled, []);
        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status, 'waiting at pause1');
        $this->assertSame(0, $counter->count, 'start waiting does not fire run complete');

        $engine->resume($runId, ['approved' => true]);
        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status, 'waiting at pause2');
        $this->assertSame(0, $counter->count, 'resume to waiting does not fire run complete');

        $engine->resume($runId, ['approved' => true]);
        $this->assertSame(RunStatus::COMPLETED, $engine->getRun($runId)->status, 'completed');
        $this->assertSame(1, $counter->count, 'run complete fires once after final resume');
    }

    /**
     * 验证：cancel WAITING run 时触发 run.complete 一次，并释放 RateLimit 占位。
     *
     * WAITING 期间仍占用并发槽，cancel 须与正常结束一样清理插件状态。
     */
    public function testCancelWaitingFiresRunCompleteAndReleasesRateLimit(): void
    {
        $registry = new WorkflowRegistry();
        $registry->register('hitl', static fn () => WorkflowDefinition::create('hitl', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()))
            ->addNode('pause', new PauseNode('pause', ['assignee' => 'ops']))
            ->addEdge('start', 'pause'));

        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))
            ->compile($registry->definition('hitl'));

        $rateLimit = RateLimitPlugin::make(1);
        $counter = new RunCompleteCounterPlugin();
        $engine = new WorkflowEngine(
            plugins: new PluginManager([$rateLimit, $counter]),
            scheduler: new DagScheduler(ConditionEvaluatorFactory::create('symfony')),
            runStore: new InMemoryRunStore(),
        );

        $runId = $engine->start($compiled, []);
        $this->assertSame(RunStatus::WAITING, $engine->getRun($runId)->status, 'waiting before cancel');
        $this->assertSame(1, $rateLimit->activeRuns(), 'waiting run holds rate limit slot');
        $this->assertSame(0, $counter->count, 'waiting has not completed');

        $engine->cancel($runId);
        $this->assertSame(RunStatus::CANCELLED, $engine->getRun($runId)->status, 'cancelled waiting run');
        $this->assertSame(0, $rateLimit->activeRuns(), 'cancel releases rate limit slot');
        $this->assertSame(1, $counter->count, 'cancel waiting fires run complete once');
    }

    /**
     * 验证：WorkflowRunPresenter 默认 toArray 脱敏 data/nodeOutputs/agentOutputs；includeDetails 时返回完整内容。
     *
     * 列表/状态 API 不应泄露敏感业务字段。
     */
    public function testWorkflowRunPresenterRedactsDetailsByDefault(): void
    {
        $definition = WorkflowDefinition::create('secure_status', '1.0.0')
            ->addNode('start', new ClosureNode('start', static fn () => NodeExecutionResult::success()));
        $compiled = WorkflowComponentFactory::compiler(WorkflowConfig::fromArray([]))->compile($definition);
        $state = new \Swoolefy\Support\Workflow\State\WorkflowState([
            'secretInput' => 'should-not-leak',
            'feedback' => ['approved' => true],
        ]);
        $state->setNodeOutput('start', ['secretOutput' => 'hidden']);
        $state->setAgentOutput('agent-a', ['answer' => 'hidden']);

        $run = new WorkflowRun(
            runId: 'run-secure-status',
            compiled: $compiled,
            status: RunStatus::WAITING,
            state: $state,
            createdAt: WorkflowRunTime::now(),
            updatedAt: WorkflowRunTime::now(),
            currentNodeId: 'start',
            pauseNodeId: 'start',
        );

        $summary = WorkflowRunPresenter::toArray($run);
        $this->assertArrayNotHasKey('data', $summary, 'summary redacts data');
        $this->assertArrayNotHasKey('nodeOutputs', $summary, 'summary redacts node outputs');
        $this->assertArrayNotHasKey('agentOutputs', $summary, 'summary redacts agent outputs');
        $this->assertTrue($summary['waiting'] === true, 'summary keeps status metadata');

        $details = WorkflowRunPresenter::toArray($run, includeDetails: true);
        $this->assertSame('should-not-leak', $details['data']['secretInput'] ?? '', 'details include data');
        $this->assertSame('hidden', $details['nodeOutputs']['start']['secretOutput'] ?? '', 'details include node output');
        $this->assertSame('hidden', $details['agentOutputs']['agent-a']['answer'] ?? '', 'details include agent output');
    }
}

/**
 * RunStore 装饰器：在 saveIfStatus 时记录 run 的 pauseNodeId。
 *
 * 用于验证 resume CAS 持久化时是否正确清空 pauseNodeId。
 */
final class PauseNodeIdSpyRunStore implements RunStoreInterface, PauseTaskQueryableInterface
{
    public ?string $pauseNodeIdAtCas = null;

    public function __construct(private readonly InMemoryRunStore $inner)
    {
    }

    public function save(WorkflowRun $run): void
    {
        $this->inner->save($run);
    }

    public function saveIfStatus(WorkflowRun $run, RunStatus $expectedStatus): bool
    {
        $this->pauseNodeIdAtCas = $run->pauseNodeId;

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
 * 计数 run.complete 钩子触发次数的测试插件。
 *
 * 用于验证 WAITING 中间态与 cancel 场景下 run.complete 触发时机。
 */
final class RunCompleteCounterPlugin implements WorkflowPluginInterface
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

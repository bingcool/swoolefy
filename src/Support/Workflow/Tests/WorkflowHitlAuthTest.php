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
 *
 * ## 运行
 * ```bash
 * php src/Support/Workflow/Tests/WorkflowHitlAuthTest.php
 * ```
 */

use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
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
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\Plugin\Builtin\RateLimitPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginRegistry;
use Swoolefy\Support\Workflow\Plugin\WorkflowPluginInterface;
use Swoolefy\Support\Workflow\Condition\ConditionEvaluatorFactory;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\WorkflowComponentFactory;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowHitlAuth;
use Swoolefy\Support\Workflow\WorkflowRegistry;
use Swoolefy\Support\Workflow\Tests\WorkflowRunsSchemaInstaller;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// 通用断言与输出
// ---------------------------------------------------------------------------

/** 断言条件为真，否则抛 RuntimeException 使单测失败。 */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 在 CLI 输出 [PASS] 标记。 */
function pass(string $name): void
{
    echo "[PASS] {$name}\n";
}

// ---------------------------------------------------------------------------
// 环境变量夹具
// ---------------------------------------------------------------------------

/**
 * 在回调执行期间临时设置/清除环境变量，结束后恢复原值。
 *
 * 用于测试 WORKFLOW_HITL_AUTH_ENABLED 等开关，避免污染进程全局状态。
 *
 * @param array<string, string|null> $vars null 表示临时 unset 该变量
 * @param callable(): void $callback 在修改后的环境中执行的测试逻辑
 */
function withHitlEnv(array $vars, callable $callback): void
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

// ---------------------------------------------------------------------------
// 测试用 Spy / 插件
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// 配置夹具
// ---------------------------------------------------------------------------

/**
 * 构造启用 HITL 鉴权的 WorkflowConfig，支持数组覆盖默认项。
 *
 * 默认：memory store、auth_enabled、api_key、allowed_roles、require_assignee_match。
 */
function hitlConfig(array $overrides = []): WorkflowConfig
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

// ---------------------------------------------------------------------------
// WorkflowHitlAuth 凭证校验
// ---------------------------------------------------------------------------

/**
 * 验证：提供正确 API Key 时 assertAuthorized 通过。
 *
 * 生产 API 网关常用 Key 鉴权，须支持无角色仅 Key 的场景。
 */
function testHitlAuthWithValidApiKey(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());
    $auth->assertAuthorized('secret-key', null);
    pass('hitl auth valid api key');
}

/**
 * 验证：提供 allowed_roles 内角色时 assertAuthorized 通过（无需 Key）。
 *
 * 支持基于角色的内部服务调用。
 */
function testHitlAuthWithValidRole(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());
    $auth->assertAuthorized(null, 'admin');
    pass('hitl auth valid role');
}

/**
 * 验证：鉴权开启且 Key 与角色均缺失时抛出 WorkflowPermissionException。
 *
 * 防止匿名 resume/list 操作。
 */
function testHitlAuthRejectsMissingCredentials(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());
    try {
        $auth->assertAuthorized(null, null);
        assertTrue(false, 'should throw');
    } catch (WorkflowPermissionException) {
    }
    pass('hitl auth rejects missing credentials');
}

/**
 * 验证：auth_enabled=false 或环境变量关闭时，无凭证也可通过鉴权。
 *
 * 开发/内网场景可关闭 HITL 鉴权而不改业务代码。
 */
function testHitlAuthDisabledAllowsAll(): void
{
    withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], static function (): void {
        $auth = new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false]]));
        $auth->assertAuthorized(null, null);
    });
    pass('hitl auth disabled allows all');
}

/**
 * 验证：require_assignee_match 时，resume 方 assignee 须与 PauseNode 配置一致。
 *
 * 基于真实 WAITING run（DbRunStore）验证 assertCanResume 行为与错误信息。
 */
function testHitlAssigneeMatch(): void
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
    assertTrue($run->status === RunStatus::WAITING, 'waiting');

    withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], static function () use ($run): void {
        $auth = new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false, 'require_assignee_match' => true]]));

        try {
            $auth->assertCanResume($run, null, 'wrong-team', null);
            assertTrue(false, 'should throw assignee mismatch');
        } catch (WorkflowPermissionException $e) {
            assertTrue(str_contains($e->getMessage(), 'legal-team'), 'assignee message');
        }

        $auth->assertCanResume($run, null, 'legal-team', null);
    });
    pass('hitl assignee match');
}

// ---------------------------------------------------------------------------
// 暂停任务列表与 assignee 过滤
// ---------------------------------------------------------------------------

/**
 * 验证：resolveListAssigneeFilter 对非 admin 默认限定为当前 actor；admin 或鉴权关闭时可见全部。
 *
 * 列表 API 须防止普通操作员越权查看他人任务。
 */
function testResolveListAssigneeFilterScopesNonAdminToActor(): void
{
    $auth = new WorkflowHitlAuth(hitlConfig());

    assertTrue($auth->resolveListAssigneeFilter(null, 'alice', 'operator') === 'alice', 'non-admin defaults to actor');
    assertTrue($auth->resolveListAssigneeFilter('legal-team', 'alice', 'operator') === 'legal-team', 'query assignee preserved');
    assertTrue($auth->resolveListAssigneeFilter(null, 'alice', WorkflowHitlAuth::ADMIN_ROLE) === null, 'admin sees all');
    withHitlEnv(['WORKFLOW_HITL_AUTH_ENABLED' => '0'], static function (): void {
        assertTrue(
            (new WorkflowHitlAuth(hitlConfig(['hitl' => ['auth_enabled' => false]])))
                ->resolveListAssigneeFilter(null, 'alice', 'operator') === null,
            'auth disabled sees all',
        );
    });

    pass('resolve list assignee filter');
}

/**
 * 验证：listPauseTasks(assignee) 按 assignee 过滤；null 返回全部。
 *
 * 与 RunStore.listWaiting 集成，覆盖多工作流多 assignee 场景。
 */
function testListPauseTasksScopesByAssigneeFilter(): void
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

    assertTrue(count($engine->listPauseTasks(null)) === 2, 'no filter returns all');
    assertTrue(count($engine->listPauseTasks('legal-team')) === 1, 'legal-team filter');
    assertTrue(count($engine->listPauseTasks('ops-team')) === 1, 'ops-team filter');
    assertTrue(count($engine->listPauseTasks('unknown')) === 0, 'unknown assignee empty');

    pass('list pause tasks scopes by assignee filter');
}

// ---------------------------------------------------------------------------
// resume CAS 与 DbRunStore
// ---------------------------------------------------------------------------

/**
 * 验证：同一 WAITING run 被 CAS 认领后，第二次 saveIfStatus 失败且 engine.resume 被拒绝。
 *
 * 模拟多 Worker 并发 resume 的竞态防护。
 */
function testResumeCasPreventsDoubleResume(): void
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
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting');

    $store->saveIfStatus($engine->getRun($runId), RunStatus::WAITING);
    $run = $engine->getRun($runId);
    $run->status = RunStatus::RUNNING;
    $run->updatedAt = WorkflowRunTime::now();
    assertTrue($store->saveIfStatus($run, RunStatus::WAITING), 'first cas');

    $run2 = $engine->getRun($runId);
    $run2->status = RunStatus::RUNNING;
    assertTrue(!$store->saveIfStatus($run2, RunStatus::WAITING), 'second cas fails');

    try {
        $engine->resume($runId, ['approved' => true]);
        assertTrue(false, 'resume should fail after cas claim');
    } catch (WorkflowException $e) {
        assertTrue(str_contains($e->getMessage(), 'not waiting'), 'resume blocked');
    }

    pass('resume cas prevents double resume');
}

/**
 * 验证：DbRunStore.saveIfStatus 在期望状态不匹配时返回 false；匹配时可更新状态。
 *
 * 覆盖已完成 run 不能按 WAITING 期望 CAS、以及 COMPLETED→WAITING 反向更新路径。
 */
function testDbRunStoreSaveIfStatus(): void
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

    assertTrue(!$store->saveIfStatus($run, RunStatus::WAITING), 'completed run not waiting');
    $run->status = RunStatus::WAITING;
    assertTrue($store->saveIfStatus($run, RunStatus::COMPLETED), 'cas from completed to waiting');

    pass('db run store saveIfStatus');
}

/**
 * 验证：engine.resume 在 CAS 持久化时将 pauseNodeId 置为 null，且 run 最终 COMPLETED。
 *
 * 通过 PauseNodeIdSpyRunStore 观测 saveIfStatus 写入时的 pauseNodeId。
 */
function testResumeCasPersistClearsPauseNodeId(): void
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
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting');

    $engine->resume($runId, ['approved' => true]);
    assertTrue($store->pauseNodeIdAtCas === null, 'cas persist clears pauseNodeId');
    assertTrue($engine->getRun($runId)->status === RunStatus::COMPLETED, 'completed after resume');

    pass('resume cas persist clears pause node id');
}

// ---------------------------------------------------------------------------
// run.complete 与 cancel 生命周期
// ---------------------------------------------------------------------------

/**
 * 验证：run 处于 WAITING（含多次 pause）时不触发 run.complete；最终 COMPLETED 时触发一次。
 *
 * 与 start 语义对齐：仅真正结束才计为完成，避免 WAITING 误释放资源。
 */
function testResumeDoesNotFireRunCompleteWhileWaiting(): void
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
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting at pause1');
    assertTrue($counter->count === 0, 'start waiting does not fire run complete');

    $engine->resume($runId, ['approved' => true]);
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting at pause2');
    assertTrue($counter->count === 0, 'resume to waiting does not fire run complete');

    $engine->resume($runId, ['approved' => true]);
    assertTrue($engine->getRun($runId)->status === RunStatus::COMPLETED, 'completed');
    assertTrue($counter->count === 1, 'run complete fires once after final resume');

    pass('resume run complete aligned with start');
}

/**
 * 验证：cancel WAITING run 时触发 run.complete 一次，并释放 RateLimit 占位。
 *
 * WAITING 期间仍占用并发槽，cancel 须与正常结束一样清理插件状态。
 */
function testCancelWaitingFiresRunCompleteAndReleasesRateLimit(): void
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
    assertTrue($engine->getRun($runId)->status === RunStatus::WAITING, 'waiting before cancel');
    assertTrue($rateLimit->activeRuns() === 1, 'waiting run holds rate limit slot');
    assertTrue($counter->count === 0, 'waiting has not completed');

    $engine->cancel($runId);
    assertTrue($engine->getRun($runId)->status === RunStatus::CANCELLED, 'cancelled waiting run');
    assertTrue($rateLimit->activeRuns() === 0, 'cancel releases rate limit slot');
    assertTrue($counter->count === 1, 'cancel waiting fires run complete once');

    pass('cancel waiting releases rate limit');
}

// ---------------------------------------------------------------------------
// API 响应脱敏
// ---------------------------------------------------------------------------

/**
 * 验证：WorkflowRunPresenter 默认 toArray 脱敏 data/nodeOutputs/agentOutputs；includeDetails 时返回完整内容。
 *
 * 列表/状态 API 不应泄露敏感业务字段。
 */
function testWorkflowRunPresenterRedactsDetailsByDefault(): void
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
    assertTrue(!array_key_exists('data', $summary), 'summary redacts data');
    assertTrue(!array_key_exists('nodeOutputs', $summary), 'summary redacts node outputs');
    assertTrue(!array_key_exists('agentOutputs', $summary), 'summary redacts agent outputs');
    assertTrue($summary['waiting'] === true, 'summary keeps status metadata');

    $details = WorkflowRunPresenter::toArray($run, includeDetails: true);
    assertTrue(($details['data']['secretInput'] ?? '') === 'should-not-leak', 'details include data');
    assertTrue(($details['nodeOutputs']['start']['secretOutput'] ?? '') === 'hidden', 'details include node output');
    assertTrue(($details['agentOutputs']['agent-a']['answer'] ?? '') === 'hidden', 'details include agent output');

    pass('workflow run presenter redacts details by default');
}

// ---------------------------------------------------------------------------
// 执行入口
// ---------------------------------------------------------------------------

$tests = [
    'hitl auth valid api key' => 'testHitlAuthWithValidApiKey',
    'hitl auth valid role' => 'testHitlAuthWithValidRole',
    'hitl auth rejects missing credentials' => 'testHitlAuthRejectsMissingCredentials',
    'hitl auth disabled allows all' => 'testHitlAuthDisabledAllowsAll',
    'hitl assignee match' => 'testHitlAssigneeMatch',
    'resolve list assignee filter' => 'testResolveListAssigneeFilterScopesNonAdminToActor',
    'list pause tasks scopes by assignee filter' => 'testListPauseTasksScopesByAssigneeFilter',
    'resume cas prevents double resume' => 'testResumeCasPreventsDoubleResume',
    'resume cas persist clears pause node id' => 'testResumeCasPersistClearsPauseNodeId',
    'resume run complete aligned with start' => 'testResumeDoesNotFireRunCompleteWhileWaiting',
    'cancel waiting releases rate limit' => 'testCancelWaitingFiresRunCompleteAndReleasesRateLimit',
    'workflow run presenter redacts details' => 'testWorkflowRunPresenterRedactsDetailsByDefault',
    'db run store saveIfStatus' => 'testDbRunStoreSaveIfStatus',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    $fn();
    $passed++;
}

echo "\nAll {$passed} workflow HITL / CAS tests passed.\n";

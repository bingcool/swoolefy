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

namespace PhpUintTest\Unit\Support\Workflow;

use Swoolefy\Support\Neuron\Memory\ChatHistoryFactory;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Tests\Fixtures\DecisionDto;
use Swoolefy\Support\Workflow\Condition\SymfonyExpressionLanguageEvaluator;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowCompiler;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Engine\DagScheduler;
use Swoolefy\Support\Workflow\Engine\NodeExecutionResult;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Exception\WorkflowCompileException;
use Swoolefy\Support\Workflow\Node\ClosureNode;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Swoolefy\Support\Workflow\Plugin\PluginManager;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Swoolefy\Support\Workflow\Tests\Fixtures\OrderProcessingFixtureWorkflow;
use Swoolefy\Support\Workflow\Workflow;
use Swoolefy\Support\Workflow\WorkflowBootstrap;
use PhpUintTest\TestCase;
use NeuronAI\Chat\History\ChatHistoryInterface;
use RuntimeException;

/**
 * Phase 1 工作流引擎回归测试。
 *
 * ## 覆盖范围
 * | 区域 | 要点 |
 * |------|------|
 * | WorkflowCompiler | 环检测、条件边 default 必填、单入口约束 |
 * | 条件路由 | 高置信度 payment / 低置信度 manual_review / 拒绝 reject |
 * | Workflow Facade | fromDefinition()->compile()->start() 链式调用 |
 * | 插件 | TracingPlugin span 收集 |
 * | Neuron | ChatHistoryFactory 内存记忆 |
 * | Symfony EL | decision 分支表达式求值 |
 */
final class WorkflowPhase1Test extends TestCase
{
    /**
     * 验证：含 a↔b 双向边的图在编译时应抛出 WorkflowCompileException。
     *
     * 为何重要：DAG 不允许环，否则调度器可能死循环。
     */
    public function testCompilerDetectsCycle(): void
    {
        $definition = WorkflowDefinition::create('cycle')
            ->addNode('a', new ClosureNode('a', fn () => NodeExecutionResult::success()))
            ->addNode('b', new ClosureNode('b', fn () => NodeExecutionResult::success()))
            ->addEdge('a', 'b')
            ->addEdge('b', 'a');

        $compiler = WorkflowBootstrap::compiler();

        try {
            $compiler->compile($definition);
            throw new RuntimeException('Expected cycle detection to fail');
        } catch (WorkflowCompileException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * 验证：条件边未配置 default 分支时编译失败，且错误信息提及 default。
     *
     * 为何重要：运行时须保证每条出边有且仅有一条可走路径，缺 default 会导致未定义行为。
     */
    public function testCompilerRequiresConditionalDefault(): void
    {
        $definition = WorkflowDefinition::create('no_default')
            ->addNode('a', new ClosureNode('a', fn () => NodeExecutionResult::success()))
            ->addNode('b', new ClosureNode('b', fn () => NodeExecutionResult::success()))
            ->addConditionalEdges('a', [
                'b' => EdgeCondition::when('true'),
            ]);

        try {
            (new WorkflowCompiler())->compile($definition);
            throw new RuntimeException('Expected missing default to fail at compile time');
        } catch (WorkflowCompileException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'default'), 'message mentions default');
        }
    }

    /**
     * 验证：存在多个无入边入口节点时编译失败。
     *
     * 为何重要：引擎只执行单一入口，多入口图语义不明确，应在编译期拒绝。
     */
    public function testCompilerRejectsMultipleEntryNodes(): void
    {
        $definition = WorkflowDefinition::create('multi_entry')
            ->addNode('entry_a', new ClosureNode('entry_a', fn () => NodeExecutionResult::success()))
            ->addNode('entry_b', new ClosureNode('entry_b', fn () => NodeExecutionResult::success()))
            ->addNode('join', new ClosureNode('join', fn () => NodeExecutionResult::success()))
            ->addEdge('entry_a', 'join')
            ->addEdge('entry_b', 'join');

        try {
            (new WorkflowCompiler())->compile($definition);
            throw new RuntimeException('Expected multiple entry nodes to fail at compile time');
        } catch (WorkflowCompileException $e) {
            $this->assertTrue(str_contains($e->getMessage(), 'exactly one entry'), 'message mentions single entry');
        }
    }

    /**
     * 验证：高置信度批准（confidence≥0.8）应直连 payment，跳过 manual_review。
     *
     * 同时确认 TracingPlugin 记录了足够 span，便于可观测性回归。
     */
    public function testConditionalRoutingHighConfidence(): void
    {
        $tracing = new TracingPlugin();
        $engine = new WorkflowEngine(
            plugins: new PluginManager([new RetryPlugin(), $tracing]),
            scheduler: new DagScheduler(new SymfonyExpressionLanguageEvaluator()),
        );

        $definition = OrderProcessingFixtureWorkflow::definition(new NeuronFactory(), static function ($ctx, $state): DecisionDto {
            $dto = new DecisionDto();
            $dto->approved = true;
            $dto->confidence = 0.95;
            $dto->reason = 'High confidence approve';

            return $dto;
        });

        $compiler = new WorkflowCompiler(new SymfonyExpressionLanguageEvaluator());
        $compiled = $compiler->compile($definition);

        $runId = $engine->start($compiled, [
            'orderId' => 10001,
            'userId' => 'u123',
            'sessionId' => 's-abc',
        ]);

        $run = $engine->getRun($runId);
        $this->assertTrue($run->status->value === 'completed', 'Run should complete');
        $this->assertTrue($run->state->get('paymentStatus') === 'captured', 'Should route to payment directly');
        $this->assertTrue($run->state->get('manualReview') !== true, 'Should skip manual review');

        $decision = $run->state->dto(DecisionDto::class);
        $this->assertTrue($decision->approved === true, 'Decision should be approved');
        $this->assertTrue(count($tracing->spans()) >= 4, 'Tracing plugin should record spans');
    }

    /**
     * 验证：低置信度批准应经 manual_review 节点后再到 payment。
     *
     * 覆盖「批准但需人工复核」业务路径。
     */
    public function testConditionalRoutingManualReview(): void
    {
        $engine = WorkflowBootstrap::engine();

        $definition = OrderProcessingFixtureWorkflow::definition(new NeuronFactory(), static function ($ctx, $state): DecisionDto {
            $dto = new DecisionDto();
            $dto->approved = true;
            $dto->confidence = 0.55;
            $dto->reason = 'Low confidence approve';

            return $dto;
        });

        $compiled = WorkflowBootstrap::compiler()->compile($definition);
        $runId = $engine->start($compiled, ['orderId' => 10002, 'sessionId' => 's-def']);
        $run = $engine->getRun($runId);

        $this->assertTrue($run->state->get('manualReview') === true, 'Should pass manual review');
        $this->assertTrue($run->state->get('paymentStatus') === 'captured', 'Should continue to payment');
    }

    /**
     * 验证：拒绝决策应路由到 reject，且不执行 payment 捕获。
     *
     * 确保拒绝路径与支付路径互斥。
     */
    public function testConditionalRoutingReject(): void
    {
        $engine = WorkflowBootstrap::engine();

        $definition = OrderProcessingFixtureWorkflow::definition(new NeuronFactory(), static function ($ctx, $state): DecisionDto {
            $dto = new DecisionDto();
            $dto->approved = false;
            $dto->confidence = 0.99;
            $dto->reason = 'Rejected by policy';

            return $dto;
        });

        $compiled = WorkflowBootstrap::compiler()->compile($definition);
        $runId = $engine->start($compiled, ['orderId' => 10003, 'sessionId' => 's-ghi']);
        $run = $engine->getRun($runId);

        $this->assertTrue($run->state->get('orderStatus') === 'rejected', 'Should route to reject');
        $this->assertTrue($run->state->get('paymentStatus') === null, 'Should not capture payment');
    }

    /**
     * 验证：Workflow Facade 链式 compile/start 可完成整次 Run。
     *
     * 非协程 CLI 无 Context 缓存，须显式传入同一 Engine 实例。
     */
    public function testWorkflowFacade(): void
    {
        WorkflowBootstrap::reset();

        $engine = WorkflowBootstrap::engine();

        $runId = Workflow::fromDefinition(OrderProcessingFixtureWorkflow::definition(new NeuronFactory()))
            ->compile()
            ->start([
                'orderId' => 10004,
                'sessionId' => 's-jkl',
            ], $engine);

        $run = $engine->getRun($runId);
        $this->assertTrue($run->status->value === 'completed', 'Facade run should complete');
    }

    /**
     * 验证：ChatHistoryFactory::inMemory 返回可用的进程内会话记忆实现。
     *
     * 供 Neuron Agent 单测与 CLI 场景使用，无需外部存储。
     */
    public function testChatHistoryFactoryInMemory(): void
    {
        $history = ChatHistoryFactory::inMemory();
        $this->assertTrue($history instanceof ChatHistoryInterface, 'ChatHistoryFactory should return chat history');
    }

    /**
     * 验证：Symfony ExpressionLanguage 能正确求值 decision 分支条件。
     *
     * 条件边依赖 data['decision'] 结构，本用例隔离验证求值器本身。
     */
    public function testExpressionEvaluator(): void
    {
        $evaluator = new SymfonyExpressionLanguageEvaluator();
        $state = WorkflowState::fromInput([
            'decision' => ['approved' => true, 'confidence' => 0.9],
        ]);

        $this->assertTrue(
            $evaluator->evaluate(
                EdgeCondition::when("data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8"),
                $state,
            ),
            'Symfony EL should evaluate decision branch',
        );
    }
}

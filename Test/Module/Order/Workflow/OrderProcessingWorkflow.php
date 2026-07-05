<?php

declare(strict_types=1);

namespace Test\Module\Order\Workflow;

use Swoolefy\Support\AI\Node\AINode;
use Swoolefy\Support\Neuron\NeuronFactory;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Test\Module\Order\Agent\OrderDecisionAgent;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\Node\CompleteNode;
use Test\Module\Order\Node\ManualReviewNode;
use Test\Module\Order\Node\PaymentNode;
use Test\Module\Order\Node\RejectNode;
use Test\Module\Order\Node\ValidateNode;

/**
 * 订单处理工作流（workflowId: order_processing，version: 1.1.0）。
 *
 * 端到端路径：校验入参 → AI 风控决策 → 按结果路由到支付 / 人工复核 / 拒绝 → 完成（成功路径）。
 *
 * DAG（条件边按声明顺序求值，首个为 true 的分支获胜）：
 *
 *   validate
 *      │
 *      ▼
 *   ai_decision ──┬── approved && confidence >= 0.8 ──► payment ──► complete
 *                 ├── approved && confidence <  0.8 ──► manual_review ──► payment ──► complete
 *                 └── approved == false ──────────────► reject
 *                 （无分支命中时的 default）──────────► reject
 *
 * 各节点写入的 state 字段（细节见对应 Node）：
 *   - validate:       orderId、order{}、orderStatus=validated、prompt（供 AI 使用）
 *   - ai_decision:    decision{approved, confidence, reason}，类型为 OrderDecisionDto
 *   - payment:        payment{}、paymentStatus、orderStatus=paid
 *   - manual_review:  manualReview、orderStatus=manual_review[_passed]
 *   - reject:         rejectReason、orderStatus=rejected
 *   - complete:       orderStatus=completed
 *
 * 用法示例：
 *   // 生产 / 演示：走 NeuronFactory → OrderDecisionAgent（Provider / MCP / 租户隔离）
 *   $def = OrderProcessingWorkflow::definition($neuronFactory);
 *
 *   // 单测 / HTTP 演示：注入固定决策
 *   $def = OrderProcessingWorkflow::definition($neuronFactory, static fn ($ctx, $state) => $dto);
 *
 *   // 人机协同（HITL）：在 manual_review 暂停，直到 engine->resume($runId, $feedback)
 *   $def = OrderProcessingWorkflow::definition($neuronFactory, null, pauseForHumanReview: true);
 *
 * @see Test\Module\Order\README.md
 * @see docs/swoolefyAI.md §4.1 order_processing 示例
 */
final class OrderProcessingWorkflow
{
    /**
     * 构建纯工作流定义（仅描述 DAG，不启动引擎）。
     *
     * 运行时入口统一为：compile() 之后调用 WorkflowEngine::start()。
     *
     * @param NeuronFactory         $neuronFactory        注入 Provider / MCP / 租户隔离
     * @param callable|null         $aiExecutor           可选，覆盖 ai_decision 的执行逻辑（mock）。
     *                                                  签名：function (RunContext $ctx, WorkflowState $state): OrderDecisionDto
     *                                                  为 null 时，AINode 经 NeuronFactory 创建 OrderDecisionAgent，
     *                                                  并将结构化结果写入 state 键 "decision"。
     * @param bool                  $pauseForHumanReview  为 true 时，ManualReviewNode 返回 WAITING，
     *                                                  需通过 WorkflowEngine::resume() 恢复运行。
     *                                                  为 false（默认）时，低置信度订单自动通过复核并继续支付
     *                                                  （适合演示与单测）。
     */
    public static function definition(
        NeuronFactory $neuronFactory,
        ?callable $aiExecutor = null,
        bool $pauseForHumanReview = false,
    ): WorkflowDefinition {
        // --- AI 风控节点 -----------------------------------------------------
        // AINode 封装 OrderDecisionAgent，强制结构化输出为 OrderDecisionDto，
        // 结果写入 state["decision"]（下方 registerSchema 同步注册）。
        // promptKey("prompt")：从 state["prompt"] 读取提示词；
        // ValidateNode 会根据订单快照生成该字段，使模型能看到 orderId / amount / items。
        $aiBuilder = AINode::make('ai_decision')
            ->agent(OrderDecisionAgent::class)
            ->structured(OrderDecisionDto::class, outputKey: 'decision')
            ->promptKey('prompt');

        // 注入 callable 可跳过真实 LLM（单测、OrderWorkflowDemoController 的 mockDecision）。
        // 返回值须为 OrderDecisionDto（或字段兼容的结构）。
        if ($aiExecutor !== null) {
            $aiBuilder->executor($aiExecutor);
        }

        return WorkflowDefinition::create('order_processing', '1.1.0')
            // 可选元数据，供注册中心 / 运维看板使用（引擎本身不依赖）。
            ->metadata([
                'owner' => 'order-team',
                'description' => 'Order processing with AI risk decision routing',
            ])
            // RetryPlugin：按插件策略重试瞬时节点失败。
            // TracingPlugin：输出 run/node 生命周期追踪，便于可观测性。
            ->plugins(RetryPlugin::class, TracingPlugin::class)
            // 将 state["decision"] 绑定到 OrderDecisionDto，供校验与 dto() 辅助方法使用。
            ->registerSchema('decision', OrderDecisionDto::class)

            // --- 节点（id 须与边的端点一致）----------------------------------
            // validate：校验 orderId，规范化 userId/amount/items，写入订单快照。
            ->addNode('validate', new ValidateNode('validate'))
            // ai_decision：产出 decision{approved, confidence, reason}。
            ->addNode('ai_decision', $aiBuilder->build(neuronFactory: $neuronFactory))
            // payment：模拟扣款；在其他流程中可配合 Saga 补偿（退款）。
            ->addNode('payment', new PaymentNode('payment'))
            // manual_review：低置信度闸门；可选 HITL 暂停（见 $pauseForHumanReview）。
            ->addNode('manual_review', new ManualReviewNode('manual_review', $pauseForHumanReview))
            // reject：拒绝终态（不支付）。
            ->addNode('reject', new RejectNode('reject'))
            // complete：支付成功后的完成终态。
            ->addNode('complete', new CompleteNode('complete'))

            // --- 固定边 ------------------------------------------------------
            // 校验通过后始终进入 AI 决策。
            ->addEdge('validate', 'ai_decision')

            // --- 自 ai_decision 出发的条件边 ---------------------------------
            // 表达式由 ExpressionEvaluator 基于 state.data 求值：
            //   data['decision'][...] 对应 AINode 写入的字段（outputKey: decision）。
            // 分支顺序重要：第一个条件为 true 的分支获胜。
            // 阈值 0.8 为演示用风控策略（高置信度直接支付）。
            ->addConditionalEdges('ai_decision', [
                // 高置信度批准 → 直接支付。
                'payment' => EdgeCondition::when(
                    "data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8",
                ),
                // 批准但不确定 → 人工（或自动）复核，再支付。
                'manual_review' => EdgeCondition::when(
                    "data['decision']['approved'] == true and data['decision']['confidence'] < 0.8",
                ),
                // 模型明确拒绝。
                'reject' => EdgeCondition::when("data['decision']['approved'] == false"),
            ], default: 'reject')
            // 复核结束（自动通过或 resume）后始终尝试支付。
            // 注意：若 HITL feedback 中 approved=false，ManualReviewNode 会标记 rejected，
            // 但本边仍指向 payment；若需要「复核拒绝」路由，请改为条件边扩展。
            ->addEdge('manual_review', 'payment')
            // 支付成功 → 标记订单完成。
            ->addEdge('payment', 'complete');
    }
}

<?php

declare(strict_types=1);

namespace Test\Module\Order\Workflow;

use Swoolefy\Support\AI\Node\AINode;
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
 * 订单处理工作流 —— 校验 → AI 风控决策 → 支付 / 人工复核 / 拒绝 → 完成。
 *
 *   validate → ai_decision ─┬─ approved && confidence>=0.8 → payment → complete
 *                           ├─ approved && confidence<0.8  → manual_review → payment → complete
 *                           └─ rejected                    → reject
 *
 * @see docs/swoolefyAI.md §4.1 order_processing 示例
 */
final class OrderProcessingWorkflow
{
    /**
     * @param callable|null $aiExecutor 单测注入 mock；null 时使用 OrderDecisionAgent（默认 Provider / Fake 回退）
     * @param bool          $pauseForHumanReview 低置信度时是否 HITL 暂停（默认自动通过）
     */
    public static function definition(
        ?callable $aiExecutor = null,
        bool $pauseForHumanReview = false,
    ): WorkflowDefinition {
        $aiBuilder = AINode::make('ai_decision')
            ->agent(OrderDecisionAgent::class)
            ->structured(OrderDecisionDto::class, outputKey: 'decision')
            ->promptKey('prompt');

        if ($aiExecutor !== null) {
            $aiBuilder->executor($aiExecutor);
        }

        return WorkflowDefinition::create('order_processing', '1.1.0')
            ->metadata([
                'owner' => 'order-team',
                'description' => 'Order processing with AI risk decision routing',
            ])
            ->plugins(RetryPlugin::class, TracingPlugin::class)
            ->registerSchema('decision', OrderDecisionDto::class)
            ->addNode('validate', new ValidateNode('validate'))
            ->addNode('ai_decision', $aiBuilder->build())
            ->addNode('payment', new PaymentNode('payment'))
            ->addNode('manual_review', new ManualReviewNode('manual_review', $pauseForHumanReview))
            ->addNode('reject', new RejectNode('reject'))
            ->addNode('complete', new CompleteNode('complete'))
            ->addEdge('validate', 'ai_decision')
            ->addConditionalEdges('ai_decision', [
                'payment' => EdgeCondition::when(
                    "data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8",
                ),
                'manual_review' => EdgeCondition::when(
                    "data['decision']['approved'] == true and data['decision']['confidence'] < 0.8",
                ),
                'reject' => EdgeCondition::when("data['decision']['approved'] == false"),
            ], default: 'reject')
            ->addEdge('manual_review', 'payment')
            ->addEdge('payment', 'complete');
    }
}

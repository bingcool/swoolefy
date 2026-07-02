<?php

declare(strict_types=1);

namespace Test\Module\Order\Workflow;

use Swoolefy\Support\AI\Node\AINode;
use Swoolefy\Support\Workflow\Definition\EdgeCondition;
use Swoolefy\Support\Workflow\Definition\WorkflowDefinition;
use Swoolefy\Support\Workflow\Plugin\Builtin\RetryPlugin;
use Swoolefy\Support\Workflow\Plugin\Builtin\TracingPlugin;
use Test\Module\Order\Dto\OrderDecisionDto;
use Test\Module\Order\Node\ManualReviewNode;
use Test\Module\Order\Node\PaymentNode;
use Test\Module\Order\Node\RejectNode;
use Test\Module\Order\Node\ValidateNode;

/**
 * Phase 1 参考工作流 —— 订单处理 + AI 三分支路由。
 *
 * 流程图：
 *   validate → ai_decision ─┬─ (approved && confidence>=0.8) → payment
 *                           ├─ (approved && confidence<0.8)  → manual_review → payment
 *                           └─ (rejected)                    → reject
 *
 * 演示能力：
 *   - WorkflowDefinition 三层 API
 *   - AINodeBuilder + OrderDecisionDto 结构化输出
 *   - Symfony EL 条件边读 data['decision']
 *   - RetryPlugin + TracingPlugin
 *
 * @see swoolefyAI.md §4.1 order_processing 示例
 */
final class OrderProcessingWorkflow
{
    /**
     * 构建订单处理工作流定义。
     *
     * @param callable|null $aiExecutor 注入 mock AI（单测用）；null 使用默认 stub
     */
    public static function definition(
        ?callable $aiExecutor = null,
    ): WorkflowDefinition {
        $aiNode = AINode::make('ai_decision')
            ->structured(OrderDecisionDto::class, outputKey: 'decision')
            ->memory(threadIdKey: 'sessionId')
            ->executor($aiExecutor ?? self::defaultAiExecutor(...));

        return WorkflowDefinition::create('order_processing', '1.0.0')
            ->metadata(['owner' => 'order-team', 'description' => 'Order processing with AI decision routing'])
            ->plugins(RetryPlugin::class, TracingPlugin::class)
            ->registerSchema('decision', OrderDecisionDto::class)
            ->addNode('validate', new ValidateNode('validate'))
            ->addNode('ai_decision', $aiNode->build())
            ->addNode('payment', new PaymentNode('payment'))
            ->addNode('manual_review', new ManualReviewNode('manual_review'))
            ->addNode('reject', new RejectNode('reject'))
            ->addEdge('validate', 'ai_decision')
            ->addConditionalEdges('ai_decision', [
                'payment' => EdgeCondition::when("data['decision']['approved'] == true and data['decision']['confidence'] >= 0.8"),
                'manual_review' => EdgeCondition::when("data['decision']['approved'] == true and data['decision']['confidence'] < 0.8"),
                'reject' => EdgeCondition::when("data['decision']['approved'] == false"),
            ], default: 'reject')
            ->addEdge('manual_review', 'payment');
    }

    /**
     * 默认 AI 执行器（无 LLM 时的本地 stub）。
     * 返回高置信度批准，走 payment 直连分支。
     */
    private static function defaultAiExecutor(
        \Swoolefy\Support\Workflow\Engine\RunContext $ctx,
        \Swoolefy\Support\Workflow\State\WorkflowState $state,
    ): OrderDecisionDto {
        $dto = new OrderDecisionDto();
        $dto->approved = true;
        $dto->confidence = 0.91;
        $dto->reason = 'Mock AI decision for demo';

        return $dto;
    }
}

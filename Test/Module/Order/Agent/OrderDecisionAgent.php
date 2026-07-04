<?php

declare(strict_types=1);

namespace Test\Module\Order\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

/**
 * 订单决策 Agent —— 使用 neuron_ai.php 默认 Provider；无凭证时 FakeAIProvider 回退。
 */
final class OrderDecisionAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        // 结构化输出演示回退（与 OrderDecisionDto 字段一致）
        return FakeAIProvider::make(new AssistantMessage(json_encode([
            'approved' => true,
            'confidence' => 0.88,
            'reason' => 'Fake provider fallback for local dev',
        ], JSON_THROW_ON_ERROR)));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are an order risk analyst. Return JSON with approved (bool), confidence (0-1), reason (string).',
            ],
        );
    }
}

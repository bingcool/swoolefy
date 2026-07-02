<?php

declare(strict_types=1);

namespace Test\Module\Order\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Testing\FakeAIProvider;

/**
 * 订单决策 Agent —— 真实 LLM 接入（OPENAI_API_KEY）或 FakeAIProvider 回退。
 */
final class OrderDecisionAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $apiKey = (string) (getenv('OPENAI_API_KEY') ?: '');
        if ($apiKey !== '') {
            return new OpenAILike(
                baseUri: (string) (getenv('OPENAI_BASE_URI') ?: 'https://api.openai.com/v1'),
                key: $apiKey,
                model: (string) (getenv('OPENAI_MODEL') ?: 'gpt-4o-mini'),
            );
        }

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

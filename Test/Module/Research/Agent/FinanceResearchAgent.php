<?php

declare(strict_types=1);

namespace Test\Module\Research\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Testing\FakeAIProvider;

/** 财务方向研究 Agent（Fake / OpenAI 双模式）。 */
final class FinanceResearchAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return self::makeProvider('Finance research: focus on cost, ROI and budget impact.');
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(background: ['You are a finance research assistant.']);
    }

    private static function makeProvider(string $content): AIProviderInterface
    {
        $apiKey = (string) (getenv('OPENAI_API_KEY') ?: '');
        if ($apiKey !== '') {
            return new OpenAILike(
                baseUri: (string) (getenv('OPENAI_BASE_URI') ?: 'https://api.openai.com/v1'),
                key: $apiKey,
                model: (string) (getenv('OPENAI_MODEL') ?: 'gpt-4o-mini'),
            );
        }

        return FakeAIProvider::make(new AssistantMessage($content));
    }
}

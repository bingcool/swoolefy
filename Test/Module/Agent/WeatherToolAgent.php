<?php

declare(strict_types=1);

namespace Test\Module\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ToolInterface;
use Swoolefy\Support\Neuron\Memory\ChatHistoryFactory;
use Swoolefy\Support\Neuron\NeuronProviderFactory;
use Test\Module\Agent\Tool\WeatherTools;

/**
 * 带天气 Tool 的 Agent —— LLM 可调用 get_date / get_weather。
 *
 * @see https://docs.neuron-ai.dev/agent/streaming
 */
final class WeatherToolAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '（演示）深圳今天多云，气温约 28°C。请配置真实 Provider 以启用 Tool 调用。',
        ));
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
    }

    /**
     * @return list<ToolInterface>
     */
    protected function tools(): array
    {
        return WeatherTools::all();
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a weather assistant with tools.',
                'When the user asks about weather, call get_date if you need today\'s date, then call get_weather with location and date.',
                'Answer in the same language as the user, using tool results. Do not invent weather data.',
            ],
        );
    }
}

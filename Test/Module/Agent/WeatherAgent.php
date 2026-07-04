<?php

declare(strict_types=1);

namespace Test\Module\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;
use Test\Module\Agent\Dto\WeatherDto;

/**
 * 天气结构化输出 Agent —— 返回 WeatherDto。
 */
final class WeatherAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(json_encode([
            'weather' => '晴',
            'city' => '深圳',
            'date' => date('Y-m-d'),
            'temperature' => '26°C',
        ], JSON_THROW_ON_ERROR)));
    }

    protected function instructions(): string
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

        return (string) new SystemPrompt(
            background: [
                'You are a weather assistant. Return structured weather data only.',
                'Fields: weather (condition), city, date (YYYY-MM-DD), temperature (e.g. 26°C).',
                'If the user does not specify a city, use 深圳 (Shenzhen).',
                "Today is {$today} (Asia/Shanghai). The date field MUST be exactly {$today}.",
                'Reply field values in Chinese when appropriate.',
            ],
        );
    }

    protected function getOutputClass(): string
    {
        return WeatherDto::class;
    }
}

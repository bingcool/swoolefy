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

/**
 * CapabilityCenter 动态注入 Tool 的 Agent 演示。
 *
 * 与 {@see WeatherToolAgent} 的区别：
 * - WeatherToolAgent 在 tools() 中全量返回 get_date / get_weather；
 * - CapabilityToolAgent 的 tools() 返回空数组，Tool 由 NeuronFactory + CapabilityCenter
 *   在 boot 阶段按 query / topK / pinnedTools 动态解析并 addTool() 注入。
 *
 * 典型 nodeConfig：
 * ```php
 * CapabilityWeatherDemo::nodeConfig('深圳天气怎么样？', topK: 1, pinnedTools: ['native:weather:get_date'])
 * ```
 */
final class CapabilityToolAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '（演示）深圳今天多云，气温约 28°C。请配置真实 Provider 以启用 Capability + Tool 调用。',
        ));
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
    }

    /**
     * 故意返回空数组：Tool 不由 Agent 自己声明，而由 CapabilityCenter 注入。
     *
     * @return list<ToolInterface>
     */
    protected function tools(): array
    {
        return [];
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a weather assistant. Tools are provided dynamically by CapabilityCenter.',
                'Only use tools that are currently available in your tool list.',
                'When the user asks about weather, call get_date if needed, then get_weather with location and date.',
                'Answer in the same language as the user, using tool results. Do not invent weather data.',
            ],
        );
    }
}

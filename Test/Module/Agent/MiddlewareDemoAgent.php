<?php

declare(strict_types=1);

namespace Test\Module\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\Memory\ChatHistoryFactory;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

/**
 * Middleware 演示 Agent。
 *
 * Middleware 本身由 NeuronFactory agentOptions 或本类 middleware()/globalMiddleware() 挂载；
 * 本 Agent 只提供 Provider / 指令，便于控制器验证挂载链路。
 *
 * @see https://docs.neuron-ai.dev/agent/middleware
 */
final class MiddlewareDemoAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '（演示）Middleware 已挂载。请配置真实 Provider 以验证 Summarization / ToolApproval。',
        ));
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a concise assistant used to demo Neuron Agent middleware.',
                'Reply briefly in the same language as the user.',
            ],
        );
    }
}

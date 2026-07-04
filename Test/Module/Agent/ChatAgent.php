<?php

declare(strict_types=1);

namespace Test\Module\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

/**
 * 简单对话 Agent。
 *
 * Provider 优先级：
 *   1. NeuronFactory 注入（请求 provider 别名 / default_provider）
 *   2. 本类 provider()：再尝试 createDefault()
 *   3. FakeAIProvider（本地无 API Key 时的演示回退）
 */
final class ChatAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '你好！我是 Swoolefy 演示助手。当前未配置可用的 AI Provider（API Key / model），'
            . '已使用本地 Fake Provider。请在 neuron_ai.php 或环境变量中配置密钥以启用真实对话。',
        ));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a helpful assistant in a Swoolefy demo application.',
                'Reply clearly and concisely in the same language as the user.',
            ],
        );
    }
}

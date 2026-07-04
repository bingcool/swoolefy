<?php

declare(strict_types=1);

namespace Test\Module\Agent\Concerns;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

/**
 * 默认 Provider 解析：配置优先，否则 FakeAIProvider（本地演示）。
 */
trait ResolvesDefaultProvider
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
}

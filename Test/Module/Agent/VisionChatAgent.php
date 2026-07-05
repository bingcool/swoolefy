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
 * 多模态对话 Agent —— UserMessage 可携带 ImageContent（URL / base64）。
 *
 * 面向 OpenAI Vision（如 gpt-4o）等支持 image_url 的 Provider；
 * DeepSeek 等 OpenAI 兼容映射也可尝试（取决于模型是否支持 vision）。
 *
 * @see https://docs.neuron-ai.dev/providers/image
 */
final class VisionChatAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '（演示）已收到图片与文字。请配置 OpenAI 等支持 Vision 的 Provider（如 gpt-4o）以启用真实识图对话。',
        ));
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        // 识图会话默认 InMemory，避免 base64 图片写入 SQL 膨胀
        return ChatHistoryFactory::inMemory(50000);
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a multimodal vision assistant.',
                'Analyze user-provided images together with their text questions.',
                'Reply clearly in the same language as the user.',
                'Describe what you see only when relevant; answer the question directly.',
            ],
        );
    }
}

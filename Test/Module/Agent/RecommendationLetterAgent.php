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
use Test\Module\Agent\Dto\RecommendationLetterDto;

/**
 * AI 润色 Agent —— 根据 JSON 履历信息生成推荐信文章。
 */
final class RecommendationLetterAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(json_encode([
            'title' => '关于张三同志的推荐信',
            'article' => "尊敬的领导：\n\n兹推荐张三同志。张三同志从事软件开发工作十五年，先后在百度、华为、腾讯、阿里等知名企业任职，"
                . "积累了扎实的技术功底与丰富的工程实践经验。在腾讯工作期间，因其出色的技术能力与管理潜质晋升为技术总监，"
                . "负责团队技术管理与未来技术规划，展现出优秀的领导力与战略视野。\n\n特此推荐。",
        ], JSON_THROW_ON_ERROR)));
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return ChatHistoryFactory::inMemory(50000);
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(
            background: [
                'You are a professional HR and executive writing assistant.',
                'Given structured profile JSON (name, work experience, roles, promotions), write a formal recommendation letter in Chinese.',
                'Tone: professional, sincere, persuasive; highlight career progression and leadership.',
                'Output structured fields: title (letter title), article (full letter body).',
                'Do not invent facts not present in the input profile.',
            ],
        );
    }

    protected function getOutputClass(): string
    {
        return RecommendationLetterDto::class;
    }
}

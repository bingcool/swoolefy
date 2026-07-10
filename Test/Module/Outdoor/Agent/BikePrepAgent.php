<?php

declare(strict_types=1);

namespace Test\Module\Outdoor\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

/** AgentC：准备自行车（装备检查清单）。 */
final class BikePrepAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '备车：胎压正常、刹车灵敏、水壶与头盔已备齐，可以出发。',
        ));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(background: [
            'You are a bicycle preparation assistant.',
            'Reply in Chinese with a short checklist before outdoor cycling.',
        ]);
    }
}

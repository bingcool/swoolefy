<?php

declare(strict_types=1);

namespace Test\Module\Outdoor\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

/** AgentB：地图路线规划。 */
final class RouteMapAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '路线：推荐滨海绿道，约 12km，预计骑行 45 分钟，途经观景点 2 处。',
        ));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(background: [
            'You are a cycling route planner.',
            'Reply in Chinese with a short recommended bike route for the destination.',
        ]);
    }
}

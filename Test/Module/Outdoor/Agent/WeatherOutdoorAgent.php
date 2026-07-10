<?php

declare(strict_types=1);

namespace Test\Module\Outdoor\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\SystemPrompt;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use Swoolefy\Support\Neuron\NeuronProviderFactory;

/** AgentA：天气研判（是否适合户外骑行）。 */
final class WeatherOutdoorAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        $provider = (new NeuronProviderFactory())->createDefault();
        if ($provider instanceof AIProviderInterface) {
            return $provider;
        }

        return FakeAIProvider::make(new AssistantMessage(
            '天气：晴朗，气温适宜，适合户外骑行。weatherGood=true',
        ));
    }

    protected function instructions(): string
    {
        return (string) new SystemPrompt(background: [
            'You are a weather assistant for outdoor cycling.',
            'Reply in Chinese. Clearly state whether weather is good for cycling,',
            'and include the token weatherGood=true or weatherGood=false.',
        ]);
    }
}

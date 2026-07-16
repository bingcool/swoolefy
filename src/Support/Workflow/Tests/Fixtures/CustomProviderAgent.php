<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Tests\Fixtures;

use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Chat\Messages\AssistantMessage;

/**
 * 覆盖 provider() 的最小 Agent —— 供 NeuronProviderFactory::agentDeclaresCustomProvider 检测。
 */
final class CustomProviderAgent extends Agent
{
    protected function provider(): AIProviderInterface
    {
        return FakeAIProvider::make(new AssistantMessage('{"ok":true}'));
    }

    protected function instructions(): string
    {
        return 'fixture agent';
    }
}

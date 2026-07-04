<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Neuron\Memory\MemoryFactory;
use Test\Module\Workflow\WorkflowService;

/**
 * Agent 对话 HTTP API（Phase 1 Done：/agent/chat）。
 *
 * POST /api/v1/agent/chat
 * Body: { "message": "...", "sessionId": "s1", "userId": "u1" }
 */
final class AgentChatController extends BController
{
    public function chat(RequestInput $requestInput): array
    {
        $message = (string) $requestInput->input('message', '');
        if ($message === '') {
            return $this->returnJson([], 400, 'message is required');
        }

        $sessionId = (string) ($requestInput->input('sessionId') ?: $requestInput->input('threadId', 'default-session'));
        $userId = (string) ($requestInput->input('userId', 'anonymous'));
        $threadId = $userId . ':' . $sessionId;

        $memory = new MemoryFactory();
        $history = $memory->forThread($threadId);
        $history->addMessage(new UserMessage($message));

        $reply = $this->buildReply($message, $threadId);
        $history->addMessage(new AssistantMessage($reply));

        return [
            'threadId' => $threadId,
            'message' => $message,
            'reply' => $reply,
            'mcpServers' => WorkflowService::mcpFactory()->serverNames(),
        ];
    }

    private function buildReply(string $message, string $threadId): string
    {
        if (getenv('OPENAI_API_KEY')) {
            return '[Agent] Received (configure Agent class for full LLM reply): ' . mb_substr($message, 0, 200);
        }

        return '[Echo] ' . $message . ' (thread=' . $threadId . ')';
    }
}

<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Test\Module\Agent\ChatAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * Agent 对话 HTTP API。
 *
 * POST /api/v1/agent/chat
 * Body:
 *   message   string  必填，用户消息
 *   sessionId string  会话 id（默认 default-session）
 *   userId    string  用户 id（默认 anonymous）
 *   provider  string  可选，ai_model_providers 别名（如 openai、deepseek）；省略则用 default_provider
 *   model     string  可选，覆盖该 Provider 的 model
 */
final class AgentChatController extends BController
{
    public function chat(RequestInput $requestInput): array
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        $sessionId = (string) ($requestInput->input('sessionId') ?: $requestInput->input('threadId', 'default-session'));
        $userId = (string) $requestInput->input('userId', 'anonymous');
        $threadId = $userId . ':' . $sessionId;

        $providerAlias = trim((string) $requestInput->input('provider', ''));
        $model = trim((string) $requestInput->input('model', ''));

        $nodeConfig = [
            'memory' => true,
            'threadIdKey' => 'threadId',
        ];
        // 请求指定 provider 别名时覆盖 default_provider
        if ($providerAlias !== '') {
            $nodeConfig['provider'] = $providerAlias;
        }
        if ($model !== '') {
            $nodeConfig['model'] = $model;
        }

        $state = WorkflowState::fromInput([
            'threadId' => $threadId,
            'sessionId' => $sessionId,
            'userId' => $userId,
            'message' => $message,
        ]);

        try {
            $agent = WorkflowService::neuronFactory()->create(ChatAgent::class, $state, $nodeConfig);
            $reply = $agent->chat(new UserMessage($message))->getMessage()->getContent();
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent chat failed: ' . $e->getMessage(), 500, $e);
        }

        return [
            'threadId' => $threadId,
            'message' => $message,
            'reply' => $reply,
            'provider' => $providerAlias !== '' ? $providerAlias : NeuronAiConfig::load()->defaultProviderName(),
            'model' => $model !== '' ? $model : null,
        ];
    }
}

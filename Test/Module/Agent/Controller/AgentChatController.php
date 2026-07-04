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
 *   使用 neuron_ai.php 的 default_provider；可选传 provider / model 覆盖。
 *
 * POST /api/v1/agent/chat1
 *   必须指定 provider（ai_model_providers 别名）；可选 model。
 *
 * Body 公共字段：
 *   message   string  必填
 *   sessionId string  默认 default-session
 *   userId    string  默认 anonymous
 *   provider  string  chat 可选；chat1 必填
 *   model     string  可选，覆盖该 Provider 的 model
 */
final class AgentChatController extends BController
{
    /** 默认 Provider 对话。 */
    public function chat(RequestInput $requestInput): array
    {
        $providerAlias = trim((string) $requestInput->input('provider', ''));
        $model = trim((string) $requestInput->input('model', ''));

        return $this->runChat($requestInput, $providerAlias, $model);
    }

    /**
     * 指定模型提供者对话（provider 必填）。
     *
     * POST /api/v1/agent/chat1
     * Body: { "message": "...", "provider": "deepseek", "model": "deepseek-chat" }
     */
    public function chat1(RequestInput $requestInput): array
    {
        $providerAlias = trim((string) $requestInput->input('provider', ''));
        if ($providerAlias === '') {
            throw new SystemException('provider is required (ai_model_providers alias, e.g. openai, deepseek)', 400);
        }

        $model = trim((string) $requestInput->input('model', ''));

        return $this->runChat($requestInput, $providerAlias, $model);
    }

    /**
     * @return array{threadId: string, message: string, reply: string, provider: string, model: string|null}
     */
    private function runChat(RequestInput $requestInput, string $providerAlias, string $model): array
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        $sessionId = (string) ($requestInput->input('sessionId') ?: $requestInput->input('threadId', 'default-session'));
        $userId = (string) $requestInput->input('userId', 'anonymous');
        $threadId = $userId . ':' . $sessionId;

        $nodeConfig = [
            'memory' => true,
            'threadIdKey' => 'threadId',
        ];
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

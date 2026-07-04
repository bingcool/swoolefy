<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
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
 * POST /api/v1/agent/chat-thinking
 *   DeepSeek 思考模式（thinking + reasoning_effort），固定 provider=deepseek。
 *
 * @see https://api-docs.deepseek.com/zh-cn/guides/thinking_mode
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
     * DeepSeek 思考模式对话。
     *
     * POST /api/v1/agent/chat-thinking
     * Body:
     *   message           string  必填
     *   thinking          string  enabled|disabled，默认 enabled
     *   reasoning_effort  string  high|max，默认 high（仅 thinking=enabled 时生效）
     *   model             string  默认 deepseek-chat
     *   sessionId / userId 同 chat
     *
     * @see https://api-docs.deepseek.com/zh-cn/guides/thinking_mode
     */
    public function chatThinking(RequestInput $requestInput): array
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        $thinkingType = strtolower(trim((string) $requestInput->input('thinking', 'enabled')));
        if (!in_array($thinkingType, ['enabled', 'disabled'], true)) {
            throw new SystemException('thinking must be enabled or disabled', 400);
        }

        $effort = strtolower(trim((string) $requestInput->input('reasoning_effort', 'high')));
        if (!in_array($effort, ['high', 'max'], true)) {
            throw new SystemException('reasoning_effort must be high or max', 400);
        }

        $model = trim((string) $requestInput->input('model', 'deepseek-chat'));
        if ($model === '') {
            $model = 'deepseek-chat';
        }

        $sessionId = (string) ($requestInput->input('sessionId') ?: $requestInput->input('threadId', 'default-session'));
        $userId = (string) $requestInput->input('userId', 'anonymous');
        $threadId = $userId . ':' . $sessionId;

        // DeepSeek OpenAI 兼容：thinking + reasoning_effort 写入请求体 parameters
        $parameters = [
            'thinking' => ['type' => $thinkingType],
        ];
        if ($thinkingType === 'enabled') {
            $parameters['reasoning_effort'] = $effort;
        }

        $nodeConfig = [
            'memory' => true,
            'threadIdKey' => 'threadId',
            'provider' => NeuronAiProviderName::DEEPSEEK,
            'model' => $model,
            'parameters' => $parameters,
        ];

        $state = WorkflowState::fromInput([
            'threadId' => $threadId,
            'sessionId' => $sessionId,
            'userId' => $userId,
            'message' => $message,
        ]);

        try {
            $agent = WorkflowService::neuronFactory()->create(ChatAgent::class, $state, $nodeConfig);
            $assistant = $agent->chat(new UserMessage($message))->getMessage();
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent chat thinking failed: ' . $e->getMessage(), 500, $e);
        }

        return [
            'threadId' => $threadId,
            'message' => $message,
            'reply' => $assistant->getContent(),
            'reasoning_content' => $this->extractReasoningContent($assistant),
            'provider' => NeuronAiProviderName::DEEPSEEK,
            'model' => $model,
            'thinking' => $thinkingType,
            'reasoning_effort' => $thinkingType === 'enabled' ? $effort : null,
        ];
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

    /** 从 AssistantMessage 提取 DeepSeek reasoning_content。 */
    private function extractReasoningContent(Message $message): ?string
    {
        $fromMeta = $message->getMetadata('reasoning_content');
        if (is_string($fromMeta) && $fromMeta !== '') {
            return $fromMeta;
        }

        $block = $message->getReasoning();
        if ($block !== null) {
            $content = $block->getContent();

            return $content !== '' ? $content : null;
        }

        return null;
    }
}

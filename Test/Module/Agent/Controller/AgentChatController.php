<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Neuron\Memory\ChatHistoryPdoResolver;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Test\Module\Agent\ChatAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * Agent 对话 HTTP API。
 *
 * ChatAgent::chatHistory() 使用 Neuron SQLChatHistory（需表 chat_history）。
 *
 * POST /api/v1/agent/chat
 * POST /api/v1/agent/chat1
 * POST /api/v1/agent/chat-thinking
 * POST /api/v1/agent/chat-persist
 *
 * @see https://docs.neuron-ai.dev/agent/chat-history-and-memory
 * @see https://api-docs.deepseek.com/zh-cn/guides/thinking_mode
 */
final class AgentChatController extends BController
{
    /** 默认 Provider 对话（ChatAgent → SQL）。 */
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

        [$threadId, $sessionId, $userId] = $this->resolveThread($requestInput);

        $parameters = [
            'thinking' => ['type' => $thinkingType],
        ];
        if ($thinkingType === 'enabled') {
            $parameters['reasoning_effort'] = $effort;
        }

        $nodeConfig = [
            'provider' => NeuronAiProviderName::DEEPSEEK,
            'model' => $model,
            'parameters' => $parameters,
        ];

        try {
            $agent = $this->makeChatAgent($threadId, $nodeConfig);
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
     * SQL 持久化多轮会话（与 chat 相同记忆后端，显式返回 historyCount）。
     *
     * POST /api/v1/agent/chat-persist
     */
    public function chatPersist(RequestInput $requestInput): array
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        [$threadId] = $this->resolveThread($requestInput);

        $providerAlias = trim((string) $requestInput->input('provider', ''));
        $model = trim((string) $requestInput->input('model', ''));

        $nodeConfig = [];
        if ($providerAlias !== '') {
            $nodeConfig['provider'] = $providerAlias;
        }
        if ($model !== '') {
            $nodeConfig['model'] = $model;
        }

        try {
            $agent = $this->makeChatAgent($threadId, $nodeConfig);
            $historyCount = count($agent->getChatHistory()->getMessages());
            $reply = (string) $agent->chat(new UserMessage($message))->getMessage()->getContent();
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent chat persist failed: ' . $e->getMessage(), 500, $e);
        }

        return [
            'threadId' => $threadId,
            'message' => $message,
            'reply' => $reply,
            'provider' => $providerAlias !== '' ? $providerAlias : NeuronAiConfig::load()->defaultProviderName(),
            'model' => $model !== '' ? $model : null,
            'persisted' => true,
            'historyCount' => $historyCount,
            'memory' => 'sql',
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

        [$threadId] = $this->resolveThread($requestInput);

        $nodeConfig = [];
        if ($providerAlias !== '') {
            $nodeConfig['provider'] = $providerAlias;
        }
        if ($model !== '') {
            $nodeConfig['model'] = $model;
        }

        try {
            $agent = $this->makeChatAgent($threadId, $nodeConfig);
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

    /**
     * @param array<string, mixed> $nodeConfig
     */
    private function makeChatAgent(string $threadId, array $nodeConfig = []): ChatAgent
    {
        $pdo = ChatHistoryPdoResolver::resolve('db');

        /** @var ChatAgent $agent */
        $agent = WorkflowService::neuronFactory()->boot(
            new ChatAgent($threadId, $pdo),
            $nodeConfig,
        );

        return $agent;
    }

    /** @return array{0: string, 1: string, 2: string} threadId, sessionId, userId */
    private function resolveThread(RequestInput $requestInput): array
    {
        $sessionId = (string) ($requestInput->input('sessionId') ?: $requestInput->input('threadId', 'default-session'));
        $userId = (string) $requestInput->input('userId', 'anonymous');

        return [$userId . ':' . $sessionId, $sessionId, $userId];
    }

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

<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Annotation\StreamResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\AI\Stream\SseResponse;
use Swoolefy\Support\AI\Stream\SseStreamSink;
use Swoolefy\Support\Neuron\Memory\ChatHistoryPdoResolver;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Test\Module\Agent\ChatAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * Agent SSE 流式会话 HTTP API（{@see SseStreamSink}）。
 *
 * POST /api/v1/agent/stream/chat
 * Body（JSON）或 form：
 *   message   string  必填
 *   sessionId string  默认 default-session
 *   userId    string  默认 anonymous
 *   provider  string  可选（默认 default_provider）
 *   model     string  可选
 *
 * SSE 事件：
 *   start      — threadId / provider / model
 *   token      — 正文增量 content
 *   reasoning  — 思维链增量（如 DeepSeek 思考模式）
 *   complete   — reply / threadId / provider / model
 *   error      — message
 *
 * curl 示例：
 curl -N -X POST http://localhost:9501/api/v1/agent/stream/chat -H "Content-Type: application/json" -H "Accept: text/event-stream" -d '{"message":"你好","sessionId":"s1","userId":"u1","provider":"deepseek"}'
 */
final class AgentStreamController extends BController
{
    /**
     * SSE 流式对话（ChatAgent + SQL 记忆）。
     *
     * POST /api/v1/agent/stream/chat
     */
    #[StreamResponse]
    public function chat(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $sink = SseResponse::open($responseOutput);

        try {
            $this->streamChat($requestInput, $sink);
        } catch (SystemException $e) {
            $sink->publish('error', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
        } catch (Throwable $e) {
            $sink->publish('error', ['message' => 'Agent stream chat failed: ' . $e->getMessage()]);
        } finally {
            SseResponse::close($sink);
        }
    }

    private function streamChat(RequestInput $requestInput, SseStreamSink $sink): void
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

        $nodeConfig = [];
        if ($providerAlias !== '') {
            $nodeConfig['provider'] = $providerAlias;
        }
        if ($model !== '') {
            $nodeConfig['model'] = $model;
        }

        $resolvedProvider = $providerAlias !== ''
            ? $providerAlias
            : NeuronAiConfig::load()->defaultProviderName();

        $sink->publish('start', [
            'threadId' => $threadId,
            'provider' => $resolvedProvider,
            'model' => $model !== '' ? $model : null,
        ]);

        try {
            $pdo = ChatHistoryPdoResolver::resolve('db');
            $agent = WorkflowService::neuronFactory()->boot(
                new ChatAgent($threadId, $pdo),
                $nodeConfig,
            );

            $handler = $agent->stream(new UserMessage($message));
            $fullText = '';
            $fullReasoning = '';

            foreach ($handler->events() as $event) {
                if ($event instanceof TextChunk) {
                    $fullText .= $event->content;
                    $sink->publish('token', [
                        'content' => $event->content,
                        'threadId' => $threadId,
                    ]);
                    continue;
                }

                if ($event instanceof ReasoningChunk) {
                    $fullReasoning .= $event->content;
                    $sink->publish('reasoning', [
                        'content' => $event->content,
                        'threadId' => $threadId,
                    ]);
                }
            }

            $sink->publish('complete', [
                'threadId' => $threadId,
                'message' => $message,
                'reply' => $fullText,
                'reasoning_content' => $fullReasoning !== '' ? $fullReasoning : null,
                'provider' => $resolvedProvider,
                'model' => $model !== '' ? $model : null,
            ]);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException($e->getMessage(), 500, $e);
        }
    }
}

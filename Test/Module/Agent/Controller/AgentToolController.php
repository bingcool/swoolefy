<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Annotation\StreamResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\AI\Stream\SseResponse;
use Swoolefy\Support\AI\Stream\SseStreamSink;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Test\Module\Agent\WeatherToolAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * Agent Tool 调用示例（天气 get_date / get_weather）。
 *
 * POST /api/v1/agent/tool/weather
 *   同步：返回最终回答。
 *
 * POST /api/v1/agent/tool/weather/stream
 *   SSE：推送 tool_call / tool_result / token / reasoning / complete。
 *
 * Body:
 *   message   string  必填，如「深圳今天天气怎么样？」
 *   provider  string  可选
 *   model     string  可选
 *
 * @see https://docs.neuron-ai.dev/agent/streaming
 */
final class AgentToolController extends BController
{
    /**
     * 同步 Tool 对话（天气）。
     *
     * POST /api/v1/agent/tool/weather
     *
     curl -X POST http://localhost:9501/api/v1/agent/tool/weather -H "Content-Type: application/json" -d '{"message":"深圳今天天气怎么样？","provider":"deepseek"}'
     */
    public function weather(RequestInput $requestInput): array
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        $agentOptions = $this->resolveAgentOptions($requestInput);
        $provider = $this->resolveProviderLabel($agentOptions);

        $state = WorkflowState::fromInput(['message' => $message]);

        try {
            $agent = WorkflowService::neuronFactory()->create(
                WeatherToolAgent::class,
                $state,
                $agentOptions,
            );
            $reply = (string) $agent->chat(new UserMessage($message))->getMessage()->getContent();
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent tool weather failed: ' . $e->getMessage(), 500, $e);
        }

        return [
            'message' => $message,
            'reply' => $reply,
            'provider' => $provider,
            'model' => $agentOptions['model'] ?? null,
            'tools' => ['get_date', 'get_weather'],
        ];
    }

    /**
     * SSE 流式 Tool 对话（天气）。
     *
     * POST /api/v1/agent/tool/weather/stream
     *
     * SSE 事件：start / tool_call / tool_result / token / reasoning / complete / error
     *
     * @see https://docs.neuron-ai.dev/agent/streaming
     */
    #[StreamResponse]
    public function weatherStream(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $sink = SseResponse::open($responseOutput);

        try {
            $this->streamWeather($requestInput, $sink);
        } catch (SystemException $e) {
            $sink->publish('error', ['message' => $e->getMessage(), 'code' => $e->getCode()]);
        } catch (Throwable $e) {
            $sink->publish('error', ['message' => 'Agent tool weather stream failed: ' . $e->getMessage()]);
        } finally {
            SseResponse::close($sink);
        }
    }

    private function streamWeather(RequestInput $requestInput, SseStreamSink $sink): void
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        $agentOptions = $this->resolveAgentOptions($requestInput);
        $provider = $this->resolveProviderLabel($agentOptions);

        $sink->publish('start', [
            'provider' => $provider,
            'model' => $agentOptions['model'] ?? null,
            'tools' => ['get_date', 'get_weather'],
        ]);

        $state = WorkflowState::fromInput(['message' => $message]);

        try {
            $agent = WorkflowService::neuronFactory()->create(
                WeatherToolAgent::class,
                $state,
                $agentOptions,
            );

            $handler = $agent->stream(new UserMessage($message));
            $fullText = '';
            $toolCalls = [];

            foreach ($handler->events() as $chunk) {
                if ($chunk instanceof ToolCallChunk) {
                    $tool = $chunk->tool;
                    $toolCalls[] = [
                        'name' => $tool->getName(),
                        'inputs' => $tool->getInputs(),
                    ];
                    $sink->publish('tool_call', [
                        'name' => $tool->getName(),
                        'inputs' => $tool->getInputs(),
                    ]);
                    continue;
                }

                if ($chunk instanceof ToolResultChunk) {
                    $tool = $chunk->tool;
                    $sink->publish('tool_result', [
                        'name' => $tool->getName(),
                        'result' => $tool->getResult(),
                    ]);
                    continue;
                }

                if ($chunk instanceof TextChunk) {
                    $fullText .= $chunk->content;
                    $sink->publish('token', ['content' => $chunk->content]);
                    continue;
                }

                if ($chunk instanceof ReasoningChunk) {
                    $sink->publish('reasoning', ['content' => $chunk->content]);
                }
            }

            $sink->publish('complete', [
                'message' => $message,
                'reply' => $fullText,
                'provider' => $provider,
                'model' => $agentOptions['model'] ?? null,
                'tool_calls' => $toolCalls,
            ]);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException($e->getMessage(), 500, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAgentOptions(RequestInput $requestInput): array
    {
        $providerAlias = trim((string) $requestInput->input('provider', ''));
        $model = trim((string) $requestInput->input('model', ''));

        $agentOptions = [];
        if ($providerAlias !== '') {
            $agentOptions['provider'] = $providerAlias;
        }
        if ($model !== '') {
            $agentOptions['model'] = $model;
        }

        return $agentOptions;
    }

    /** @param array<string, mixed> $agentOptions */
    private function resolveProviderLabel(array $agentOptions): string
    {
        $alias = $agentOptions['provider'] ?? '';

        return is_string($alias) && $alias !== ''
            ? $alias
            : NeuronAiConfig::load()->defaultProviderName();
    }
}

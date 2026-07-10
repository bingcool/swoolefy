<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Middleware\Summarization;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\StreamingNode;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Test\Module\Agent\Middleware\RecordingMiddleware;
use Test\Module\Agent\MiddlewareDemoAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * Agent Middleware 演示（Neuron 原生 Workflow Middleware）。
 *
 * POST /api/v1/agent/middleware/chat
 *   通过 NeuronFactory agentOptions 挂载 RecordingMiddleware + Summarization，执行一轮对话。
 *
 * Body:
 *   message            string  必填
 *   provider           string  可选
 *   model              string  可选
 *   summarization      bool    可选，默认 true；是否挂载 Summarization
 *   maxTokens          int     可选，Summarization 阈值阈值，默认 10000
 *   messagesToKeep     int     可选，Summarization 保留最近消息数，默认 5
 *
 * @see https://docs.neuron-ai.dev/agent/middleware
 */
final class AgentMiddlewareController extends BController
{
    /**
     * 挂载 Middleware 后执行对话，返回 recorder 调用统计。
     *
     * POST /api/v1/agent/middleware/chat
     *
     * curl -X POST "http://localhost:9501/api/v1/agent/middleware/chat" \
     *   -H "Content-Type: application/json" \
     *   -d '{"message":"用一句话介绍你自己","provider":"deepseek","summarization":true}'
     */
    public function chat(RequestInput $requestInput): array
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        $agentOptions = $this->resolveAgentOptions($requestInput);
        $enableSummarization = filter_var(
            $requestInput->input('summarization', true),
            FILTER_VALIDATE_BOOLEAN,
        );
        $maxTokens = max(0, (int) $requestInput->input('maxTokens', 10000));
        $messagesToKeep = max(1, (int) $requestInput->input('messagesToKeep', 5));

        $recorder = new RecordingMiddleware();
        $agentOptions['globalMiddleware'] = [$recorder];

        if ($enableSummarization) {
            // 同一 Summarization 实例挂到 Chat / Stream / Structured（与官方文档一致）
            /** @var Summarization|null $summarization */
            $summarization = null;
            $makeSummarization = static function (Agent $agent) use (&$summarization, $maxTokens, $messagesToKeep): Summarization {
                return $summarization ??= new Summarization(
                    provider: $agent->resolveProvider(),
                    maxTokens: $maxTokens,
                    messagesToKeep: $messagesToKeep,
                );
            };

            $agentOptions['middleware'] = [
                ChatNode::class => [$makeSummarization],
                StreamingNode::class => [$makeSummarization],
                StructuredOutputNode::class => [$makeSummarization],
            ];
        }

        $provider = $this->resolveProviderLabel($agentOptions);
        $state = WorkflowState::fromInput(['message' => $message]);

        try {
            $agent = WorkflowService::neuronFactory()->create(
                MiddlewareDemoAgent::class,
                $state,
                $agentOptions,
            );
            $reply = (string) $agent->chat(new UserMessage($message))->getMessage()->getContent();
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent middleware chat failed: ' . $e->getMessage(), 500, $e);
        }

        return [
            'message' => $message,
            'reply' => $reply,
            'provider' => $provider,
            'model' => $agentOptions['model'] ?? null,
            'agent' => MiddlewareDemoAgent::class,
            'middleware' => [
                'summarization' => $enableSummarization,
                'maxTokens' => $maxTokens,
                'messagesToKeep' => $messagesToKeep,
                'recorder' => $recorder->snapshot(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function resolveAgentOptions(RequestInput $requestInput): array
    {
        $agentOptions = [
            // 本演示不依赖 MCP / Capability
            'capabilityEnabled' => false,
        ];

        $providerAlias = trim((string) $requestInput->input('provider', ''));
        $model = trim((string) $requestInput->input('model', ''));
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

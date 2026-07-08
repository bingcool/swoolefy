<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\CapabilityCenter\Resolver\ToolResolveContext;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Test\Module\Agent\CapabilityToolAgent;
use Test\Module\Agent\CapabilityWeatherDemo;
use Throwable;

/**
 * CapabilityCenter Agent 模块演示。
 *
 * 1. resolve — 只解析 Capability，不请求 LLM，返回 selectedTools。
 * 2. chat    — 完整链路：Capability 解析 → NeuronFactory 注入 Agent → LLM 对话。
 *
 * POST /api/v1/agent/capability/resolve
 * POST /api/v1/agent/capability/chat
 *
 * 请求体：
 *   message       string            查询文本
 *   topK          int               普通候选数量上限
 *   pinnedTools   list<string>      必须注入的 descriptor ID 列表
 *   provider      string            chat 可选，Provider 别名
 *   model         string            chat 可选
 */
final class AgentCapabilityController extends BController
{
    /**
     * 仅解析 Capability，返回 selectedTools（不请求 LLM）。
     *
     * POST /api/v1/agent/capability/resolve
     *
     * curl -X POST "http://localhost:9501/api/v1/agent/capability/resolve" \
     *   -H "Content-Type: application/json" \
     *   -d '{"message":"深圳天气怎么样？","topK":1,"pinnedTools":["native:weather:get_date"]}'
     */
    public function resolve(RequestInput $requestInput): array
    {
        [$message, $topK, $pinned] = $this->parseRequest($requestInput);
        $tools = $this->resolveTools($message, $topK, $pinned);

        return [
            'message' => $message,
            'topK' => $topK,
            'pinnedTools' => $pinned,
            'selectedTools' => $this->toolNames($tools),
        ];
    }

    /**
     * 完整测试：Capability 解析后注入 CapabilityToolAgent，并执行对话。
     *
     * NeuronFactory::create() 会在 boot 阶段调用 CapabilityCenter::resolveTools()，
     * 将命中的 Tool 通过 Agent::addTool() 注入；注入结果可通过 Agent::getTools() 读取。
     *
     * POST /api/v1/agent/capability/chat
     *
     * curl -X POST "http://localhost:9501/api/v1/agent/capability/chat" \
     *   -H "Content-Type: application/json" \
     *   -d '{"message":"深圳今天天气怎么样？","topK":1,"pinnedTools":["native:weather:get_date"],"provider":"deepseek"}'
     */
    public function chat(RequestInput $requestInput): array
    {
        [$message, $topK, $pinned] = $this->parseRequest($requestInput);
        $nodeConfig = CapabilityWeatherDemo::nodeConfig(
            $message,
            $topK,
            $pinned,
            $this->providerNodeConfig($requestInput),
        );
        $provider = $this->resolveProviderLabel($nodeConfig);

        $state = WorkflowState::fromInput(['message' => $message]);

        try {
            $agent = CapabilityWeatherDemo::neuronFactory($topK)->create(
                CapabilityToolAgent::class,
                $state,
                $nodeConfig,
            );
            $selectedTools = $this->toolNames($agent->getTools());
            $reply = (string) $agent->chat(new UserMessage($message))->getMessage()->getContent();
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent capability chat failed: ' . $e->getMessage(), 500, $e);
        }

        return [
            'message' => $message,
            'topK' => $topK,
            'pinnedTools' => $pinned,
            'selectedTools' => $selectedTools,
            'reply' => $reply,
            'provider' => $provider,
            'model' => $nodeConfig['model'] ?? null,
            'agent' => CapabilityToolAgent::class,
        ];
    }

    /**
     * @param list<string> $pinned
     *
     * @return list<ToolInterface>
     */
    private function resolveTools(string $message, int $topK, array $pinned): array
    {
        return CapabilityWeatherDemo::componentFactory(self::resolveConfig($topK))
            ->capabilityCenter()
            ->resolveTools(new ToolResolveContext(
                query: $message,
                agentId: self::class,
                tenantId: null,
                userId: null,
                pinnedToolIds: $pinned,
                capabilityProfile: 'weather',
                profileTags: ['weather'],
                topK: $topK,
            ));
    }

    private static function resolveConfig(int $topK): \Swoolefy\Support\Neuron\NeuronAiConfig
    {
        return CapabilityWeatherDemo::capabilityConfig($topK);
    }

    /**
     * @return array{0: string, 1: int, 2: list<string>}
     */
    private function parseRequest(RequestInput $requestInput): array
    {
        $message = trim((string) $requestInput->input('message', '深圳天气怎么样？'));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        return [
            $message,
            max(0, (int) $requestInput->input('topK', 1)),
            $this->stringList($requestInput->input('pinnedTools', [])),
        ];
    }

    /**
     * @param list<ToolInterface> $tools
     *
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }

    /** @return array<string, mixed> */
    private function providerNodeConfig(RequestInput $requestInput): array
    {
        $providerAlias = trim((string) $requestInput->input('provider', ''));
        $model = trim((string) $requestInput->input('model', ''));

        $nodeConfig = [];
        if ($providerAlias !== '') {
            $nodeConfig['provider'] = $providerAlias;
        }
        if ($model !== '') {
            $nodeConfig['model'] = $model;
        }

        return $nodeConfig;
    }

    /** @param array<string, mixed> $nodeConfig */
    private function resolveProviderLabel(array $nodeConfig): string
    {
        $alias = $nodeConfig['provider'] ?? '';

        return is_string($alias) && $alias !== ''
            ? $alias
            : NeuronAiConfig::load()->defaultProviderName();
    }

    /** @return list<string> */
    private function stringList(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            return [];
        }

        $list = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '' && !in_array($value, $list, true)) {
                $list[] = $value;
            }
        }

        return $list;
    }
}

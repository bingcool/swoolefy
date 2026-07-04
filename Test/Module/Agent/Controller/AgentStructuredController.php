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
use Test\Module\Agent\Dto\WeatherDto;
use Test\Module\Agent\WeatherAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * Agent 结构化输出 HTTP API。
 *
 * POST /api/v1/agent/weather
 * Body:
 *   city     string  可选，默认深圳
 *   provider string  可选，ai_model_providers 别名
 *   model    string  可选，覆盖 model
 *
 * 返回 WeatherDto 四个字段：weather、city、date、temperature。
 */
final class AgentStructuredController extends BController
{
    /**
     * 查询城市天气（结构化输出）。
     *
     * POST /api/v1/agent/weather
     * Body: { "city": "深圳", "provider": "deepseek" }
     */
    public function weather(RequestInput $requestInput): array
    {
        $city = trim((string) $requestInput->input('city', '深圳'));
        if ($city === '') {
            $city = '深圳';
        }

        $providerAlias = trim((string) $requestInput->input('provider', ''));
        $model = trim((string) $requestInput->input('model', ''));

        $nodeConfig = [];
        if ($providerAlias !== '') {
            $nodeConfig['provider'] = $providerAlias;
        }
        if ($model !== '') {
            $nodeConfig['model'] = $model;
        }

        // 以 Asia/Shanghai 为准的「今天」，避免模型臆造日期
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');

        $prompt = sprintf(
            '请给出「%s」今天（%s）的天气信息。date 字段必须是 %s，并包含 weather、city、date、temperature 四个字段。',
            $city,
            $today,
            $today,
        );

        $state = WorkflowState::fromInput([
            'city' => $city,
            'date' => $today,
            'message' => $prompt,
        ]);

        try {
            $agent = WorkflowService::neuronFactory()->create(WeatherAgent::class, $state, $nodeConfig);
            /** @var WeatherDto $dto */
            $dto = $agent->structured(new UserMessage($prompt), WeatherDto::class);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent structured weather failed: ' . $e->getMessage(), 500, $e);
        }

        if (!$dto instanceof WeatherDto) {
            throw new SystemException('Invalid structured weather output', 500);
        }

        // 强制使用今天的日期，防止模型返回错误日期
        $dto->date = $today;

        return [
            'weather' => $dto->weather,
            'city' => $dto->city,
            'date' => $dto->date,
            'temperature' => $dto->temperature,
            'provider' => $providerAlias !== '' ? $providerAlias : NeuronAiConfig::load()->defaultProviderName(),
            'model' => $model !== '' ? $model : null,
        ];
    }
}

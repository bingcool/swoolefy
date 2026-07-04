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
use Test\Module\Agent\Dto\RecommendationLetterDto;
use Test\Module\Agent\RecommendationLetterAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * AI 润色 HTTP API —— 输入履历 JSON，输出推荐信文章。
 *
 * POST /api/v1/agent/polish/recommendation
 * Body 示例：
 * {
 *   "name": "张三",
 *   "years_of_experience": 15,
 *   "experiences": [
 *     {"company": "百度", "years": 3, "role": "软件开发"},
 *     {"company": "华为", "years": 3, "role": "软件开发"},
 *     {"company": "腾讯", "years": 6, "role": "软件开发，后晋升总监，负责技术管理与未来技术规划"},
 *     {"company": "阿里", "years": 3, "role": "软件开发"}
 *   ],
 *   "highlights": ["在腾讯晋升为总监"],
 *   "purpose": "推荐信",
 *   "provider": "deepseek"
 * }
 *
 * 也支持 profile 对象包裹上述字段，或 content 纯文本履历描述。
 */
final class AgentPolishController extends BController
{
    /**
     * 根据 JSON 履历生成推荐信。
     *
     * POST /api/v1/agent/polish/recommendation
     *
     * curl -X POST http://localhost:9501/api/v1/agent/polish/recommendation -H "Content-Type: application/json" -d '{"name":"张三","years_of_experience":15,"experiences":[{"company":"百度","years":3,"role":"软件开发"},{"company":"华为","years":3,"role":"软件开发"},{"company":"腾讯","years":6,"role":"软件开发，后晋升总监，负责技术管理与未来技术规划"},{"company":"阿里","years":3,"role":"软件开发"}],"highlights":["在腾讯晋升为总监"],"purpose":"推荐信","provider":"deepseek"}'
     */
    public function recommendation(RequestInput $requestInput): array
    {
        $profile = $this->resolveProfile($requestInput);
        if ($profile === []) {
            throw new SystemException(
                'profile is required: pass name/experiences JSON fields, or profile object, or content text',
                400,
            );
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

        $profileJson = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $prompt = <<<PROMPT
请根据以下候选人履历 JSON，撰写一份正式、专业的中文推荐信。

要求：
1. 忠实于 JSON 中的事实，不要编造经历；
2. 突出职业成长路径与管理能力（如有晋升、技术规划等）；
3. 语言流畅、有说服力，适合作为正式推荐材料；
4. 输出 title（标题）与 article（完整正文）。

履历 JSON：
{$profileJson}
PROMPT;

        $state = WorkflowState::fromInput([
            'profile' => $profile,
            'message' => $prompt,
        ]);

        try {
            $agent = WorkflowService::neuronFactory()->create(
                RecommendationLetterAgent::class,
                $state,
                $nodeConfig,
            );
            /** @var RecommendationLetterDto $dto */
            $dto = $agent->structured(new UserMessage($prompt), RecommendationLetterDto::class);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent polish recommendation failed: ' . $e->getMessage(), 500, $e);
        }

        if (!$dto instanceof RecommendationLetterDto) {
            throw new SystemException('Invalid recommendation letter output', 500);
        }

        return [
            'title' => $dto->title,
            'article' => $dto->article,
            'profile' => $profile,
            'provider' => $providerAlias !== '' ? $providerAlias : NeuronAiConfig::load()->defaultProviderName(),
            'model' => $model !== '' ? $model : null,
        ];
    }

    /**
     * 从请求体解析履历：profile 对象 / 顶层字段 / content 文本。
     *
     * @return array<string, mixed>
     */
    private function resolveProfile(RequestInput $requestInput): array
    {
        $profile = $requestInput->input('profile');
        if (is_array($profile) && $profile !== []) {
            return $profile;
        }

        if (is_string($profile) && trim($profile) !== '') {
            $decoded = json_decode($profile, true);
            if (is_array($decoded) && $decoded !== []) {
                return $decoded;
            }

            return ['content' => trim($profile)];
        }

        $name = trim((string) $requestInput->input('name', ''));
        $experiences = $requestInput->input('experiences');
        $years = $requestInput->input('years_of_experience');
        $highlights = $requestInput->input('highlights');
        $purpose = trim((string) $requestInput->input('purpose', '推荐信'));
        $content = trim((string) $requestInput->input('content', ''));

        $built = [];
        if ($name !== '') {
            $built['name'] = $name;
        }
        if (is_numeric($years)) {
            $built['years_of_experience'] = (int) $years;
        }
        if (is_array($experiences) && $experiences !== []) {
            $built['experiences'] = $experiences;
        }
        if (is_array($highlights) && $highlights !== []) {
            $built['highlights'] = $highlights;
        }
        if ($purpose !== '') {
            $built['purpose'] = $purpose;
        }
        if ($content !== '') {
            $built['content'] = $content;
        }

        return $built;
    }
}

<?php

declare(strict_types=1);

namespace Test\Module\Agent\Controller;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\UploadedFile;
use Swoolefy\Http\UploadException;
use Swoolefy\Support\Neuron\NeuronAiConfig;
use Swoolefy\Support\Neuron\NeuronAiProviderName;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\State\WorkflowState;
use Test\Module\Agent\VisionChatAgent;
use Test\Module\Workflow\WorkflowService;
use Throwable;

/**
 * 多模态识图对话 HTTP API（ImageContent + 文本）。
 *
 * 面向未来对接 OpenAI Vision（gpt-4o 等）；消息结构与 OpenAI image_url 兼容。
 *
 * POST /api/v1/agent/vision/chat
 *
 * 方式一：multipart 上传
 *   curl -F "message=这张图里有什么？" -F "image=@/path/to/photo.jpg" \
 *        -F "provider=openai" -F "model=gpt-4o" \
 *        http://localhost:9501/api/v1/agent/vision/chat
 *
 * 方式二：JSON（图片 URL）
 *   {"message":"描述这张图","image_url":"https://example.com/a.jpg","provider":"openai","model":"gpt-4o"}
 *
 * 方式三：JSON（base64）
 *   {"message":"描述这张图","image_base64":"...","media_type":"image/jpeg","provider":"openai"}
 */
final class AgentVisionController extends BController
{
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * 上传图片 + 文本对话。
     *
     * POST /api/v1/agent/vision/chat
     */
    public function chat(RequestInput $requestInput): array
    {
        $message = trim((string) $requestInput->input('message', ''));
        if ($message === '') {
            throw new SystemException('message is required', 400);
        }

        $imageBlock = $this->resolveImageContent($requestInput);

        // 默认 openai，便于对接 Vision 模型；可覆盖为其他支持 image_url 的 Provider
        $providerAlias = trim((string) $requestInput->input('provider', NeuronAiProviderName::OPENAI));
        $model = trim((string) $requestInput->input('model', 'gpt-4o'));
        if ($model === '') {
            $model = 'gpt-4o';
        }

        $agentOptions = [
            'provider' => $providerAlias,
            'model' => $model,
        ];

        $userMessage = new UserMessage([
            $imageBlock,
            new TextContent($message),
        ]);

        $state = WorkflowState::fromInput([
            'message' => $message,
            'has_image' => true,
        ]);

        try {
            $agent = WorkflowService::neuronFactory()->create(
                VisionChatAgent::class,
                $state,
                $agentOptions,
            );
            $reply = $agent->chat($userMessage)->getMessage()->getContent();
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } catch (SystemException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SystemException('Agent vision chat failed: ' . $e->getMessage(), 500, $e);
        }

        return [
            'message' => $message,
            'reply' => $reply,
            'provider' => $providerAlias !== '' ? $providerAlias : NeuronAiConfig::load()->defaultProviderName(),
            'model' => $model,
            'image' => [
                'source_type' => $imageBlock->sourceType->value,
                'media_type' => $imageBlock->mediaType,
            ],
        ];
    }

    /**
     * 从 multipart 文件 / image_url / image_base64 构建 ImageContent。
     */
    private function resolveImageContent(RequestInput $requestInput): ImageContent
    {
        $uploaded = $requestInput->file('image') ?? $requestInput->file('file');
        if ($uploaded instanceof UploadedFile) {
            return $this->imageContentFromUpload($uploaded);
        }

        $imageUrl = trim((string) $requestInput->input('image_url', ''));
        if ($imageUrl !== '') {
            if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                throw new SystemException('image_url is invalid', 400);
            }

            return new ImageContent(
                content: $imageUrl,
                sourceType: SourceType::URL,
                mediaType: $this->guessMediaTypeFromUrl($imageUrl),
            );
        }

        $imageBase64 = trim((string) $requestInput->input('image_base64', ''));
        if ($imageBase64 !== '') {
            // 允许 data URL 前缀
            $mediaType = trim((string) $requestInput->input('media_type', 'image/jpeg'));
            if (str_starts_with($imageBase64, 'data:')) {
                if (preg_match('#^data:([^;]+);base64,(.+)$#', $imageBase64, $matches) === 1) {
                    $mediaType = $matches[1];
                    $imageBase64 = $matches[2];
                }
            }

            if ($imageBase64 === '') {
                throw new SystemException('image_base64 is empty', 400);
            }

            return new ImageContent(
                content: $imageBase64,
                sourceType: SourceType::BASE64,
                mediaType: $mediaType !== '' ? $mediaType : 'image/jpeg',
            );
        }

        throw new SystemException(
            'image is required: upload field "image", or pass image_url / image_base64',
            400,
        );
    }

    private function imageContentFromUpload(UploadedFile $uploaded): ImageContent
    {
        try {
            if (!$uploaded->isValid()) {
                throw new SystemException('uploaded image is invalid', 400);
            }
            $uploaded->validateMaxSize(self::MAX_IMAGE_BYTES);
            $uploaded->validateExtensions(self::ALLOWED_EXTENSIONS);
        } catch (UploadException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }

        $binary = $uploaded->getContents();
        if ($binary === '') {
            throw new SystemException('uploaded image is empty', 400);
        }

        $mime = $uploaded->getMimeType();
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = $this->guessMediaTypeFromName($uploaded->getClientOriginalName()) ?? 'image/jpeg';
        }

        return new ImageContent(
            content: base64_encode($binary),
            sourceType: SourceType::BASE64,
            mediaType: $mime,
        );
    }

    private function guessMediaTypeFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        return $this->guessMediaTypeFromName($path);
    }

    private function guessMediaTypeFromName(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => null,
        };
    }
}

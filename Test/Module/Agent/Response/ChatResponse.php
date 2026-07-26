<?php

declare(strict_types=1);

namespace Test\Module\Agent\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

/**
 * Agent 对话响应（POST /api/v1/agent/chat、/chat1）。
 */
final class ChatResponse extends BaseResponse
{
    #[ApiProperty(description: '会话线程 ID（userId:sessionId）')]
    protected string $threadId;

    #[ApiProperty(description: '用户输入消息')]
    protected string $message;

    #[ApiProperty(description: '助手回复内容')]
    protected string $reply;

    #[ApiProperty(description: '模型提供者别名（ai_model_providers）')]
    protected string $provider;

    #[ApiProperty(description: '模型名称；未指定时为 null')]
    protected ?string $model = null;

    public function __construct(
        string $threadId,
        string $message,
        string $reply,
        string $provider,
        ?string $model = null,
    ) {
        $this->threadId = $threadId;
        $this->message = $message;
        $this->reply = $reply;
        $this->provider = $provider;
        $this->model = $model;
    }

    public function getThreadId(): string
    {
        return $this->threadId;
    }

    public function setThreadId(string $threadId): static
    {
        $this->threadId = $threadId;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getReply(): string
    {
        return $this->reply;
    }

    public function setReply(string $reply): static
    {
        $this->reply = $reply;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getData(): array
    {
        return [
            'threadId' => $this->threadId,
            'message' => $this->message,
            'reply' => $this->reply,
            'provider' => $this->provider,
            'model' => $this->model,
        ];
    }
}

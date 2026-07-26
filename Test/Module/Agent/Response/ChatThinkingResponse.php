<?php

declare(strict_types=1);

namespace Test\Module\Agent\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

/**
 * DeepSeek 思考模式对话响应（POST /api/v1/agent/chat-thinking）。
 */
final class ChatThinkingResponse extends BaseResponse
{
    #[ApiProperty(description: '会话线程 ID（userId:sessionId）')]
    protected string $threadId;

    #[ApiProperty(description: '用户输入消息')]
    protected string $message;

    #[ApiProperty(description: '助手回复内容')]
    protected string $reply;

    #[ApiProperty(description: '思考过程内容（DeepSeek reasoning）；无则为 null')]
    protected ?string $reasoning_content = null;

    #[ApiProperty(description: '模型提供者别名')]
    protected string $provider;

    #[ApiProperty(description: '模型名称')]
    protected string $model;

    #[ApiProperty(description: '思考模式：enabled | disabled')]
    protected string $thinking;

    #[ApiProperty(description: '推理强度：high | max；thinking=disabled 时为 null')]
    protected ?string $reasoning_effort = null;

    public function __construct(
        string $threadId,
        string $message,
        string $reply,
        ?string $reasoningContent,
        string $provider,
        string $model,
        string $thinking,
        ?string $reasoningEffort,
    ) {
        $this->threadId = $threadId;
        $this->message = $message;
        $this->reply = $reply;
        $this->reasoning_content = $reasoningContent;
        $this->provider = $provider;
        $this->model = $model;
        $this->thinking = $thinking;
        $this->reasoning_effort = $reasoningEffort;
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

    public function getReasoningContent(): ?string
    {
        return $this->reasoning_content;
    }

    public function setReasoningContent(?string $reasoningContent): static
    {
        $this->reasoning_content = $reasoningContent;

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

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getThinking(): string
    {
        return $this->thinking;
    }

    public function setThinking(string $thinking): static
    {
        $this->thinking = $thinking;

        return $this;
    }

    public function getReasoningEffort(): ?string
    {
        return $this->reasoning_effort;
    }

    public function setReasoningEffort(?string $reasoningEffort): static
    {
        $this->reasoning_effort = $reasoningEffort;

        return $this;
    }

    public function getData(): array
    {
        return [
            'threadId' => $this->threadId,
            'message' => $this->message,
            'reply' => $this->reply,
            'reasoning_content' => $this->reasoning_content,
            'provider' => $this->provider,
            'model' => $this->model,
            'thinking' => $this->thinking,
            'reasoning_effort' => $this->reasoning_effort,
        ];
    }
}

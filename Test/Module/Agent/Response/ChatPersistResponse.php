<?php

declare(strict_types=1);

namespace Test\Module\Agent\Response;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Http\BaseResponse;

/**
 * SQL 持久化多轮会话响应（POST /api/v1/agent/chat-persist）。
 */
final class ChatPersistResponse extends BaseResponse
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

    #[ApiProperty(description: '是否已写入 SQL 记忆')]
    protected bool $persisted;

    #[ApiProperty(description: '当前会话历史消息条数（含本轮）')]
    protected int $historyCount;

    #[ApiProperty(description: '记忆后端标识，如 sql')]
    protected string $memory;

    public function __construct(
        string $threadId,
        string $message,
        string $reply,
        string $provider,
        ?string $model,
        int $historyCount,
        bool $persisted = true,
        string $memory = 'sql',
    ) {
        $this->threadId = $threadId;
        $this->message = $message;
        $this->reply = $reply;
        $this->provider = $provider;
        $this->model = $model;
        $this->persisted = $persisted;
        $this->historyCount = $historyCount;
        $this->memory = $memory;
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

    public function getPersisted(): bool
    {
        return $this->persisted;
    }

    public function setPersisted(bool $persisted): static
    {
        $this->persisted = $persisted;

        return $this;
    }

    public function getHistoryCount(): int
    {
        return $this->historyCount;
    }

    public function setHistoryCount(int $historyCount): static
    {
        $this->historyCount = $historyCount;

        return $this;
    }

    public function getMemory(): string
    {
        return $this->memory;
    }

    public function setMemory(string $memory): static
    {
        $this->memory = $memory;

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
            'persisted' => $this->persisted,
            'historyCount' => $this->historyCount,
            'memory' => $this->memory,
        ];
    }
}

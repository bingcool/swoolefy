<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron任务项DTO
 */
class CronItemDto extends AbstractDto
{
    #[ApiProperty(description: '任务ID')]
    protected int $id = 0;

    #[ApiProperty(description: '节点ID')]
    protected int $node_id = 0;

    #[ApiProperty(description: '任务名称')]
    protected string $name = '';

    #[ApiProperty(description: 'Cron表达式')]
    protected string $expression = '';

    #[ApiProperty(description: '执行命令')]
    protected string $command = '';

    #[ApiProperty(description: '执行类型：1=shell, 2=http')]
    protected int $exec_type = 1;

    #[ApiProperty(description: '任务状态：0=禁用, 1=启用')]
    protected int $status = 1;

    #[ApiProperty(description: '是否阻塞重叠执行：0=否, 1=是')]
    protected int $with_block_lapping = 0;

    #[ApiProperty(description: '任务描述')]
    protected string $description = '';

    #[ApiProperty(description: '允许执行时间段')]
    protected array $cron_between = [];

    #[ApiProperty(description: '跳过执行时间段')]
    protected array $cron_skip = [];

    #[ApiProperty(description: 'HTTP请求方法')]
    protected string $http_method = 'GET';

    #[ApiProperty(description: 'HTTP请求体')]
    protected ?array $http_body = null;

    #[ApiProperty(description: 'HTTP请求头')]
    protected ?array $http_headers = null;

    #[ApiProperty(description: 'HTTP请求超时时间（秒）')]
    protected int $http_request_time_out = 30;

    #[ApiProperty(description: '创建时间')]
    protected string $created_at = '';

    #[ApiProperty(description: '更新时间')]
    protected string $updated_at = '';

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getNodeId(): int
    {
        return $this->node_id;
    }

    public function setNodeId(int $node_id): static
    {
        $this->node_id = $node_id;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function setExpression(string $expression): static
    {
        $this->expression = $expression;
        return $this;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function setCommand(string $command): static
    {
        $this->command = $command;
        return $this;
    }

    public function getExecType(): int
    {
        return $this->exec_type;
    }

    public function setExecType(int $exec_type): static
    {
        $this->exec_type = $exec_type;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getWithBlockLapping(): int
    {
        return $this->with_block_lapping;
    }

    public function setWithBlockLapping(int $with_block_lapping): static
    {
        $this->with_block_lapping = $with_block_lapping;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCronBetween(): array
    {
        return $this->cron_between;
    }

    public function setCronBetween(array $cron_between): static
    {
        $this->cron_between = $cron_between;
        return $this;
    }

    public function getCronSkip(): array
    {
        return $this->cron_skip;
    }

    public function setCronSkip(array $cron_skip): static
    {
        $this->cron_skip = $cron_skip;
        return $this;
    }

    public function getHttpMethod(): string
    {
        return $this->http_method;
    }

    public function setHttpMethod(string $http_method): static
    {
        $this->http_method = $http_method;
        return $this;
    }

    public function getHttpBody(): ?array
    {
        return $this->http_body;
    }

    public function setHttpBody(?array $http_body): static
    {
        $this->http_body = $http_body;
        return $this;
    }

    public function getHttpHeaders(): ?array
    {
        return $this->http_headers;
    }

    public function setHttpHeaders(?array $http_headers): static
    {
        $this->http_headers = $http_headers;
        return $this;
    }

    public function getHttpRequestTimeOut(): int
    {
        return $this->http_request_time_out;
    }

    public function setHttpRequestTimeOut(int $http_request_time_out): static
    {
        $this->http_request_time_out = $http_request_time_out;
        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->created_at;
    }

    public function setCreatedAt(string $created_at): static
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getUpdatedAt(): string
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(string $updated_at): static
    {
        $this->updated_at = $updated_at;
        return $this;
    }
}

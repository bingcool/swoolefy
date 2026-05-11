<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * Cron 任务列表/详情行（与 cron_task 查询字段对齐）。
 */
class CronTaskRowDto extends AbstractDto
{
    #[ApiProperty(description: '任务 ID')]
    protected int $id = 0;

    #[ApiProperty(description: '节点 ID')]
    protected int $nodeId = 0;

    #[ApiProperty(description: '任务名称')]
    protected string $name = '';

    #[ApiProperty(description: 'Cron 表达式')]
    protected string $expression = '';

    #[ApiProperty(description: '执行命令或 URL')]
    protected string $command = '';

    #[ApiProperty(description: '执行类型：1=shell, 2=http')]
    protected int $execType = 1;

    #[ApiProperty(description: '任务状态：0=禁用, 1=启用')]
    protected int $status = 1;

    #[ApiProperty(description: '是否阻塞重叠执行：0=否, 1=是')]
    protected int $withBlockLapping = 0;

    #[ApiProperty(description: '任务描述')]
    protected string $description = '';

    /**
     * @var array<int, array<string, mixed>>
     */
    #[ApiProperty(description: '允许执行时间段列表')]
    protected array $cronBetween = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    #[ApiProperty(description: '跳过执行时间段列表')]
    protected array $cronSkip = [];

    #[ApiProperty(description: 'HTTP 请求方法')]
    protected string $httpMethod = 'GET';

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求体')]
    protected ?array $httpBody = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求头')]
    protected ?array $httpHeaders = null;

    #[ApiProperty(description: 'HTTP 请求超时时间（秒）')]
    protected int $httpRequestTimeOut = 30;

    #[ApiProperty(description: '创建时间')]
    protected string $createdAt = '';

    #[ApiProperty(description: '更新时间')]
    protected string $updatedAt = '';

    /**
     * @param array<string, mixed> $row
     */
    public static function fromEntityRow(array $row): self
    {
        $dto = new self();
        $dto->setId((int)($row['id'] ?? 0));
        $dto->setNodeId((int)($row['node_id'] ?? 0));
        $dto->setName((string)($row['name'] ?? ''));
        $dto->setExpression((string)($row['expression'] ?? ''));
        $dto->setCommand((string)($row['command'] ?? ''));
        $dto->setExecType((int)($row['exec_type'] ?? 1));
        $dto->setStatus((int)($row['status'] ?? 1));
        $dto->setWithBlockLapping((int)($row['with_block_lapping'] ?? 0));
        $dto->setDescription((string)($row['description'] ?? ''));
        $cb = $row['cron_between'] ?? [];
        $dto->setCronBetween(is_array($cb) ? $cb : []);
        $cs = $row['cron_skip'] ?? [];
        $dto->setCronSkip(is_array($cs) ? $cs : []);
        $dto->setHttpMethod((string)($row['http_method'] ?? 'GET'));
        $hb = $row['http_body'] ?? null;
        $dto->setHttpBody(is_array($hb) ? $hb : null);
        $hh = $row['http_headers'] ?? null;
        $dto->setHttpHeaders(is_array($hh) ? $hh : null);
        $dto->setHttpRequestTimeOut((int)($row['http_request_time_out'] ?? 30));
        $dto->setCreatedAt((string)($row['created_at'] ?? ''));
        $dto->setUpdatedAt((string)($row['updated_at'] ?? ''));

        return $dto;
    }

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
        return $this->nodeId;
    }

    public function setNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;

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
        return $this->execType;
    }

    public function setExecType(int $execType): static
    {
        $this->execType = $execType;

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
        return $this->withBlockLapping;
    }

    public function setWithBlockLapping(int $withBlockLapping): static
    {
        $this->withBlockLapping = $withBlockLapping;

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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCronBetween(): array
    {
        return $this->cronBetween;
    }

    /**
     * @param array<int, array<string, mixed>> $cronBetween
     */
    public function setCronBetween(array $cronBetween): static
    {
        $this->cronBetween = $cronBetween;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCronSkip(): array
    {
        return $this->cronSkip;
    }

    /**
     * @param array<int, array<string, mixed>> $cronSkip
     */
    public function setCronSkip(array $cronSkip): static
    {
        $this->cronSkip = $cronSkip;

        return $this;
    }

    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHttpBody(): ?array
    {
        return $this->httpBody;
    }

    /**
     * @param array<string, mixed>|null $httpBody
     */
    public function setHttpBody(?array $httpBody): static
    {
        $this->httpBody = $httpBody;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHttpHeaders(): ?array
    {
        return $this->httpHeaders;
    }

    /**
     * @param array<string, mixed>|null $httpHeaders
     */
    public function setHttpHeaders(?array $httpHeaders): static
    {
        $this->httpHeaders = $httpHeaders;

        return $this;
    }

    public function getHttpRequestTimeOut(): int
    {
        return $this->httpRequestTimeOut;
    }

    public function setHttpRequestTimeOut(int $httpRequestTimeOut): static
    {
        $this->httpRequestTimeOut = $httpRequestTimeOut;

        return $this;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function setCreatedAt(string $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(string $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}

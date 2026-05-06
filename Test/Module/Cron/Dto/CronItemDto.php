<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

class CronItemDto extends AbstractDto
{
    #[ApiProperty(description: 'Task ID')]
    protected ?int $id = null;

    #[ApiProperty(description: 'Node ID')]
    protected ?int $node_id = null;

    #[ApiProperty(description: 'Task name')]
    protected ?string $name = null;

    #[ApiProperty(description: 'Cron expression')]
    protected ?string $expression = null;

    #[ApiProperty(description: 'Command or URL')]
    protected ?string $command = null;

    #[ApiProperty(description: 'Execution type')]
    protected ?int $exec_type = null;

    #[ApiProperty(description: 'Status')]
    protected ?int $status = null;

    #[ApiProperty(description: 'Block overlapping execution flag')]
    protected ?int $with_block_lapping = null;

    #[ApiProperty(description: 'Description')]
    protected ?string $description = null;

    /**
     * @var array<int, CronTimeRangeDto>|null
     */
    #[ApiProperty(description: 'Allowed execution time ranges')]
    protected ?array $cron_between = null;

    /**
     * @var array<int, CronTimeRangeDto>|null
     */
    #[ApiProperty(description: 'Skipped execution time ranges')]
    protected ?array $cron_skip = null;

    #[ApiProperty(description: 'HTTP method')]
    protected ?string $http_method = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP request body')]
    protected ?array $http_body = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP request headers')]
    protected ?array $http_headers = null;

    #[ApiProperty(description: 'HTTP request timeout')]
    protected ?int $http_request_time_out = null;

    #[ApiProperty(description: 'Created time')]
    protected ?string $created_at = null;

    #[ApiProperty(description: 'Updated time')]
    protected ?string $updated_at = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getNodeId(): ?int
    {
        return $this->node_id;
    }

    public function setNodeId(?int $node_id): self
    {
        $this->node_id = $node_id;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getExpression(): ?string
    {
        return $this->expression;
    }

    public function setExpression(?string $expression): self
    {
        $this->expression = $expression;

        return $this;
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }

    public function setCommand(?string $command): self
    {
        $this->command = $command;

        return $this;
    }

    public function getExecType(): ?int
    {
        return $this->exec_type;
    }

    public function setExecType(?int $exec_type): self
    {
        $this->exec_type = $exec_type;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getWithBlockLapping(): ?int
    {
        return $this->with_block_lapping;
    }

    public function setWithBlockLapping(?int $with_block_lapping): self
    {
        $this->with_block_lapping = $with_block_lapping;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return array<int, CronTimeRangeDto>|null
     */
    public function getCronBetween(): ?array
    {
        return $this->cron_between;
    }

    /**
     * @param array<int, CronTimeRangeDto>|null $cron_between
     */
    public function setCronBetween(?array $cron_between): self
    {
        $this->cron_between = $cron_between;

        return $this;
    }

    public function addCronBetween(CronTimeRangeDto $cron_between): self
    {
        if ($this->cron_between === null) {
            $this->cron_between = [];
        }

        $this->cron_between[] = $cron_between;

        return $this;
    }

    /**
     * @return array<int, CronTimeRangeDto>|null
     */
    public function getCronSkip(): ?array
    {
        return $this->cron_skip;
    }

    /**
     * @param array<int, CronTimeRangeDto>|null $cron_skip
     */
    public function setCronSkip(?array $cron_skip): self
    {
        $this->cron_skip = $cron_skip;

        return $this;
    }

    public function addCronSkip(CronTimeRangeDto $cron_skip): self
    {
        if ($this->cron_skip === null) {
            $this->cron_skip = [];
        }

        $this->cron_skip[] = $cron_skip;

        return $this;
    }

    public function getHttpMethod(): ?string
    {
        return $this->http_method;
    }

    public function setHttpMethod(?string $http_method): self
    {
        $this->http_method = $http_method;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHttpBody(): ?array
    {
        return $this->http_body;
    }

    /**
     * @param array<string, mixed>|null $http_body
     */
    public function setHttpBody(?array $http_body): self
    {
        $this->http_body = $http_body;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getHttpHeaders(): ?array
    {
        return $this->http_headers;
    }

    /**
     * @param array<string, mixed>|null $http_headers
     */
    public function setHttpHeaders(?array $http_headers): self
    {
        $this->http_headers = $http_headers;

        return $this;
    }

    public function getHttpRequestTimeOut(): ?int
    {
        return $this->http_request_time_out;
    }

    public function setHttpRequestTimeOut(?int $http_request_time_out): self
    {
        $this->http_request_time_out = $http_request_time_out;

        return $this;
    }

    public function getCreatedAt(): ?string
    {
        return $this->created_at;
    }

    public function setCreatedAt(?string $created_at): self
    {
        $this->created_at = $created_at;

        return $this;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(?string $updated_at): self
    {
        $this->updated_at = $updated_at;

        return $this;
    }
}

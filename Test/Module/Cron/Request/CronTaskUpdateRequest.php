<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;
use Test\Module\Cron\Dto\CronTimeRangeDto;

class CronTaskUpdateRequest extends BaseRequest
{
    #[ApiProperty(description: '任务 ID')]
    #[ValidationRule(rule: 'required|int', message: 'id不能为空')]
    #[StringToInt]
    protected int $id = 0;

    #[ApiProperty(description: '任务名称')]
    protected ?string $name = null;

    #[ApiProperty(description: 'Cron 表达式')]
    protected ?string $expression = null;

    #[ApiProperty(description: '执行命令或 URL')]
    protected ?string $command = null;

    #[ApiProperty(description: '描述')]
    protected ?string $description = null;

    #[ApiProperty(description: '节点 ID')]
    #[StringToInt]
    protected ?int $node_id = null;

    #[ApiProperty(description: '执行类型：1 shell，2 http')]
    protected ?int $exec_type = null;

    #[ApiProperty(description: '状态：0 禁用，1 启用')]
    protected ?int $status = null;

    #[ApiProperty(description: '是否阻塞重叠执行')]
    protected ?int $with_block_lapping = null;

    #[ApiProperty(description: 'HTTP 方法')]
    protected ?string $http_method = null;

    #[ApiProperty(description: 'HTTP 超时（秒）')]
    protected ?int $http_request_time_out = null;

    /**
     * @var array<int, CronTimeRangeDto>|null
     */
    #[ApiProperty(description: '允许执行时间段列表')]
    #[ValidationRule(rule: 'nullable|array', message: 'cron_between 格式错误', itemClass: CronTimeRangeDto::class)]
    protected ?array $cron_between = null;

    /**
     * @var array<int, CronTimeRangeDto>|null
     */
    #[ApiProperty(description: '需跳过的时间段列表')]
    #[ValidationRule(rule: 'nullable|array', message: 'cron_skip 格式错误', itemClass: CronTimeRangeDto::class)]
    protected ?array $cron_skip = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求体')]
    protected ?array $http_body = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求头')]
    protected ?array $http_headers = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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

    public function getHttpMethod(): ?string
    {
        return $this->http_method;
    }

    public function setHttpMethod(?string $http_method): self
    {
        $this->http_method = $http_method;

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

    /**
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        $out = [];
        if ($this->name !== null) {
            $out['name'] = $this->name;
        }
        if ($this->expression !== null) {
            $out['expression'] = $this->expression;
        }
        if ($this->command !== null) {
            $out['command'] = $this->command;
        }
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->node_id !== null) {
            $out['node_id'] = $this->node_id;
        }
        if ($this->exec_type !== null) {
            $out['exec_type'] = $this->exec_type;
        }
        if ($this->status !== null) {
            $out['status'] = $this->status;
        }
        if ($this->with_block_lapping !== null) {
            $out['with_block_lapping'] = $this->with_block_lapping;
        }
        if ($this->http_method !== null) {
            $out['http_method'] = $this->http_method;
        }
        if ($this->http_request_time_out !== null) {
            $out['http_request_time_out'] = $this->http_request_time_out;
        }
        if ($this->cron_between !== null) {
            $out['cron_between'] = $this->serializeTimeRanges($this->cron_between);
        }
        if ($this->cron_skip !== null) {
            $out['cron_skip'] = $this->serializeTimeRanges($this->cron_skip);
        }
        if ($this->http_body !== null) {
            $out['http_body'] = $this->http_body;
        }
        if ($this->http_headers !== null) {
            $out['http_headers'] = $this->http_headers;
        }

        return $out;
    }

    /**
     * @param array<int, CronTimeRangeDto> $ranges
     * @return array<int, array{start: string, end: string}>|null
     */
    protected function serializeTimeRanges(array $ranges): ?array
    {
        if ($ranges === []) {
            return null;
        }

        $out = [];
        foreach ($ranges as $row) {
            if ($row instanceof CronTimeRangeDto) {
                $out[] = [
                    'start' => $row->getStart(),
                    'end' => $row->getEnd(),
                ];
            }
        }

        return $out === [] ? null : $out;
    }
}

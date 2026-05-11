<?php

declare(strict_types=1);


namespace Test\Module\Cron\Request\CronTaskManager;

use Test\Module\Cron\Dto\CronTaskManager\CronTimeRangeDto;
use InvalidArgumentException;
use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronTaskCreateRequest extends BaseRequest
{
    #[ApiProperty(description: '任务名称')]
    #[ValidationRule(rule: 'required|string', message: 'name 不能为空')]
    protected string $name = '';

    #[ApiProperty(description: 'Cron 表达式')]
    #[ValidationRule(rule: 'required|string', message: 'expression 不能为空')]
    protected string $expression = '';

    #[ApiProperty(description: '执行命令或 URL')]
    #[ValidationRule(rule: 'required|string', message: 'command 不能为空')]
    protected string $command = '';

    #[ApiProperty(description: '执行类型：1 shell，2 http')]
    #[ValidationRule(rule: 'required|int', message: 'execType 不能为空')]
    protected int $execType = 0;

    #[ApiProperty(description: '节点 ID')]
    #[ValidationRule(rule: 'required|int', message: 'nodeId 不能为空')]
    #[StringToInt]
    protected int $nodeId = 0;

    #[ApiProperty(description: '描述')]
    protected ?string $description = null;

    #[ApiProperty(description: '状态：0 禁用，1 启用')]
    protected ?int $status = null;

    #[ApiProperty(description: '是否阻塞重叠执行：0 否，1 是')]
    protected ?int $withBlockLapping = null;

    #[ApiProperty(description: 'HTTP 方法')]
    protected ?string $httpMethod = null;

    #[ApiProperty(description: 'HTTP 超时（秒）')]
    protected ?int $httpRequestTimeOut = null;

    /**
     * @var array<int, CronTimeRangeDto>
     */
    #[ApiProperty(description: '允许执行时间段列表')]
    #[ValidationRule(rule: 'nullable|array', message: 'cronBetween 格式错误', itemClass: CronTimeRangeDto::class)]
    protected array $cronBetween = [];

    /**
     * @var array<int, CronTimeRangeDto>
     */
    #[ApiProperty(description: '需跳过的时间段列表')]
    #[ValidationRule(rule: 'nullable|array', message: 'cronSkip 格式错误', itemClass: CronTimeRangeDto::class)]
    protected array $cronSkip = [];

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求体（JSON 对象）')]
    protected ?array $httpBody = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求头（JSON 对象）')]
    protected ?array $httpHeaders = null;

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

    public function getNodeId(): int
    {
        return $this->nodeId;
    }

    public function setNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function setStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getWithBlockLapping(): ?int
    {
        return $this->withBlockLapping;
    }

    public function setWithBlockLapping(?int $withBlockLapping): static
    {
        $this->withBlockLapping = $withBlockLapping;

        return $this;
    }

    public function getHttpMethod(): ?string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(?string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;

        return $this;
    }

    public function getHttpRequestTimeOut(): ?int
    {
        return $this->httpRequestTimeOut;
    }

    public function setHttpRequestTimeOut(?int $httpRequestTimeOut): static
    {
        $this->httpRequestTimeOut = $httpRequestTimeOut;

        return $this;
    }

    /**
     * @return array<int, CronTimeRangeDto>
     */
    public function getCronBetween(): array
    {
        return $this->cronBetween;
    }

    /**
     * @param array<int, CronTimeRangeDto>|null $cronBetween
     */
    public function setCronBetween(?array $cronBetween): static
    {
        $cronBetween = $cronBetween ?? [];
        if ($cronBetween !== [] && !($cronBetween[0] instanceof CronTimeRangeDto)) {
            throw new InvalidArgumentException('cronBetween items must be instances of CronTimeRangeDto');
        }
        $this->cronBetween = $cronBetween;

        return $this;
    }

    public function addCronBetween(CronTimeRangeDto $item): static
    {
        $this->cronBetween[] = $item;

        return $this;
    }

    /**
     * @return array<int, CronTimeRangeDto>
     */
    public function getCronSkip(): array
    {
        return $this->cronSkip;
    }

    /**
     * @param array<int, CronTimeRangeDto> $cronSkip
     */
    public function setCronSkip(array $cronSkip): static
    {
        if ($cronSkip !== [] && !($cronSkip[0] instanceof CronTimeRangeDto)) {
            throw new InvalidArgumentException('cronSkip items must be instances of CronTimeRangeDto');
        }
        $this->cronSkip = $cronSkip;

        return $this;
    }

    public function addCronSkip(CronTimeRangeDto $item): static
    {
        $this->cronSkip[] = $item;

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

    /**
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        return [
            'name' => $this->getName(),
            'expression' => $this->getExpression(),
            'command' => $this->getCommand(),
            'exec_type' => $this->getExecType(),
            'node_id' => $this->getNodeId(),
            'description' => $this->getDescription(),
            'status' => $this->getStatus(),
            'with_block_lapping' => $this->getWithBlockLapping(),
            'http_method' => $this->getHttpMethod(),
            'http_request_time_out' => $this->getHttpRequestTimeOut(),
            'cron_between' => $this->serializeTimeRanges($this->getCronBetween()),
            'cron_skip' => $this->serializeTimeRanges($this->getCronSkip()),
            'http_body' => $this->getHttpBody(),
            'http_headers' => $this->getHttpHeaders(),
        ];
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

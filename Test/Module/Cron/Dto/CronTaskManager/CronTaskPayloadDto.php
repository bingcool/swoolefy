<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * 创建/更新 Cron 任务时写入实体的字段集合（仅包含本次应持久化的字段）。
 */
class CronTaskPayloadDto extends AbstractDto
{
    public const EXEC_TYPE_SHELL = 1;

    public const EXEC_TYPE_HTTP = 2;

    protected ?string $name = null;

    protected ?string $expression = null;

    protected ?string $command = null;

    protected ?string $description = null;

    protected ?int $nodeId = null;

    protected ?int $execType = null;

    protected ?int $status = null;

    protected ?int $withBlockLapping = null;

    protected ?string $httpMethod = null;

    protected ?int $httpRequestTimeOut = null;

    /**
     * @var array<int, array{start: string, end: string}>|null
     */
    protected ?array $cronBetween = null;

    /**
     * @var array<int, array{start: string, end: string}>|null
     */
    protected ?array $cronSkip = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $httpBody = null;

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $httpHeaders = null;

    /** @var array<string, true> */
    private array $presentFields = [];

    public function putName(string $name): static
    {
        $this->name = $name;
        $this->presentFields['name'] = true;

        return $this;
    }

    public function putExpression(string $expression): static
    {
        $this->expression = $expression;
        $this->presentFields['expression'] = true;

        return $this;
    }

    public function putCommand(string $command): static
    {
        $this->command = $command;
        $this->presentFields['command'] = true;

        return $this;
    }

    public function putDescription(string $description): static
    {
        $this->description = $description;
        $this->presentFields['description'] = true;

        return $this;
    }

    public function putNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;
        $this->presentFields['nodeId'] = true;

        return $this;
    }

    public function putExecType(int $execType): static
    {
        $this->execType = $execType;
        $this->presentFields['execType'] = true;

        return $this;
    }

    public function putStatus(int $status): static
    {
        $this->status = $status;
        $this->presentFields['status'] = true;

        return $this;
    }

    public function putWithBlockLapping(int $withBlockLapping): static
    {
        $this->withBlockLapping = $withBlockLapping;
        $this->presentFields['withBlockLapping'] = true;

        return $this;
    }

    public function putHttpMethod(string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;
        $this->presentFields['httpMethod'] = true;

        return $this;
    }

    public function putHttpRequestTimeOut(int $httpRequestTimeOut): static
    {
        $this->httpRequestTimeOut = $httpRequestTimeOut;
        $this->presentFields['httpRequestTimeOut'] = true;

        return $this;
    }

    /**
     * @param array<int, array{start: string, end: string}>|null $cronBetween
     */
    public function putCronBetween(?array $cronBetween): static
    {
        $this->cronBetween = $cronBetween;
        $this->presentFields['cronBetween'] = true;

        return $this;
    }

    /**
     * @param array<int, array{start: string, end: string}>|null $cronSkip
     */
    public function putCronSkip(?array $cronSkip): static
    {
        $this->cronSkip = $cronSkip;
        $this->presentFields['cronSkip'] = true;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $httpBody
     */
    public function putHttpBody(?array $httpBody): static
    {
        $this->httpBody = $httpBody;
        $this->presentFields['httpBody'] = true;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $httpHeaders
     */
    public function putHttpHeaders(?array $httpHeaders): static
    {
        $this->httpHeaders = $httpHeaders;
        $this->presentFields['httpHeaders'] = true;

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->presentFields === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toEntityArray(): array
    {
        $fieldMap = [
            'name' => 'name',
            'expression' => 'expression',
            'command' => 'command',
            'description' => 'description',
            'nodeId' => 'node_id',
            'execType' => 'exec_type',
            'status' => 'status',
            'withBlockLapping' => 'with_block_lapping',
            'httpMethod' => 'http_method',
            'httpRequestTimeOut' => 'http_request_time_out',
            'cronBetween' => 'cron_between',
            'cronSkip' => 'cron_skip',
            'httpBody' => 'http_body',
            'httpHeaders' => 'http_headers',
        ];

        $out = [];
        foreach ($fieldMap as $property => $column) {
            if (!isset($this->presentFields[$property])) {
                continue;
            }
            $out[$column] = $this->$property;
        }

        return $out;
    }
}

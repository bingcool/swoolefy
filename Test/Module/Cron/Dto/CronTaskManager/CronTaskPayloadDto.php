<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 创建/更新 Cron 任务时写入实体的字段集合 DTO。
 *
 * **职责**：以「显式 put + presentFields 追踪」模式表达部分更新语义，仅包含本次应持久化的字段。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskPayloadBuilder::build} 校验原始 payload
 * 后逐字段 put 构造。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService} 调用 {@see toEntityArray}
 * 转为 snake_case 关联数组后写入 {@see \Test\Module\Cron\Entity\CronTaskEntity}。
 *
 * **关键字段语义**：
 * - 各字段默认为 null 表示「未提交」；仅被 put* 方法标记的字段会出现在 toEntityArray 输出中
 * - execType：{@see EXEC_TYPE_SHELL}=1（shell），{@see EXEC_TYPE_HTTP}=2（http）
 * - presentFields：内部追踪已 put 的字段名，用于区分「未提交」与「提交为空值」
 */
class CronTaskPayloadDto extends AbstractDto
{
    /** shell/fork 执行类型常量 */
    public const EXEC_TYPE_SHELL = 1;

    /** HTTP 执行类型常量 */
    public const EXEC_TYPE_HTTP = 2;

    #[ApiProperty(description: '任务名称')]
    protected ?string $name = null;

    #[ApiProperty(description: 'Cron 表达式')]
    protected ?string $expression = null;

    #[ApiProperty(description: '执行命令或 URL')]
    protected ?string $command = null;

    #[ApiProperty(description: '任务描述')]
    protected ?string $description = null;

    #[ApiProperty(description: '绑定的 Agent 节点 ID')]
    protected ?int $nodeId = null;

    #[ApiProperty(description: '执行类型：1=shell，2=http')]
    protected ?int $execType = null;

    #[ApiProperty(description: '任务状态：0=禁用，1=启用')]
    protected ?int $status = null;

    #[ApiProperty(description: '是否阻塞重叠执行：0=否，1=是')]
    protected ?int $withBlockLapping = null;

    #[ApiProperty(description: '失败后重试次数（不含首次；0=不重试）')]
    protected ?int $retry = null;

    #[ApiProperty(description: 'HTTP 请求方法')]
    protected ?string $httpMethod = null;

    #[ApiProperty(description: 'HTTP 请求超时时间（秒）')]
    protected ?int $httpRequestTimeOut = null;

    /**
     * @var array<int, array{start: string, end: string}>|null
     */
    #[ApiProperty(description: '允许执行时间段列表')]
    protected ?array $cronBetween = null;

    /**
     * @var array<int, array{start: string, end: string}>|null
     */
    #[ApiProperty(description: '跳过执行时间段列表')]
    protected ?array $cronSkip = null;

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

    /**
     * @var array<string, true>
     */
    #[ApiProperty(description: '已显式 put 的字段名集合，用于部分更新追踪')]
    private array $presentFields = [];

    /** 标记并设置任务名称 */
    public function putName(string $name): static
    {
        $this->name = $name;
        $this->presentFields['name'] = true;

        return $this;
    }

    /** 标记并设置 Cron 表达式 */
    public function putExpression(string $expression): static
    {
        $this->expression = $expression;
        $this->presentFields['expression'] = true;

        return $this;
    }

    /** 标记并设置执行命令或 URL */
    public function putCommand(string $command): static
    {
        $this->command = $command;
        $this->presentFields['command'] = true;

        return $this;
    }

    /** 标记并设置任务描述 */
    public function putDescription(string $description): static
    {
        $this->description = $description;
        $this->presentFields['description'] = true;

        return $this;
    }

    /** 标记并设置绑定的 Agent 节点 ID */
    public function putNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;
        $this->presentFields['nodeId'] = true;

        return $this;
    }

    /**
     * 标记并设置执行类型。
     *
     * @param int $execType 1=shell，2=http
     */
    public function putExecType(int $execType): static
    {
        $this->execType = $execType;
        $this->presentFields['execType'] = true;

        return $this;
    }

    /**
     * 标记并设置任务状态。
     *
     * @param int $status 0=禁用，1=启用
     */
    public function putStatus(int $status): static
    {
        $this->status = $status;
        $this->presentFields['status'] = true;

        return $this;
    }

    /**
     * 标记并设置是否阻塞重叠执行。
     *
     * @param int $withBlockLapping 0=否，1=是
     */
    public function putWithBlockLapping(int $withBlockLapping): static
    {
        $this->withBlockLapping = $withBlockLapping;
        $this->presentFields['withBlockLapping'] = true;

        return $this;
    }

    /**
     * 标记并设置失败后重试次数（不含首次）。
     *
     * @param int $retry 0=不重试，N=最多再试 N 次
     */
    public function putRetry(int $retry): static
    {
        $this->retry = $retry;
        $this->presentFields['retry'] = true;

        return $this;
    }

    /** 标记并设置 HTTP 请求方法 */
    public function putHttpMethod(string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;
        $this->presentFields['httpMethod'] = true;

        return $this;
    }

    /** 标记并设置 HTTP 请求超时时间（秒） */
    public function putHttpRequestTimeOut(int $httpRequestTimeOut): static
    {
        $this->httpRequestTimeOut = $httpRequestTimeOut;
        $this->presentFields['httpRequestTimeOut'] = true;

        return $this;
    }

    /**
     * 标记并设置允许执行时间段列表。
     *
     * @param array<int, array{start: string, end: string}>|null $cronBetween
     */
    public function putCronBetween(?array $cronBetween): static
    {
        $this->cronBetween = $cronBetween;
        $this->presentFields['cronBetween'] = true;

        return $this;
    }

    /**
     * 标记并设置跳过执行时间段列表。
     *
     * @param array<int, array{start: string, end: string}>|null $cronSkip
     */
    public function putCronSkip(?array $cronSkip): static
    {
        $this->cronSkip = $cronSkip;
        $this->presentFields['cronSkip'] = true;

        return $this;
    }

    /**
     * 标记并设置 HTTP 请求体。
     *
     * @param array<string, mixed>|null $httpBody
     */
    public function putHttpBody(?array $httpBody): static
    {
        $this->httpBody = $httpBody;
        $this->presentFields['httpBody'] = true;

        return $this;
    }

    /**
     * 标记并设置 HTTP 请求头。
     *
     * @param array<string, mixed>|null $httpHeaders
     */
    public function putHttpHeaders(?array $httpHeaders): static
    {
        $this->httpHeaders = $httpHeaders;
        $this->presentFields['httpHeaders'] = true;

        return $this;
    }

    /**
     * 判断是否无任何已 put 字段（更新场景下表示空更新）。
     */
    public function isEmpty(): bool
    {
        return $this->presentFields === [];
    }

    /**
     * 将已 put 的字段转为数据库 snake_case 关联数组。
     *
     * 仅输出 presentFields 中标记过的字段，未 put 的字段不会出现在结果中，
     * 从而实现部分更新语义。API 字段 name 对应表列 cron_name。
     *
     * @return array<string, mixed>
     */
    public function toEntityArray(): array
    {
        $fieldMap = [
            'name' => 'cron_name',
            'expression' => 'expression',
            'command' => 'command',
            'description' => 'description',
            'nodeId' => 'node_id',
            'execType' => 'exec_type',
            'status' => 'status',
            'withBlockLapping' => 'with_block_lapping',
            'retry' => 'retry',
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

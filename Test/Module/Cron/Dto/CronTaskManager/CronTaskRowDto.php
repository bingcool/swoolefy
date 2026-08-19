<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;
use Swoolefy\Worker\Cron\CronNextRunAt;

/**
 * Cron 任务列表/详情行 DTO。
 *
 * **职责**：表示 cron_task 表的单条任务记录，字段与数据库查询结果对齐（camelCase 输出）。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::listTasks} 通过
 * {@see static::fromEntityRow} 映射查询行；{@see \Test\Module\Cron\Response\CronTaskManager\CronTaskRowResponse}
 * 在 create/update 后同样调用 fromEntityRow。
 *
 * **消费者**：{@see \Test\Module\Cron\Response\CronTaskManager\ListTasksPageResult} 收集列表项；
 * API 序列化时通过 ApiProperty 注解生成文档。
 *
 * **关键字段语义**：
 * - execType：1=shell，2=http
 * - status：0=禁用，1=启用
 * - withBlockLapping：0=允许重叠执行，1=阻塞重叠执行
 * - command：shell 时为脚本路径，http 时为请求 URL
 * - cronBetween / cronSkip：允许/跳过执行的时间段 JSON 数组
 * - nextRunAt：下次合法执行 unix 秒（展示层推算，不落库）；禁用/非法表达式为 null
 * - nextRunAtAt：同上的 datetime 串（Y-m-d H:i:s），无下次执行时为空
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

    #[ApiProperty(description: '失败后重试次数（不含首次；0=不重试）')]
    protected int $retry = 0;

    #[ApiProperty(description: '表达式类型：interval=每N秒，cron=Linux Cron（展示层派生，不落库）')]
    protected string $expressionType = 'interval';

    #[ApiProperty(description: '任务描述')]
    protected string $description = '';

    /**
     * 允许执行的时间段列表。
     *
     * @var array<int, array<string, mixed>>
     */
    #[ApiProperty(description: '允许执行时间段列表')]
    protected array $cronBetween = [];

    /**
     * 跳过执行的时间段列表。
     *
     * @var array<int, array<string, mixed>>
     */
    #[ApiProperty(description: '跳过执行时间段列表')]
    protected array $cronSkip = [];

    #[ApiProperty(description: 'HTTP 请求方法')]
    protected string $httpMethod = 'GET';

    /**
     * HTTP 请求体。
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求体')]
    protected ?array $httpBody = null;

    /**
     * HTTP 请求头。
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(description: 'HTTP 请求头')]
    protected ?array $httpHeaders = null;

    #[ApiProperty(description: 'HTTP 请求超时时间（秒）')]
    protected int $httpRequestTimeOut = 30;

    #[ApiProperty(description: '下次合法执行 unix 秒；禁用或非法表达式为 null（暂停，无下次调度）')]
    protected ?int $nextRunAt = null;

    #[ApiProperty(description: '下次合法执行时间（Y-m-d H:i:s）；无则空串，风格同 createdAt / lastHeartbeatAt')]
    protected string $nextRunAtAt = '';

    #[ApiProperty(description: '创建时间')]
    protected string $createdAt = '';

    #[ApiProperty(description: '更新时间')]
    protected string $updatedAt = '';

    /**
     * 从数据库实体行（snake_case）映射为 DTO。
     *
     * JSON 列（cron_between、cron_skip、http_body、http_headers）在非法类型时
     * 分别回退为空数组或 null。
     *
     * nextRunAt 由 {@see CronNextRunAt::compute} 按引擎规则推算，不读 Worker 内存。
     *
     * @param array<string, mixed> $row cron_task 查询行或实体 getAttributes() 结果
     * @param int|null $now 推算基准 unix 秒；空则 time()。单测可注入以对齐网格
     */
    public static function fromEntityRow(array $row, ?int $now = null): self
    {
        $dto = new self();
        $dto->setId((int)($row['id'] ?? 0));
        $dto->setNodeId((int)($row['node_id'] ?? 0));
        // 表列为 cron_name，兼容误用 name 的查询结果
        $dto->setName((string)($row['name'] ?? $row['cron_name'] ?? ''));
        $dto->setExpression((string)($row['expression'] ?? ''));
        $dto->setCommand((string)($row['command'] ?? ''));
        $dto->setExecType((int)($row['exec_type'] ?? 1));
        $dto->setStatus((int)($row['status'] ?? 1));
        $dto->setWithBlockLapping((int)($row['with_block_lapping'] ?? 0));
        $dto->setRetry(max(0, (int)($row['retry'] ?? 0)));
        $dto->setExpressionType(self::deriveExpressionType((string)($row['expression'] ?? '')));
        $dto->setDescription((string)($row['description'] ?? ''));
        $cb = $row['cron_between'] ?? [];
        $dto->setCronBetween(is_array($cb) ? $cb : []);
        $cs = $row['cron_skip'] ?? [];
        $dto->setCronSkip(is_array($cs) ? $cs : []);
        $dto->setHttpMethod((string)($row['http_method'] ?? 'GET'));
        $hb = $row['http_body'] ?? null;
        $dto->setHttpBody(is_array($hb) ? $hb : null);
        $hh = $row['http_headers'] ?? null;
        $dto->setHttpHeaders(self::maskSensitiveHeaders(is_array($hh) ? $hh : null));
        $dto->setHttpRequestTimeOut((int)($row['http_request_time_out'] ?? 30));
        $dto->setCreatedAt((string)($row['created_at'] ?? ''));
        $dto->setUpdatedAt((string)($row['updated_at'] ?? ''));

        $computeRow = $row;
        $computeRow['status'] = $dto->getStatus();
        $computeRow['expression'] = $dto->getExpression();
        $computeRow['cron_name'] = $dto->getName();
        $computeRow['command'] = $dto->getCommand();
        $computeRow['cron_between'] = $dto->getCronBetween();
        $computeRow['cron_skip'] = $dto->getCronSkip();
        $next = CronNextRunAt::compute($computeRow, $now);
        $dto->setNextRunAt($next);
        $dto->setNextRunAtAt(CronNextRunAt::formatDatetime($next));

        return $dto;
    }

    /** 获取任务 ID */
    public function getId(): int
    {
        return $this->id;
    }

    /** 设置任务 ID */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /** 获取节点 ID */
    public function getNodeId(): int
    {
        return $this->nodeId;
    }

    /** 设置节点 ID */
    public function setNodeId(int $nodeId): static
    {
        $this->nodeId = $nodeId;

        return $this;
    }

    /** 获取任务名称 */
    public function getName(): string
    {
        return $this->name;
    }

    /** 设置任务名称 */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** 获取 Cron 表达式 */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /** 设置 Cron 表达式 */
    public function setExpression(string $expression): static
    {
        $this->expression = $expression;

        return $this;
    }

    /** 获取执行命令或 URL */
    public function getCommand(): string
    {
        return $this->command;
    }

    /** 设置执行命令或 URL */
    public function setCommand(string $command): static
    {
        $this->command = $command;

        return $this;
    }

    /** 获取执行类型 */
    public function getExecType(): int
    {
        return $this->execType;
    }

    /** 设置执行类型 */
    public function setExecType(int $execType): static
    {
        $this->execType = $execType;

        return $this;
    }

    /** 获取任务状态 */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** 设置任务状态 */
    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * 由 expression 派生展示类型：纯数字 → interval，否则 cron。
     */
    public static function deriveExpressionType(string $expression): string
    {
        $expression = trim($expression);

        return $expression !== '' && ctype_digit($expression) ? 'interval' : 'cron';
    }

    /**
     * 列表/详情不回传 Authorization/Cookie/Token 等敏感 Header 明文。
     *
     * @param array<string, mixed>|null $headers
     * @return array<string, mixed>|null
     */
    public static function maskSensitiveHeaders(?array $headers): ?array
    {
        if ($headers === null) {
            return null;
        }

        $sensitive = ['authorization', 'cookie', 'token', 'x-api-key', 'api-key', 'password', 'secret'];
        $masked = [];
        foreach ($headers as $key => $value) {
            $lower = strtolower((string)$key);
            $hit = false;
            foreach ($sensitive as $needle) {
                if ($lower === $needle || str_contains($lower, $needle)) {
                    $hit = true;
                    break;
                }
            }
            $masked[$key] = $hit ? '******' : $value;
        }

        return $masked;
    }

    /** 获取是否阻塞重叠执行 */
    public function getWithBlockLapping(): int
    {
        return $this->withBlockLapping;
    }

    /** 设置是否阻塞重叠执行 */
    public function setWithBlockLapping(int $withBlockLapping): static
    {
        $this->withBlockLapping = $withBlockLapping;

        return $this;
    }

    /** 获取失败后重试次数 */
    public function getRetry(): int
    {
        return $this->retry;
    }

    /** 设置失败后重试次数 */
    public function setRetry(int $retry): static
    {
        $this->retry = max(0, $retry);

        return $this;
    }

    /** 获取表达式展示类型 */
    public function getExpressionType(): string
    {
        return $this->expressionType;
    }

    /** 设置表达式展示类型 */
    public function setExpressionType(string $expressionType): static
    {
        $this->expressionType = $expressionType;

        return $this;
    }

    /** 获取任务描述 */
    public function getDescription(): string
    {
        return $this->description;
    }

    /** 设置任务描述 */
    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * 获取允许执行时间段列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCronBetween(): array
    {
        return $this->cronBetween;
    }

    /**
     * 设置允许执行时间段列表。
     *
     * @param array<int, array<string, mixed>> $cronBetween
     */
    public function setCronBetween(array $cronBetween): static
    {
        $this->cronBetween = $cronBetween;

        return $this;
    }

    /**
     * 获取跳过执行时间段列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCronSkip(): array
    {
        return $this->cronSkip;
    }

    /**
     * 设置跳过执行时间段列表。
     *
     * @param array<int, array<string, mixed>> $cronSkip
     */
    public function setCronSkip(array $cronSkip): static
    {
        $this->cronSkip = $cronSkip;

        return $this;
    }

    /** 获取 HTTP 请求方法 */
    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    /** 设置 HTTP 请求方法 */
    public function setHttpMethod(string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;

        return $this;
    }

    /**
     * 获取 HTTP 请求体。
     *
     * @return array<string, mixed>|null
     */
    public function getHttpBody(): ?array
    {
        return $this->httpBody;
    }

    /**
     * 设置 HTTP 请求体。
     *
     * @param array<string, mixed>|null $httpBody
     */
    public function setHttpBody(?array $httpBody): static
    {
        $this->httpBody = $httpBody;

        return $this;
    }

    /**
     * 获取 HTTP 请求头。
     *
     * @return array<string, mixed>|null
     */
    public function getHttpHeaders(): ?array
    {
        return $this->httpHeaders;
    }

    /**
     * 设置 HTTP 请求头。
     *
     * @param array<string, mixed>|null $httpHeaders
     */
    public function setHttpHeaders(?array $httpHeaders): static
    {
        $this->httpHeaders = $httpHeaders;

        return $this;
    }

    /** 获取 HTTP 请求超时时间（秒） */
    public function getHttpRequestTimeOut(): int
    {
        return $this->httpRequestTimeOut;
    }

    /** 设置 HTTP 请求超时时间（秒） */
    public function setHttpRequestTimeOut(int $httpRequestTimeOut): static
    {
        $this->httpRequestTimeOut = $httpRequestTimeOut;

        return $this;
    }

    /** 获取下次合法执行 unix 秒；无则为 null */
    public function getNextRunAt(): ?int
    {
        return $this->nextRunAt;
    }

    /** 设置下次合法执行 unix 秒 */
    public function setNextRunAt(?int $nextRunAt): static
    {
        $this->nextRunAt = $nextRunAt;

        return $this;
    }

    /** 获取下次合法执行 datetime 串；无则为空 */
    public function getNextRunAtAt(): string
    {
        return $this->nextRunAtAt;
    }

    /** 设置下次合法执行 datetime 串 */
    public function setNextRunAtAt(string $nextRunAtAt): static
    {
        $this->nextRunAtAt = $nextRunAtAt;

        return $this;
    }

    /** 获取创建时间 */
    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    /** 设置创建时间 */
    public function setCreatedAt(string $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /** 获取更新时间 */
    public function getUpdatedAt(): string
    {
        return $this->updatedAt;
    }

    /** 设置更新时间 */
    public function setUpdatedAt(string $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}

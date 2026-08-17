<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * 表达式预览结果：统一走引擎 ExpressionParser，不另写解析器。
 */
class ExpressionPreviewResultDto extends AbstractDto
{
    #[ApiProperty(description: '是否合法')]
    protected bool $valid = false;

    #[ApiProperty(description: 'interval 或 cron')]
    protected string $type = '';

    #[ApiProperty(description: '人类可读描述或错误信息')]
    protected string $description = '';

    /**
     * @var list<string>
     */
    #[ApiProperty(description: '接下来若干次执行时间')]
    protected array $nextRuns = [];

    /**
     * @param list<string> $nextRuns
     */
    public static function ok(string $type, string $description, array $nextRuns): self
    {
        $dto = new self();
        $dto->valid = true;
        $dto->type = $type;
        $dto->description = $description;
        $dto->nextRuns = $nextRuns;

        return $dto;
    }

    public static function invalid(string $description): self
    {
        $dto = new self();
        $dto->valid = false;
        $dto->type = '';
        $dto->description = $description;
        $dto->nextRuns = [];

        return $dto;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return list<string>
     */
    public function getNextRuns(): array
    {
        return $this->nextRuns;
    }
}

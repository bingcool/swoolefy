<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Core\Dto\AbstractDto;

/**
 * buildTaskPayload 构建结果：成功时携带 CronTaskPayloadDto，失败时携带错误信息。
 */
class CronTaskPayloadBuildResultDto extends AbstractDto
{
    protected ?CronTaskPayloadDto $payload = null;

    protected ?string $error = null;

    public static function ok(CronTaskPayloadDto $payload): self
    {
        $result = new self();
        $result->payload = $payload;

        return $result;
    }

    public static function fail(string $error): self
    {
        $result = new self();
        $result->error = $error;

        return $result;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getPayload(): ?CronTaskPayloadDto
    {
        return $this->payload;
    }
}

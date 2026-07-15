<?php

declare(strict_types=1);

namespace Test\Module\Cron\Dto\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Core\Dto\AbstractDto;

/**
 * buildTaskPayload 构建结果 DTO。
 *
 * **职责**：封装 PayloadBuilder 的成功/失败结果，避免在构建阶段抛异常。
 *
 * **生产者**：{@see \Test\Module\Cron\Service\CronTaskPayloadBuilder::build} 校验通过后调用
 * {@see static::ok}，校验失败调用 {@see static::fail}。
 *
 * **消费者**：{@see \Test\Module\Cron\Service\CronTaskManagerService::createTask} 与
 * {@see \Test\Module\Cron\Service\CronTaskManagerService::updateTask} 通过 {@see hasError}
 * 判断后取 payload 或错误信息。
 *
 * **关键字段语义**（互斥）：
 * - 成功时：payload 有值，error 为 null
 * - 失败时：error 有值，payload 为 null
 */
class CronTaskPayloadBuildResultDto extends AbstractDto
{
    #[ApiProperty(description: '构建成功时的规范化 payload，失败时为 null')]
    protected ?CronTaskPayloadDto $payload = null;

    #[ApiProperty(description: '构建失败时的错误描述，成功时为 null')]
    protected ?string $error = null;

    /**
     * 构造成功结果。
     *
     * @param CronTaskPayloadDto $payload 经校验与规范化的可持久化字段集合
     */
    public static function ok(CronTaskPayloadDto $payload): self
    {
        $result = new self();
        $result->payload = $payload;

        return $result;
    }

    /**
     * 构造失败结果。
     *
     * @param string $error 面向调用方的错误描述（如「name/expression/command为必填」）
     */
    public static function fail(string $error): self
    {
        $result = new self();
        $result->error = $error;

        return $result;
    }

    /**
     * 判断是否构建失败（error 非 null）。
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /** 获取错误信息，成功时为 null */
    public function getError(): ?string
    {
        return $this->error;
    }

    /** 获取规范化 payload，失败时为 null */
    public function getPayload(): ?CronTaskPayloadDto
    {
        return $this->payload;
    }
}

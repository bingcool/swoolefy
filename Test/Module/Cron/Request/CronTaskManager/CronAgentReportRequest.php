<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request\CronTaskManager;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\StringToInt;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronAgentReportRequest extends BaseRequest
{
    #[ApiProperty(description: 'Cron 任务 ID')]
    #[ValidationRule(rule: 'required|int', message: 'cronId 不能为空')]
    #[StringToInt]
    protected int $cronId = 0;

    #[ApiProperty(description: '运行日志消息')]
    #[ValidationRule(rule: 'required|string', message: 'message 不能为空')]
    protected string $message = '';

    /**
     * @var mixed
     */
    #[ApiProperty(description: '任务项快照（任意结构）')]
    protected $taskItem = null;

    #[ApiProperty(description: '执行批次 ID')]
    protected ?string $execBatchId = null;

    #[ApiProperty(description: '进程 PID')]
    protected ?int $pid = null;

    #[ApiProperty(description: '结构化执行状态 0-6；缺省不从 message 猜测')]
    #[StringToInt]
    protected ?int $status = null;

    #[ApiProperty(description: '触发类型：1-scheduler 2-run_once')]
    #[StringToInt]
    protected ?int $triggerType = null;

    #[ApiProperty(description: '执行耗时毫秒')]
    #[StringToInt]
    protected ?int $durationMs = null;

    #[ApiProperty(description: 'Shell 退出码')]
    #[StringToInt]
    protected ?int $exitCode = null;

    #[ApiProperty(description: 'HTTP 状态码')]
    #[StringToInt]
    protected ?int $httpStatus = null;

    public function getCronId(): int
    {
        return $this->cronId;
    }

    public function setCronId(int $cronId): static
    {
        $this->cronId = $cronId;

        return $this;
    }

    public function getMessage(): string
    {
        return trim($this->message);
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getTaskItem()
    {
        return $this->taskItem;
    }

    /**
     * @param mixed $taskItem
     */
    public function setTaskItem($taskItem): static
    {
        $this->taskItem = $taskItem;

        return $this;
    }

    public function getExecBatchId(): string
    {
        return trim((string)($this->execBatchId ?? ''));
    }

    public function setExecBatchId(?string $execBatchId): static
    {
        $this->execBatchId = $execBatchId;

        return $this;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function setPid(?int $pid): static
    {
        $this->pid = $pid;

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

    public function getTriggerType(): ?int
    {
        return $this->triggerType;
    }

    public function setTriggerType(?int $triggerType): static
    {
        $this->triggerType = $triggerType;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function setExitCode(?int $exitCode): static
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function setHttpStatus(?int $httpStatus): static
    {
        $this->httpStatus = $httpStatus;

        return $this;
    }
}

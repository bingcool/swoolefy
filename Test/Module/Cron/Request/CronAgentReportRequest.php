<?php

declare(strict_types=1);

namespace Test\Module\Cron\Request;

use Swoolefy\Annotation\ApiProperty;
use Swoolefy\Annotation\Validation\ValidationRule;
use Swoolefy\Http\BaseRequest;

class CronAgentReportRequest extends BaseRequest
{
    #[ApiProperty(description: 'Cron 任务 ID')]
    #[ValidationRule(rule: 'required|int', message: 'cron_id不能为空')]
    protected int $cron_id = 0;

    #[ApiProperty(description: '运行日志消息')]
    #[ValidationRule(rule: 'required|string', message: 'message不能为空')]
    protected string $message = '';

    /**
     * @var mixed
     */
    #[ApiProperty(description: '任务项快照（任意结构）')]
    protected $task_item = null;

    #[ApiProperty(description: '执行批次 ID')]
    protected ?string $exec_batch_id = null;

    #[ApiProperty(description: '进程 PID')]
    protected ?int $pid = null;

    public function getCronId(): int
    {
        return $this->cron_id;
    }

    public function setCronId(int $cron_id): self
    {
        $this->cron_id = $cron_id;

        return $this;
    }

    public function getMessage(): string
    {
        return trim($this->message);
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getTaskItem()
    {
        return $this->task_item;
    }

    /**
     * @param mixed $task_item
     */
    public function setTaskItem($task_item): self
    {
        $this->task_item = $task_item;

        return $this;
    }

    public function getExecBatchId(): string
    {
        return trim((string)($this->exec_batch_id ?? ''));
    }

    public function setExecBatchId(?string $exec_batch_id): self
    {
        $this->exec_batch_id = $exec_batch_id;

        return $this;
    }

    public function getPid(): int
    {
        return (int)($this->pid ?? 0);
    }

    public function setPid(?int $pid): self
    {
        $this->pid = $pid;

        return $this;
    }
}

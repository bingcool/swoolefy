<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\Job;

use Swoolefy\Support\Job\Exception\JobException;

/**
 * jobType → Handler 映射，供单进程消费多种任务类型。
 */
final class JobHandlerRegistry
{
    /** @var array<string, JobHandlerInterface> */
    private array $handlers = [];

    public function register(JobHandlerInterface $handler): self
    {
        foreach ($handler->types() as $type) {
            if ($type === '') {
                throw new JobException('Handler type must be non-empty');
            }
            // 同 jobType 后注册覆盖先注册，便于测试替换
            $this->handlers[$type] = $handler;
        }

        return $this;
    }

    /**
     * @param list<JobHandlerInterface> $handlers
     */
    public function registerMany(array $handlers): self
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }

        return $this;
    }

    public function has(string $jobType): bool
    {
        return isset($this->handlers[$jobType]);
    }

    public function get(string $jobType): ?JobHandlerInterface
    {
        return $this->handlers[$jobType] ?? null;
    }

    public function require(string $jobType): JobHandlerInterface
    {
        $handler = $this->get($jobType);
        if ($handler === null) {
            throw new JobException("No JobHandler registered for jobType [{$jobType}]");
        }

        return $handler;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    public function count(): int
    {
        return count($this->handlers);
    }
}

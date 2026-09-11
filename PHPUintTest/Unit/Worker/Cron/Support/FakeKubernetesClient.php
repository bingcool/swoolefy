<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker\Cron\Support;

use Swoolefy\Worker\Cron\KubernetesApiException;
use Swoolefy\Worker\Cron\KubernetesClientInterface;

/**
 * 内存版 Kubernetes API：记录调用、按脚本返回 Job 状态。
 *
 * 只实现 {@see KubernetesClientInterface} 的行为契约，不模拟 API Server 的校验。
 */
final class FakeKubernetesClient implements KubernetesClientInterface
{
    /** @var array<string, mixed>|null 返回给 getDeployment 的对象；null = 404 */
    public ?array $deployment = null;

    /** @var list<array<string, mixed>> 每次 getJob 依次返回一个；用尽后重复最后一个 */
    public array $jobStates = [];

    /** @var array<string, array<string, mixed>> 已创建的 Job，key = name */
    public array $createdJobs = [];

    /** @var list<array<string, mixed>> listPods 的返回 */
    public array $pods = [];

    public string $podLog = '';

    /** @var list<string> 删除过的 Job 名 */
    public array $deleted = [];

    /** @var list<array<string, mixed>> createJob 收到的完整对象 */
    public array $createCalls = [];

    public int $getJobCalls = 0;

    /** 下次 createJob 抛 409，用于验证幂等分支 */
    public bool $createConflicts = false;

    public function getDeployment(string $namespace, string $name): array
    {
        if ($this->deployment === null) {
            throw new KubernetesApiException('not found', 404, 'NotFound');
        }

        return $this->deployment;
    }

    public function createJob(string $namespace, array $job): array
    {
        $this->createCalls[] = $job;
        if ($this->createConflicts) {
            throw new KubernetesApiException('already exists', 409, 'AlreadyExists');
        }
        $name = (string) ($job['metadata']['name'] ?? '');
        $job['metadata']['uid'] = 'uid-' . $name;
        $this->createdJobs[$name] = $job;

        return $job;
    }

    public function getJob(string $namespace, string $name): array
    {
        ++$this->getJobCalls;
        if ($this->jobStates === []) {
            if (!isset($this->createdJobs[$name])) {
                throw new KubernetesApiException('not found', 404, 'NotFound');
            }

            return $this->createdJobs[$name];
        }

        return count($this->jobStates) > 1 ? array_shift($this->jobStates) : $this->jobStates[0];
    }

    public function deleteJob(string $namespace, string $name): bool
    {
        $this->deleted[] = $name;
        unset($this->createdJobs[$name]);

        return true;
    }

    /** @var list<array<string, mixed>> listJobs 的返回 */
    public array $jobs = [];

    public function listJobs(string $namespace, string $labelSelector): array
    {
        if ($this->jobs !== []) {
            return $this->jobs;
        }

        return array_values($this->createdJobs);
    }

    public function listPods(string $namespace, string $labelSelector): array
    {
        return $this->pods;
    }

    public function getPodLogs(string $namespace, string $pod, string $container = '', int $tailLines = 50): string
    {
        return $this->podLog;
    }
}

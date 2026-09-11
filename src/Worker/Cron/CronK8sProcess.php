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

namespace Swoolefy\Worker\Cron;

use Swoolefy\Support\Kubernetes\Client;
use Swoolefy\Support\Kubernetes\ClientInterface;
use Swoolefy\Support\Kubernetes\ExecutorOptions;
use Swoolefy\Support\Kubernetes\JobTemplateBuilder;

/**
 * Kubernetes Cron Worker（exec_type=3）。
 *
 * 与 {@see CronForkProcess} / {@see CronUrlProcess} 同级：调度全部走父类 {@see CronManager}，
 * 本类只负责装配 {@see KubernetesExecutor}。
 *
 * ## 部署约束（方案 §3、§11.2）
 *
 * - **`worker_num = 1`**。多 Worker 会在同一个 `CRON_NODE_ID` 上跑出两个 Scheduler，
 *   虽然 `cron_scheduled_task_record` 的 Slot 唯一键能兜住，但会白白产生
 *   DUPLICATE 噪音，没有任何收益。
 * - K8s 任务往往是分钟级，Job 期间协程一直被占用，因此 `life_time` 与
 *   `limit_run_coroutine_num` 必须单独配置，**不要直接抄 fork Worker 的值**：
 *   `life_time` 到点 reboot 会 `runtimeCoroutineWait()` 等所有在跑的协程，
 *   一个长 Job 会把 reboot 拖住同样长的时间。
 * - 该 Worker 所在节点必须具备集群凭证：集群内跑用 ServiceAccount，
 *   集群外用 `K8S_API_SERVER` + `K8S_TOKEN`。
 *
 * 子类（应用层）通常重写 {@see createKubernetesHook} 注入 `cron_task_log` 相关行为，
 * 这样取消请求才能真正删掉 Job，而不是只把 Execution 标成 CANCELLED（§11.1）。
 *
 * @see KubernetesExecutor
 */
class CronK8sProcess extends CronProcess
{
    /**
     * onInit
     * @return void
     */
    public function onInit()
    {
        parent::onInit();
    }

    /**
     * 装配 Kubernetes 执行器。
     *
     * Client / Options 都从环境变量读，Hook 由子类提供。构造失败（例如没有任何
     * 集群凭证）不能让 Worker 起不来，否则会陷入 reboot 循环；此时返回一个
     * 只会失败的执行器，把错误写进每条 Execution 的 message，运维更容易定位。
     */
    protected function createCronExecutor(): CronExecutorInterface
    {
        try {
            $client = $this->createKubernetesClient();
        } catch (\Throwable $e) {
            return new UnavailableExecutor('KUBERNETES_CLIENT_UNAVAILABLE: ' . $e->getMessage());
        }

        return new KubernetesExecutor(
            client: $client,
            builder: new JobTemplateBuilder(),
            hook: $this->createKubernetesHook(),
            options: ExecutorOptions::fromEnv(),
        );
    }

    /**
     * 集群客户端。子类可重写以支持多集群 kubeconfig（P1）。
     */
    protected function createKubernetesClient(): ClientInterface
    {
        return Client::fromEnv();
    }

    /**
     * 落库 / 取消钩子。框架层没有 `cron_task_log`，默认是空实现。
     *
     * **生产必须重写**：否则 Admin 的取消请求只会把 Execution 标成 CANCELLED，
     * Kubernetes Job 仍在跑（方案 §11.1 的孤儿 Job）。
     */
    protected function createKubernetesHook(): KubernetesExecutionHookInterface
    {
        return new NullKubernetesExecutionHook();
    }

    /**
     * 启动生产引擎。Worker 级异常才 reboot；单个 Job 的异常已在 Executor 内隔离。
     */
    public function run()
    {
        try {
            parent::run();
            $this->runCronTask();
        } catch (\Throwable $throwable) {
            $context = [
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'message' => $throwable->getMessage(),
                'code' => $throwable->getCode(),
                'reboot_count' => $this->getRebootCount(),
                'trace' => $throwable->getTraceAsString(),
            ];
            parent::onHandleException($throwable, $context);
            sleep(2);
            $this->reboot();
        }
    }
}

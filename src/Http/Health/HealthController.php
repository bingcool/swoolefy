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

namespace Swoolefy\Http\Health;

use Swoole\Http\Status;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Http\RequestInput;

/**
 * K8s HTTP 探针 Controller（框架内置，应用路由直接挂载）。
 *
 * ## 路径（Config/health.php）
 * - GET /health、/healthz、/livez → {@see live()}
 * - GET /ready、/readyz         → {@see ready()}
 *
 * ## 为何直接 returnJson + setEnd
 * 框架对 Controller 返回值会再走 {@see \Swoolefy\Http\ActionResultNormalizer}。
 * 探针需自行设定 **HTTP 状态码**（200/503），故在方法内写响应并 `setEnd`，
 * 使 {@see \Swoolefy\Http\HttpRoute::emitActionResult} 因 `isEnd()` 跳过二次包装。
 *
 * ## kubelet
 * ```yaml
 * livenessProbe:
 *   httpGet: { path: /health, port: 9501 }
 * readinessProbe:
 *   httpGet: { path: /ready, port: 9501 }
 * ```
 *
 * 探针路由**禁止**挂鉴权 / 限流（见 {@see HealthRoutes::register()}）。
 */
final class HealthController extends BController
{
    /**
     * Liveness：进程存活。
     *
     * 默认仅 Process 检查；失败少见。失败时 HTTP 503，msg=`not ok`。
     * Route: GET /health | /healthz | /livez
     */
    public function live(RequestInput $requestInput): void
    {
        unset($requestInput);
        $this->respond(HealthProbe::fromConfig()->liveness());
    }

    /**
     * Readiness：依赖就绪后才接流量。
     *
     * 任一 readiness_checks 失败 → HTTP 503，kubelet 摘除 Endpoints，不杀容器。
     * Route: GET /ready | /readyz
     */
    public function ready(RequestInput $requestInput): void
    {
        unset($requestInput);
        $this->respond(HealthProbe::fromConfig()->readiness());
    }

    /**
     * 统一写出探针响应。
     *
     * 技术点：
     * 1. `swooleResponse->status()` 决定 kubelet 成败（比业务 code 字段更重要）
     * 2. `returnJson` 走框架信封 `{code,msg,data}`，data 为 {@see ProbeReport::toArray()}
     * 3. returnJson 内部会 `Application::setEnd()` + `response->end()`，避免双写
     */
    private function respond(ProbeReport $report): void
    {
        $config = HealthConfig::load();
        if (!$config->enabled()) {
            // 路由层一般已因 enabled=false 不注册；此处兜底防止误挂载
            $this->swooleResponse->status(Status::SERVICE_UNAVAILABLE);
            $this->returnJson(['status' => 'disabled'], Status::SERVICE_UNAVAILABLE, 'health probe disabled');

            return;
        }

        $httpStatus = $report->ok ? Status::OK : Status::SERVICE_UNAVAILABLE;
        $this->swooleResponse->status($httpStatus);

        $businessCode = $report->ok ? 0 : Status::SERVICE_UNAVAILABLE;
        // 文案区分探针类型，便于日志检索；kubelet 不解析 body
        $msg = $report->ok
            ? 'ok'
            : ($report->probe === 'readiness' ? 'not ready' : 'not ok');
        $this->returnJson(
            $report->toArray($config->includeDetails()),
            $businessCode,
            $msg,
        );
    }
}

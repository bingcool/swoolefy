<?php

/**
 * K8s 探针路由（无鉴权 / 无限流）。
 *
 * 在应用 Router 入口 require 本文件，或调用：
 *   \Swoolefy\Http\Health\HealthRoutes::register();
 *
 * 路径见 Config/health.php。
 */

\Swoolefy\Http\Health\HealthRoutes::register();

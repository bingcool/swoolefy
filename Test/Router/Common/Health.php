<?php

/**
 * K8s 探针：GET /health|/healthz|/livez 、 GET /ready|/readyz
 *
 * @see \Swoolefy\Http\Health\HealthController
 * @see Test/Config/health.php
 */

declare(strict_types=1);

\Swoolefy\Http\Health\HealthRoutes::register();

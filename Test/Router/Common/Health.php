<?php

/**
 * K8s 探针：GET /health|/healthz|/livez 、 GET /ready|/readyz
 *
 * @see \Swoolefy\Http\Health\HealthController
 * @see Test/Config/health.php
 */

\Swoolefy\Http\Health\HealthRoutes::register();

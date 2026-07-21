<?php

namespace Test\Router;

/**
 * Test 公共 API 路由索引（已按模块拆分，本文件不再注册具体路由）。
 *
 * 框架会递归扫描 APP_PATH/Router 下所有 .php 并 include。
 *
 * Common/（对应 Test\\Controller）
 *   - Index.php          IndexController
 *   - Cache.php          CacheController
 *   - Token.php          TokenController
 *   - AuthUser.php       AuthUserController（AuthenticateMiddleware）
 *   - Uuid.php           UuidController
 *   - Lock.php           LockController
 *   - RateLimit.php      RateLimitController
 *   - Validate.php       ValidateController
 *   - Websocket.php      WsController
 *   - Process.php        ProcessController
 *   - Queue.php          QueueController
 *   - Captcha.php        CaptchaController
 *   - Redis.php          RedisController
 *   - Transaction.php    TransactionController
 *   - EventStream.php    EventStreamController（SSE）
 *   - Chunked.php        ChunkedController
 *   - Download.php       DownloadController
 *   - Upload.php         UploadController
 *   - FileStorage.php    FileStorageController（本地 FileStorageSystem）
 *   - Object.php         ObjectController
 *   - Pg.php             PgController
 *   - Amqp.php           AmqpController
 *   - Exception.php      ExceptionController
 *   - UserOrder.php      Module\\Order\\UserOrderController（Db 综合测试）
 *   - OrderLog.php       Module\\Order\\LogOrderController
 *
 * Module/（对应 Test\\Module）
 *   - Rag.php / Research.php / Outdoor.php / OrderWorkflow.php
 *   - Workflow.php / Mcp.php / Agent.php
 *
 * 其他：Demo.php、CronManager.php、Product/
 */


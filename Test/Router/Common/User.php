<?php

namespace Test\Router;

/**
 * 原 User.php 路由已拆分：
 *   - Index.php      IndexController（/user/testAddUser 等）
 *   - Object.php     ObjectController（/user/order/*）
 *   - Pg.php          PgController（/user/user-order/save-pg-*）
 *   - UserOrder.php   UserOrderController（Db 综合测试）
 *   - OrderLog.php    LogOrderController
 *
 * 本文件保留为空壳，避免历史引用报错；不再注册路由。
 */

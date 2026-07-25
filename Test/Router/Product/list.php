<?php

namespace Test\Router\Product;

/**
 * 原 GET /product/list/mylist 与 IndexController::index 重复（同为 GET），已删除。
 * 产品列表演示请使用独立 Controller + 独立 action，勿再挂到 IndexController::index。
 */

<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Controller;

use PhpUintTest\Http\HttpIntegrationTestCase;

/**
 * Test\Controller curl 黄金路径基类。
 *
 * 物理目录在 Unit/Controller（按 Controller 归类）；suite 归 http（见 phpunit.xml.dist）。
 */
abstract class ControllerHttpTestCase extends HttpIntegrationTestCase
{
}

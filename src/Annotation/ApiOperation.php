<?php

declare(strict_types=1);

namespace Swoolefy\Annotation;

use Attribute;

/**
 * Controller action OpenAPI 文案注解。
 *
 * - 仅 `description`：用作 operation summary（不再重复写入 description）
 * - 同时提供 `summary` + `description`：分别写入 OpenAPI 对应字段
 * - 位置参数 `#[ApiOperation('...')]` 等价于 description
 *
 * @see \Swoolefy\Script\ApiDoc\ApiDocGenerator
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiOperation
{
    public function __construct(
        protected string $description = '',
        protected string $summary = '',
    ) {
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }
}

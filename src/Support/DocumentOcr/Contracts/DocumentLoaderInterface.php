<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Contracts;

use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;

/**
 * 将本地路径加载为 DocumentSource。
 */
interface DocumentLoaderInterface
{
    /**
     * @param string               $path     本地文件路径
     * @param array<string, mixed> $metadata 透传元数据
     */
    public function load(string $path, array $metadata = []): DocumentSource;
}

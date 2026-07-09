<?php

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Loaders;

use Swoolefy\Support\DocumentOcr\Contracts\DocumentLoaderInterface;
use Swoolefy\Support\DocumentOcr\Exceptions\DocumentParseException;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;

/**
 * 本地文件加载器：校验路径存在并解析扩展名。
 *
 * 仅处理 realpath 可解析的本地文件，拒绝目录与不可读路径。
 */
final class LocalFileLoader implements DocumentLoaderInterface
{
    /**
     * {@inheritdoc}
     *
     * @throws DocumentParseException 文件不存在或不可读
     */
    public function load(string $path, array $metadata = []): DocumentSource
    {
        $path = trim($path);
        if ($path === '') {
            throw new DocumentParseException('Document path must not be empty');
        }

        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new DocumentParseException('Document file not found or not readable: ' . $path);
        }

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = null;
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($real);
            $mime = is_string($detected) && $detected !== '' ? $detected : null;
        }

        return new DocumentSource(
            path: $real,
            extension: $extension,
            mimeType: $mime,
            metadata: $metadata + ['basename' => basename($real)],
        );
    }
}

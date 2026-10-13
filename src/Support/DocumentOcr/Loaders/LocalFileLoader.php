<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\DocumentOcr\Loaders;

use Swoolefy\Support\DocumentOcr\Contracts\DocumentLoaderInterface;
use Swoolefy\Support\DocumentOcr\Exceptions\DocumentException;
use Swoolefy\Support\DocumentOcr\Schema\DocumentSource;

/**
 * 本地文件加载器：校验路径存在并解析扩展名。
 *
 * 仅处理 realpath 可解析的本地文件，拒绝目录与不可读路径。
 * 文件 realpath 必须落在允许的根目录内（.. 与符号链接都按解析后的真实路径判断）；
 * 未传 $allowedRoots 时只允许系统临时目录（上传临时文件所在位置）。
 */
final class LocalFileLoader implements DocumentLoaderInterface
{
    /** @var list<string> realpath 之后的根目录，以 / 结尾 */
    private array $allowedRoots;

    /**
     * @param list<string> $allowedRoots 允许读取的根目录；为空时取 sys_get_temp_dir() 与 /tmp
     */
    public function __construct(array $allowedRoots = [])
    {
        if ($allowedRoots === []) {
            $allowedRoots = [sys_get_temp_dir(), '/tmp'];
        }

        $roots = [];
        foreach ($allowedRoots as $root) {
            $real = is_string($root) && trim($root) !== '' ? realpath(trim($root)) : false;
            if ($real !== false && is_dir($real)) {
                $real = rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if (!in_array($real, $roots, true)) {
                    $roots[] = $real;
                }
            }
        }
        $this->allowedRoots = $roots;
    }

    /**
     * {@inheritdoc}
     *
     * @throws DocumentException 文件不存在、不可读或不在允许目录内
     */
    public function load(string $path, array $metadata = []): DocumentSource
    {
        $path = trim($path);
        if ($path === '') {
            throw new DocumentException('Document path must not be empty');
        }
        if (str_contains($path, "\0")) {
            throw new DocumentException('Document path contains NUL byte');
        }

        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new DocumentException('Document file not found or not readable: ' . $path);
        }
        if (!$this->isAllowed($real)) {
            throw new DocumentException('Document path is outside allowed directories: ' . $path);
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

    /** @return list<string> */
    public function allowedRoots(): array
    {
        return $this->allowedRoots;
    }

    private function isAllowed(string $realFile): bool
    {
        foreach ($this->allowedRoots as $root) {
            if (str_starts_with($realFile, $root)) {
                return true;
            }
        }

        return false;
    }
}

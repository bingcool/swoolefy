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

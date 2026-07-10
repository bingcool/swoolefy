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

namespace Swoolefy\Support\Rag\Ingestion;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Document;

/**
 * 文件/目录文档加载器 —— 封装 Neuron FileDataLoader。
 *
 * 支持从目录递归读取 txt/md/html 等（需 Neuron Reader 配置）。
 */
final class FileDocumentLoader
{
    /**
     * 从目录或单文件路径加载 Document 列表。
     *
     * @return list<Document>
     */
    public static function fromPath(string $path): array
    {
        if (!is_file($path) && !is_dir($path)) {
            return [];
        }

        return (new FileDataLoader($path))->getDocuments();
    }
}

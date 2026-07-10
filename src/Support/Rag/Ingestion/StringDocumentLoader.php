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

use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Document;

/**
 * 字符串 → Document[] 加载器 —— 封装 Neuron StringDataLoader。
 *
 * 技术要点：
 * - fromTexts：一条字符串对应一个 Document（适合 FAQ / 短段落）
 * - fromString：长文本走 Neuron splitter 自动分块（适合大段 Markdown）
 */
final class StringDocumentLoader
{
    /**
     * 多条独立文本 → 多个 Document（不分块）。
     *
     * @param list<string> $texts
     *
     * @return list<Document>
     */
    public static function fromTexts(array $texts): array
    {
        $documents = [];
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '') {
                continue;
            }
            $documents[] = new Document($text);
        }

        return $documents;
    }

    /**
     * 单条长文本 → 分块后的 Document 列表。
     *
     * 使用 Neuron StringDataLoader 内置 splitter，避免单条 content 超出 embed 上限。
     *
     * @return list<Document>
     */
    public static function fromString(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        return (new StringDataLoader($text))->getDocuments();
    }
}

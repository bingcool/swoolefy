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

namespace Swoolefy\Support\DocumentOcr\Chunking;

use NeuronAI\RAG\Document;
use Swoolefy\Support\DocumentOcr\Schema\ParseResult;

/**
 * 将 ParseResult 转为 Neuron Document[]，供 IngestionPipeline 入库。
 *
 * Phase 1 采用简单按空行分段；超长段再按字符硬切，避免单块过大。
 * 不做复杂 Markdown AST 切分，保持实现小而可测。
 */
final class ChunkingAdapter
{
    /**
     * @param int $maxChars 单块最大字符数（按 mb 长度估算）
     *
     * @return list<Document>
     */
    public function __construct(
        private readonly int $maxChars = 2000,
    ) {
    }

    /**
     * @param string               $sourceName 写入 Document sourceName 的标识
     * @param array<string, mixed> $extraMeta  额外 metadata
     *
     * @return list<Document>
     */
    public function splitParseResult(ParseResult $result, string $sourceName = '', array $extraMeta = []): array
    {
        $markdown = trim($result->markdown);
        if ($markdown === '') {
            return [];
        }

        $baseMeta = array_merge([
            'parserName' => $result->parserName,
            'selectionReason' => $result->selectionReason,
            'sourceHash' => $result->sourceHash,
        ], $result->metadata, $extraMeta);

        $chunks = [];
        foreach ($this->splitText($markdown) as $index => $chunk) {
            $doc = new Document($chunk);
            if ($sourceName !== '') {
                $doc->sourceName = $sourceName;
            }
            $doc->sourceType = 'document_ocr';
            $doc->metadata = $baseMeta + ['chunkIndex' => $index];
            $chunks[] = $doc;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitText(string $text): array
    {
        // 先按空行分段
        $parts = preg_split("/\n\s*\n/", $text) ?: [$text];
        $chunks = [];
        $buffer = '';

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $candidate = $buffer === '' ? $part : $buffer . "\n\n" . $part;
            if (mb_strlen($candidate) <= $this->maxChars) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks = [...$chunks, ...$this->hardSplit($buffer)];
                $buffer = '';
            }

            if (mb_strlen($part) <= $this->maxChars) {
                $buffer = $part;
            } else {
                $chunks = [...$chunks, ...$this->hardSplit($part)];
            }
        }

        if ($buffer !== '') {
            $chunks = [...$chunks, ...$this->hardSplit($buffer)];
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function hardSplit(string $text): array
    {
        if (mb_strlen($text) <= $this->maxChars) {
            return [$text];
        }

        $chunks = [];
        $length = mb_strlen($text);
        for ($offset = 0; $offset < $length; $offset += $this->maxChars) {
            $chunks[] = mb_substr($text, $offset, $this->maxChars);
        }

        return $chunks;
    }
}

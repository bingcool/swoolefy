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

namespace Swoolefy\Support\Neuron\Embedding;

use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use Swoolefy\Support\Neuron\Http\NeuronHttpFactory;

/**
 * 带 Swoole 协程 HTTP 的 OpenAI-like Embedding 提供者。
 *
 * 在父类构造后替换 httpClient，注入 {@see NeuronHttpFactory}。
 */
final class SwooleOpenAILikeEmbeddings extends OpenAILikeEmbeddings
{
    public function __construct(
        string $baseUri,
        string $key,
        string $model,
        ?int $dimensions = 1536,
    ) {
        parent::__construct($baseUri, $key, $model, $dimensions);

        $this->httpClient = NeuronHttpFactory::create()
            ->withBaseUri($baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ]);
    }
}

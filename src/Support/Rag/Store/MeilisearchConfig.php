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

namespace Swoolefy\Support\Rag\Store;

use Swoolefy\Support\Neuron\NeuronAiConfig;

/**
 * Meilisearch 向量库连接配置 —— 供 {@see \Swoolefy\Support\Rag\Factory\VectorStoreFactory} 生产环境使用。
 *
 * 技术要点：
 * - 每个 knowledgeBase 对应 Meilisearch 独立 index（indexUid）
 * - embedder / dimension 需与 Meilisearch 侧向量配置一致，否则检索维度不匹配
 * - apiKey 为 null 时不发送 Authorization 头（本地开发常见）
 *
 * @see docs/SwoolefyAI.md §4.10.2
 */
final class MeilisearchConfig
{
    /**
     * @param string      $host      Meilisearch HTTP 地址，如 http://localhost:7700
     * @param string|null $apiKey    Master Key；null 表示无鉴权
     * @param string      $embedder  Meilisearch embedder 名称（与 index 配置一致）
     * @param int         $dimension 向量维度（写入 _vectors 时使用）
     */
    public function __construct(
        public readonly string $host = 'http://localhost:7700',
        public readonly ?string $apiKey = null,
        public readonly string $embedder = 'default',
        public readonly int $dimension = 1024,
    ) {
    }

    /**
     * 从 neuron_ai.php + 环境变量构建配置。
     *
     * @param string|null $alias 向量库别名；null 使用 default_vector_store
     */
    public static function fromNeuronAiConfig(?NeuronAiConfig $config = null, ?string $alias = null): self
    {
        $config ??= NeuronAiConfig::load();

        return new self(
            host: $config->meilisearchHost($alias),
            apiKey: $config->meilisearchKey($alias),
            embedder: $config->meilisearchEmbedder($alias),
            dimension: $config->meilisearchDimension($alias),
        );
    }

    /**
     * 从环境变量构建配置（兼容旧用法）。
     *
     * 读取：MEILISEARCH_HOST、MEILISEARCH_KEY、MEILISEARCH_EMBEDDER、MEILISEARCH_DIMENSION
     */
    public static function fromEnv(): self
    {
        return self::fromNeuronAiConfig();
    }
}

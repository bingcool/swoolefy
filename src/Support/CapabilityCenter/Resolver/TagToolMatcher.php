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

namespace Swoolefy\Support\CapabilityCenter\Resolver;

use Swoolefy\Support\CapabilityCenter\CapabilityDescriptor;

use function array_values;
use function count;
use function in_array;
use function mb_strtolower;
use function preg_split;
use function str_contains;
use function trim;

/**
 * 轻量 tag / query 匹配器（Tag 阶段）。
 *
 * Phase 3 不引入 embedding，仅用规则打分：
 * - pinned 精确 ID 由 CompositeToolResolver 统一处理，此处不参与；
 * - profileTags 命中 descriptor.tags 时 +5.0；
 * - capabilityProfile 命中 tags 时 +3.0；
 * - query 分词命中 name / description / tags 时每个 token +1.0。
 */
final class TagToolMatcher
{
    /**
     * 对通过 Policy 的 descriptor 打分并排序。
     *
     * query 非空且 score <= 0 的 descriptor 会被跳过（无匹配则不入选）。
     * query 为空时保留 score=0 的 descriptor，供无意图场景兜底。
     *
     * @param list<CapabilityDescriptor> $descriptors Policy 过滤后的候选
     * @param ToolResolveContext         $context     请求上下文
     *
     * @return list<ResolvedCapability> 按 score 降序排列
     */
    public function match(array $descriptors, ToolResolveContext $context): array
    {
        $results = [];
        foreach ($descriptors as $descriptor) {
            $score = $this->score($descriptor, $context);

            // 有 query 时要求至少有一定匹配度；无 query 时允许 score=0 通过
            if ($score <= 0.0 && $context->query !== '') {
                continue;
            }

            $results[] = new ResolvedCapability($descriptor, $score, 'tag');
        }

        // 分数降序；同分按 id 字典序保证稳定性
        usort(
            $results,
            static fn (ResolvedCapability $a, ResolvedCapability $b): int => $b->score <=> $a->score
                ?: strcmp($a->descriptor->id, $b->descriptor->id),
        );

        return array_values($results);
    }

    /**
     * 计算单个 descriptor 与当前 context 的匹配分数。
     *
     * 打分权重：profileTags(+5) > capabilityProfile(+3) > query token(+1 each)
     */
    private function score(CapabilityDescriptor $descriptor, ToolResolveContext $context): float
    {
        $score = 0.0;
        $tags = array_map(static fn (string $tag): string => mb_strtolower($tag), $descriptor->tags);

        // profileTags 与 descriptor.tags 交集，每个命中 +5
        foreach ($context->profileTags as $tag) {
            if (in_array(mb_strtolower($tag), $tags, true)) {
                $score += 5.0;
            }
        }

        // capabilityProfile 本身作为 tag 命中时 +3
        if ($context->capabilityProfile !== null && in_array(mb_strtolower($context->capabilityProfile), $tags, true)) {
            $score += 3.0;
        }

        // query 分词在 name/description/tags 合并文本中命中，每个 token +1
        $content = mb_strtolower($descriptor->toIndexContent());
        foreach ($this->tokens($context->query) as $token) {
            if (str_contains($content, $token)) {
                $score += 1.0;
            }
        }

        return $score;
    }

    /**
     * 将 query 分词为最多 20 个 token。
     *
     * 支持 Unicode 字母数字；过滤空串与重复项。
     *
     * @return list<string>
     */
    private function tokens(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        // 限制 token 数量，避免超长 query 导致性能问题
        return count($tokens) > 20 ? array_slice($tokens, 0, 20) : $tokens;
    }
}

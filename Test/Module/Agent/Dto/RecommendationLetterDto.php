<?php

declare(strict_types=1);

namespace Test\Module\Agent\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;
use Swoolefy\Core\Dto\ArrayDto;

/**
 * AI 润色后的推荐信结构化输出。
 */
final class RecommendationLetterDto extends ArrayDto
{
    #[SchemaProperty(description: '推荐信标题', required: true)]
    public string $title;

    #[SchemaProperty(description: '完整推荐信正文，正式、流畅、有说服力', required: true)]
    public string $article;
}

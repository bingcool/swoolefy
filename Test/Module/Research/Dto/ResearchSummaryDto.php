<?php

declare(strict_types=1);

namespace Test\Module\Research\Dto;

use Swoolefy\Core\Dto\ArrayDto;

/** MCP 研究摘要 DTO。 */
final class ResearchSummaryDto extends ArrayDto
{
    public bool $urgent = false;

    public string $summary = '';

    public string $source = '';
}

<?php

declare(strict_types=1);

namespace Test\Module\Research\Dto;

/** MCP 研究摘要 DTO。 */
final class ResearchSummaryDto
{
    public bool $urgent = false;

    public string $summary = '';

    public string $source = '';
}

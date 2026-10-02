<?php

declare(strict_types=1);

namespace App\Services\Posts;

final readonly class SplitResult
{
    /**
     * @param  list<string>  $sections
     * @param  list<string>  $issues  advisory validation issue kinds
     * @param  list<int>  $sectionSources  Authored-segment index per section.
     */
    public function __construct(
        public array $sections,
        public array $issues,
        public array $sectionSources = [],
    ) {}
}

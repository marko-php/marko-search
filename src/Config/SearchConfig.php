<?php

declare(strict_types=1);

namespace Marko\Search\Config;

use Marko\Config\ConfigRepositoryInterface;
use Marko\Config\Exceptions\ConfigNotFoundException;

readonly class SearchConfig
{
    public function __construct(
        private ConfigRepositoryInterface $config,
    ) {}

    /**
     * @throws ConfigNotFoundException
     */
    public function maxPerPage(): int
    {
        return $this->config->getInt('search.max_per_page');
    }

    /**
     * Clamp a requested per-page value to between 1 and the configured max_per_page.
     *
     * @throws ConfigNotFoundException
     */
    public function clampPerPage(
        int $requested,
    ): int {
        return max(1, min($requested, $this->maxPerPage()));
    }
}

<?php

declare(strict_types=1);

use Marko\Config\Exceptions\ConfigNotFoundException;
use Marko\Search\Config\SearchConfig;
use Marko\Testing\Fake\FakeConfigRepository;

it('reads max_per_page from the search config', function (): void {
    $config = new SearchConfig(new FakeConfigRepository(['search.max_per_page' => 25]));

    expect($config->maxPerPage())->toBe(25);
});

it('clamps a requested per-page value to between 1 and max_per_page', function (): void {
    $config = new SearchConfig(new FakeConfigRepository(['search.max_per_page' => 25]));

    expect($config->clampPerPage(10))->toBe(10)
        ->and($config->clampPerPage(26))->toBe(25)
        ->and($config->clampPerPage(100000000))->toBe(25)
        ->and($config->clampPerPage(0))->toBe(1)
        ->and($config->clampPerPage(-5))->toBe(1);
});

it('throws when max_per_page is not configured', function (): void {
    $config = new SearchConfig(new FakeConfigRepository([]));

    expect(fn () => $config->maxPerPage())->toThrow(ConfigNotFoundException::class);
});

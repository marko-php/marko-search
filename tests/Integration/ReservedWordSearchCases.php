<?php

declare(strict_types=1);

namespace Marko\Search\Tests\Integration;

use Marko\Search\Value\FilterOperator;
use Marko\Search\Value\SearchCriteria;
use Marko\Search\Value\SearchFilter;

/**
 * Cases shared by the MySQL/MariaDB and PostgreSQL search suites. Each suite's beforeEach sets $this->connection
 * and $this->driver over a seeded search_reserved_settings table. Before #338 every one of these failed with a
 * syntax error, because DatabaseSearchDriver interpolated key, group and order bare.
 */
function reservedWordSearchCases(): void
{
    it('searches reserved-word searchable columns', function (): void {
        $result = $this->driver->search('alpha', SearchCriteria::create('alpha'));
        $byGroup = $this->driver->search('general', SearchCriteria::create('general'));

        expect($result->total)->toBe(1)
            ->and($result->items[0]['key'])->toBe('alpha')
            ->and($byGroup->total)->toBe(2);
    })->issue(338);

    it('filters on a reserved-word column', function (): void {
        $result = $this->driver->search(
            'Setting',
            SearchCriteria::create('Setting')
                ->withFilter(new SearchFilter('group', FilterOperator::Equals, 'general'))
                ->withFilter(new SearchFilter('order', FilterOperator::In, [1, 2, 3]))
                ->withFilter(new SearchFilter('key', FilterOperator::NotEquals, 'beta')),
        );

        expect($result->total)->toBe(1)
            ->and(array_column($result->items, 'key'))->toBe(['gamma']);
    })->issue(338);

    it('sorts by a reserved-word column', function (): void {
        $ascending = $this->driver->search('Setting', SearchCriteria::create('Setting')->withSort('order', 'asc'));
        $descending = $this->driver->search('Setting', SearchCriteria::create('Setting')->withSort('key', 'desc'));

        expect(array_column($ascending->items, 'key'))->toBe(['beta', 'gamma', 'alpha'])
            ->and(array_column($descending->items, 'key'))->toBe(['gamma', 'beta', 'alpha']);
    })->issue(338);

    it('searches, filters and sorts a mixed-case column', function (): void {
        $result = $this->driver->search(
            'Setting',
            SearchCriteria::create('Setting')
                ->withFilter(new SearchFilter('displayName', FilterOperator::Like, '%a Setting'))
                ->withSort('displayName', 'desc'),
        );

        expect(array_column($result->items, 'displayName'))->toBe(['Gamma Setting', 'Beta Setting', 'Alpha Setting'])
            ->and($result->total)->toBe(3);
    })->issue(338);
}

<?php

declare(strict_types=1);

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use Marko\Search\Contracts\SearchableInterface;
use Marko\Search\Driver\DatabaseSearchDriver;
use Marko\Search\Exceptions\SearchException;
use Marko\Search\Value\FilterOperator;
use Marko\Search\Value\SearchCriteria;
use Marko\Search\Value\SearchFilter;

// Test searchable entity
readonly class PostSearchable implements SearchableInterface
{
    public function getSearchableFields(): array
    {
        return ['title' => 2.0, 'body' => 1.0];
    }
}

// Fake connection that captures executed SQL
class FakeSearchConnection implements ConnectionInterface
{
    /** @var array<array{sql: string, bindings: array<mixed>}> */
    public array $queries = [];

    /** @var array<array<string, mixed>> */
    public array $rows = [];

    public function connect(): void {}

    public function disconnect(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function query(
        string $sql,
        array $bindings = [],
    ): array {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

        return $this->rows;
    }

    public function execute(
        string $sql,
        array $bindings = [],
    ): int {
        return 0;
    }

    public function prepare(
        string $sql,
    ): StatementInterface {
        throw new RuntimeException('Not implemented');
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function driverName(): string
    {
        return 'sqlite';
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function quoteIdentifier(
        string $identifier,
    ): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}

it('searches entities using SQL LIKE for partial text matching', function (): void {
    $connection = new FakeSearchConnection();
    $connection->rows = [
        ['id' => 1, 'title' => 'Hello World', 'body' => 'Some content'],
    ];

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('hello');

    $result = $driver->search('hello', $criteria);

    expect($result->items)
        ->toHaveCount(1)
        ->and($result->items[0]['title'])->toBe('Hello World');

    // Verify LIKE was used in the query
    $dataQuery = $connection->queries[1] ?? $connection->queries[0];
    expect($dataQuery['sql'])->toContain('LIKE');
});

it('searches across multiple fields defined by SearchableInterface', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('test');

    $driver->search('test', $criteria);

    // The data query should contain LIKE conditions for both title and body fields
    $dataQuery = $connection->queries[1] ?? $connection->queries[0];
    expect($dataQuery['sql'])
        ->toContain('"title" LIKE ?')
        ->toContain('"body" LIKE ?')
        ->toContain(' OR ')
        ->and($dataQuery['bindings'])->toBe(['%test%', '%test%']);
});

it('applies equality filters from SearchCriteria to query', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('php')
        ->withFilter(new SearchFilter('status', FilterOperator::Equals, 'published'))
        ->withFilter(new SearchFilter('author_id', FilterOperator::Equals, 5));

    $driver->search('php', $criteria);

    $dataQuery = $connection->queries[1] ?? $connection->queries[0];

    expect($dataQuery['sql'])
        ->toContain('"status" = ?')
        ->toContain('"author_id" = ?')
        ->and($dataQuery['bindings'])->toContain('published')
        ->and($dataQuery['bindings'])->toContain(5);
});

it('applies sorting from SearchCriteria to query results', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('php')
        ->withSort('created_at', 'desc');

    $driver->search('php', $criteria);

    $dataQuery = $connection->queries[1] ?? $connection->queries[0];

    expect($dataQuery['sql'])
        ->toContain('ORDER BY "created_at" desc');
});

it('paginates results based on SearchCriteria page and per_page', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('php')
        ->withPage(3)
        ->withPerPage(10);

    $driver->search('php', $criteria);

    $dataQuery = $connection->queries[1] ?? $connection->queries[0];

    // Page 3 with 10 per page = LIMIT 10 OFFSET 20
    expect($dataQuery['sql'])
        ->toContain('LIMIT 10')
        ->toContain('OFFSET 20');
});

it('rejects SQL injection in sort column names', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('test')
        ->withSort('id; DROP TABLE users --', 'asc');

    expect(fn () => $driver->search('test', $criteria))
        ->toThrow(SearchException::class, 'Invalid sort column identifier');
});

it('rejects SQL injection in filter field names', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('test')
        ->withFilter(new SearchFilter('1=1; --', FilterOperator::Equals, 'value'));

    expect(fn () => $driver->search('test', $criteria))
        ->toThrow(SearchException::class, 'Invalid filter column identifier');
});

it('rejects SQL injection in sort direction', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('test')
        ->withSort('created_at', 'asc; DROP TABLE posts');

    expect(fn () => $driver->search('test', $criteria))
        ->toThrow(SearchException::class, 'Invalid sort direction');
});

it('rejects SQL injection in table names', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'posts; DROP TABLE users', new PostSearchable());
    $criteria = SearchCriteria::create('test');

    expect(fn () => $driver->search('test', $criteria))
        ->toThrow(SearchException::class, 'Invalid table identifier');
});

it('rejects SQL injection in searchable field names', function (): void {
    $connection = new FakeSearchConnection();

    $searchable = new readonly class () implements SearchableInterface
    {
        public function getSearchableFields(): array
        {
            return ['title OR 1=1 --' => 1.0];
        }
    };

    $driver = new DatabaseSearchDriver($connection, 'posts', $searchable);
    $criteria = SearchCriteria::create('test');

    expect(fn () => $driver->search('test', $criteria))
        ->toThrow(SearchException::class, 'Invalid column identifier');
});

it('allows valid identifiers with underscores', function (): void {
    $connection = new FakeSearchConnection();

    $driver = new DatabaseSearchDriver($connection, 'blog_posts', new PostSearchable());
    $criteria = SearchCriteria::create('test')
        ->withSort('created_at', 'desc')
        ->withFilter(new SearchFilter('author_id', FilterOperator::Equals, 5));

    $driver->search('test', $criteria);

    expect($connection->queries)->toHaveCount(2);
});

it('returns SearchResult with total count and matched items', function (): void {
    $dataRows = [
        ['id' => 1, 'title' => 'PHP Best Practices', 'body' => 'Content about php'],
        ['id' => 2, 'title' => 'Learn PHP', 'body' => 'PHP tutorial content'],
    ];

    $connection = new class ($dataRows) extends FakeSearchConnection
    {
        public function __construct(
            private readonly array $dataRows,
        ) {}

        public function query(
            string $sql,
            array $bindings = [],
        ): array {
            $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];

            if (str_contains($sql, 'COUNT(*)')) {
                return [['count' => 42]];
            }

            return $this->dataRows;
        }
    };

    $driver = new DatabaseSearchDriver($connection, 'posts', new PostSearchable());
    $criteria = SearchCriteria::create('php')
        ->withPage(2)
        ->withPerPage(5);

    $result = $driver->search('php', $criteria);

    expect($result->total)->toBe(42)
        ->and($result->items)->toHaveCount(2)
        ->and($result->query)->toBe('php')
        ->and($result->page)->toBe(2)
        ->and($result->perPage)->toBe(5)
        ->and($result->items[0]['title'])->toBe('PHP Best Practices')
        ->and($result->items[1]['title'])->toBe('Learn PHP');
});

// Quotes with backticks, so the SQL proves the driver asks the connection rather than choosing a delimiter itself
class BacktickSearchConnection extends FakeSearchConnection
{
    public function quoteIdentifier(
        string $identifier,
    ): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

readonly class ReservedWordSearchable implements SearchableInterface
{
    public function getSearchableFields(): array
    {
        return ['key' => 2.0, 'group' => 1.0];
    }
}

describe('identifier quoting', function (): void {
    it('quotes the table and searchable fields through the connection', function (): void {
        $connection = new BacktickSearchConnection();

        new DatabaseSearchDriver($connection, 'posts', new PostSearchable())
            ->search('php', SearchCriteria::create('php'));

        expect($connection->queries[0]['sql'])
            ->toBe('SELECT COUNT(*) as count FROM `posts` WHERE (`title` LIKE ? OR `body` LIKE ?)')
            ->and($connection->queries[1]['sql'])
            ->toBe('SELECT * FROM `posts` WHERE (`title` LIKE ? OR `body` LIKE ?) LIMIT 15 OFFSET 0');
    });

    it('quotes filter fields through the connection for every operator', function (): void {
        $connection = new BacktickSearchConnection();
        $criteria = SearchCriteria::create('php')
            ->withFilter(new SearchFilter('status', FilterOperator::Equals, 'published'))
            ->withFilter(new SearchFilter('status', FilterOperator::NotEquals, 'draft'))
            ->withFilter(new SearchFilter('views', FilterOperator::GreaterThan, 10))
            ->withFilter(new SearchFilter('views', FilterOperator::LessThan, 100))
            ->withFilter(new SearchFilter('slug', FilterOperator::Like, 'php-%'))
            ->withFilter(new SearchFilter('author_id', FilterOperator::In, [1, 2]));

        new DatabaseSearchDriver($connection, 'posts', new PostSearchable())->search('php', $criteria);

        expect($connection->queries[1]['sql'])->toContain(
            '(`title` LIKE ? OR `body` LIKE ?) AND `status` = ? AND `status` != ? AND `views` > ? AND `views` < ?'
            . ' AND `slug` LIKE ? AND `author_id` IN (?, ?)',
        )->and($connection->queries[1]['bindings'])
            ->toBe(['%php%', '%php%', 'published', 'draft', 10, 100, 'php-%', 1, 2]);
    });

    it('quotes the sort column through the connection and keeps the direction bare', function (): void {
        $connection = new BacktickSearchConnection();

        new DatabaseSearchDriver($connection, 'posts', new PostSearchable())
            ->search('php', SearchCriteria::create('php')->withSort('created_at', 'desc'));

        expect($connection->queries[1]['sql'])->toContain(' ORDER BY `created_at` desc LIMIT');
    });

    it('quotes reserved-word identifiers instead of interpolating them bare', function (): void {
        $connection = new BacktickSearchConnection();
        $criteria = SearchCriteria::create('x')
            ->withFilter(new SearchFilter('group', FilterOperator::Equals, 'general'))
            ->withSort('order', 'asc');

        new DatabaseSearchDriver($connection, 'order', new ReservedWordSearchable())->search('x', $criteria);

        expect($connection->queries[1]['sql'])->toBe(
            'SELECT * FROM `order` WHERE (`key` LIKE ? OR `group` LIKE ?) AND `group` = ?'
            . ' ORDER BY `order` asc LIMIT 15 OFFSET 0',
        );
    });
});

<?php

declare(strict_types=1);

namespace Marko\Search\Driver;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Search\Contracts\SearchableInterface;
use Marko\Search\Contracts\SearchInterface;
use Marko\Search\Exceptions\SearchException;
use Marko\Search\Value\FilterOperator;
use Marko\Search\Value\SearchCriteria;
use Marko\Search\Value\SearchFilter;
use Marko\Search\Value\SearchResult;

/**
 * Searches one table with LIKE across the searchable fields.
 *
 * The table, searchable, filter and sort names are first checked against a plain-identifier pattern (letters,
 * digits and underscores, so a dotted or injected name fails with a SearchException), then quoted through
 * ConnectionInterface::quoteIdentifier(), so reserved words (`key`, `group`, `order`) and mixed-case PostgreSQL
 * columns work. The sort direction is checked against an asc/desc allowlist; values are always bound.
 */
readonly class DatabaseSearchDriver implements SearchInterface
{
    private const string IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    private const array VALID_SORT_DIRECTIONS = ['asc', 'desc'];

    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName,
        private SearchableInterface $searchable,
    ) {}

    /**
     * @throws SearchException
     */
    public function search(
        string $query,
        SearchCriteria $criteria,
    ): SearchResult {
        $table = $this->quote($this->tableName, 'table');
        $fields = array_keys($this->searchable->getSearchableFields());

        // Build LIKE conditions for text search
        $likeClauses = [];
        $searchBindings = [];

        foreach ($fields as $field) {
            $likeClauses[] = $this->quote($field, 'column') . ' LIKE ?';
            $searchBindings[] = "%$query%";
        }

        $whereClause = '(' . implode(' OR ', $likeClauses) . ')';
        $bindings = $searchBindings;

        // Apply filters from SearchCriteria
        $filterClauses = [];

        foreach ($criteria->filters as $filter) {
            [$clause, $filterBindings] = $this->buildFilterClause($filter);
            $filterClauses[] = $clause;
            $bindings = array_merge($bindings, $filterBindings);
        }

        if ($filterClauses !== []) {
            $whereClause .= ' AND ' . implode(' AND ', $filterClauses);
        }

        // Count query
        $countSql = "SELECT COUNT(*) as count FROM $table WHERE $whereClause";
        $countResult = $this->connection->query($countSql, $bindings);
        $total = (int) ($countResult[0]['count'] ?? 0);

        // Data query
        $sql = "SELECT * FROM $table WHERE $whereClause";

        // Apply sorting
        if ($criteria->sortBy !== '') {
            $sortColumn = $this->quote($criteria->sortBy, 'sort column');
            $this->assertValidSortDirection($criteria->sortDirection);
            $sql .= " ORDER BY $sortColumn $criteria->sortDirection";
        }

        // Apply pagination
        $offset = ($criteria->page - 1) * $criteria->perPage;
        $sql .= " LIMIT $criteria->perPage OFFSET $offset";

        $rows = $this->connection->query($sql, $bindings);

        return new SearchResult(
            items: $rows,
            total: $total,
            query: $query,
            page: $criteria->page,
            perPage: $criteria->perPage,
        );
    }

    /**
     * Build a SQL clause and bindings for a search filter.
     *
     * @return array{string, array}
     *
     * @throws SearchException
     */
    private function buildFilterClause(
        SearchFilter $filter,
    ): array {
        $field = $this->quote($filter->field, 'filter column');

        return match ($filter->operator) {
            FilterOperator::Equals => ["$field = ?", [$filter->value]],
            FilterOperator::NotEquals => ["$field != ?", [$filter->value]],
            FilterOperator::GreaterThan => ["$field > ?", [$filter->value]],
            FilterOperator::LessThan => ["$field < ?", [$filter->value]],
            FilterOperator::Like => ["$field LIKE ?", [$filter->value]],
            FilterOperator::In => [
                "$field IN (" . implode(', ', array_fill(0, count((array) $filter->value), '?')) . ')',
                (array) $filter->value,
            ],
        };
    }

    /**
     * Validate an identifier, then quote it for the connection's SQL dialect.
     *
     * @throws SearchException
     */
    private function quote(
        string $identifier,
        string $type,
    ): string {
        $this->assertValidIdentifier($identifier, $type);

        return $this->connection->quoteIdentifier($identifier);
    }

    /**
     * Validate that an identifier contains only safe characters for SQL.
     *
     * @throws SearchException
     */
    private function assertValidIdentifier(
        string $identifier,
        string $type,
    ): void {
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw SearchException::invalidIdentifier($identifier, $type);
        }
    }

    /**
     * Validate that a sort direction is either 'asc' or 'desc'.
     *
     * @throws SearchException
     */
    private function assertValidSortDirection(
        string $direction,
    ): void {
        if (!in_array(strtolower($direction), self::VALID_SORT_DIRECTIONS, true)) {
            throw SearchException::invalidSortDirection($direction);
        }
    }
}

<?php

declare(strict_types=1);

namespace Marko\Search\Driver;

use Marko\Config\Exceptions\ConfigNotFoundException;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Search\Config\SearchConfig;
use Marko\Search\Contracts\FilterableInterface;
use Marko\Search\Contracts\SearchableInterface;
use Marko\Search\Contracts\SearchInterface;
use Marko\Search\Contracts\SelectableInterface;
use Marko\Search\Contracts\SortableInterface;
use Marko\Search\Exceptions\SearchException;
use Marko\Search\Value\FilterOperator;
use Marko\Search\Value\SearchCriteria;
use Marko\Search\Value\SearchFilter;
use Marko\Search\Value\SearchResult;

/**
 * Searches one table with LIKE across the searchable fields.
 *
 * Only declared columns are touched: filters must target a filterable field and the sort a sortable field, and
 * each row returns only the selectable fields (never SELECT *). A searchable that does not implement
 * FilterableInterface, SortableInterface or SelectableInterface falls back to its searchable fields for that list.
 *
 * Every name is then checked against a plain-identifier pattern (letters, digits and underscores, so a dotted or
 * injected name fails with a SearchException) and quoted through ConnectionInterface::quoteIdentifier(), so reserved
 * words (`key`, `group`, `order`) and mixed-case PostgreSQL columns work. The sort direction is checked against an
 * asc/desc allowlist; values are always bound. The search term is matched literally: its `%` and `_` are escaped.
 * The page must be 1 or greater, and the per-page count is clamped to search.max_per_page.
 */
readonly class DatabaseSearchDriver implements SearchInterface
{
    private const string IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    private const array VALID_SORT_DIRECTIONS = ['asc', 'desc'];

    /**
     * The LIKE escape character. `!` rather than `\`, because a backslash literal is spelled differently on MySQL
     * (`'\\'`) and PostgreSQL (`'\'`), while `ESCAPE '!'` means the same thing on every driver.
     */
    private const string LIKE_ESCAPE = '!';

    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName,
        private SearchableInterface $searchable,
        private SearchConfig $config,
    ) {}

    /**
     * @throws SearchException|ConfigNotFoundException
     */
    public function search(
        string $query,
        SearchCriteria $criteria,
    ): SearchResult {
        if ($criteria->page < 1) {
            throw SearchException::invalidPage($criteria->page);
        }

        $perPage = $this->config->clampPerPage($criteria->perPage);
        $table = $this->quote($this->tableName, 'table');
        $searchableFields = array_keys($this->searchable->getSearchableFields());
        $columns = implode(', ', array_map(
            fn (string $field): string => $this->quote($field, 'column'),
            $this->selectableFields($searchableFields),
        ));

        // Build LIKE conditions for text search, matching the query literally
        $likeClauses = [];
        $searchBindings = [];
        $pattern = '%' . $this->escapeLike($query) . '%';

        foreach ($searchableFields as $field) {
            $likeClauses[] = $this->quote($field, 'column') . " LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";
            $searchBindings[] = $pattern;
        }

        $whereClause = '(' . implode(' OR ', $likeClauses) . ')';
        $bindings = $searchBindings;

        // Apply filters from SearchCriteria
        $filterClauses = [];
        $filterableFields = $this->searchable instanceof FilterableInterface
            ? $this->searchable->getFilterableFields()
            : $searchableFields;

        foreach ($criteria->filters as $filter) {
            if (!in_array($filter->field, $filterableFields, true)) {
                throw SearchException::fieldNotFilterable($filter->field, $filterableFields);
            }

            [$clause, $filterBindings] = $this->buildFilterClause($filter);
            $filterClauses[] = $clause;
            $bindings = array_merge($bindings, $filterBindings);
        }

        if ($filterClauses !== []) {
            $whereClause .= ' AND ' . implode(' AND ', $filterClauses);
        }

        // Validate sorting before any SQL runs
        $orderBy = '';

        if ($criteria->sortBy !== '') {
            $sortableFields = $this->searchable instanceof SortableInterface
                ? $this->searchable->getSortableFields()
                : $searchableFields;

            if (!in_array($criteria->sortBy, $sortableFields, true)) {
                throw SearchException::fieldNotSortable($criteria->sortBy, $sortableFields);
            }

            $sortColumn = $this->quote($criteria->sortBy, 'sort column');
            $this->assertValidSortDirection($criteria->sortDirection);
            $orderBy = " ORDER BY $sortColumn $criteria->sortDirection";
        }

        // Count query
        $countSql = "SELECT COUNT(*) as count FROM $table WHERE $whereClause";
        $countResult = $this->connection->query($countSql, $bindings);
        $total = (int) ($countResult[0]['count'] ?? 0);

        // Data query with pagination
        $offset = ($criteria->page - 1) * $perPage;
        $sql = "SELECT $columns FROM $table WHERE $whereClause$orderBy LIMIT $perPage OFFSET $offset";

        $rows = $this->connection->query($sql, $bindings);

        return new SearchResult(
            items: $rows,
            total: $total,
            query: $query,
            page: $criteria->page,
            perPage: $perPage,
        );
    }

    /**
     * The columns each result row returns: the declared selectable fields, or the searchable fields.
     *
     * @param array<string> $searchableFields
     * @return array<string>
     */
    private function selectableFields(
        array $searchableFields,
    ): array {
        return $this->searchable instanceof SelectableInterface
            ? $this->searchable->getSelectableFields()
            : $searchableFields;
    }

    /**
     * Escape the LIKE escape character and the `%` and `_` wildcards so the value matches literally.
     */
    private function escapeLike(
        string $value,
    ): string {
        $escape = self::LIKE_ESCAPE;

        return str_replace([$escape, '%', '_'], [$escape . $escape, $escape . '%', $escape . '_'], $value);
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
            FilterOperator::In => $this->buildInClause($field, (array) $filter->value),
        };
    }

    /**
     * Build an IN clause; an empty list matches nothing (`1 = 0`), since `IN ()` is a syntax error.
     *
     * @param array<mixed> $values
     * @return array{string, array}
     */
    private function buildInClause(
        string $field,
        array $values,
    ): array {
        if ($values === []) {
            return ['1 = 0', []];
        }

        $values = array_values($values);

        return ["$field IN (" . implode(', ', array_fill(0, count($values), '?')) . ')', $values];
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

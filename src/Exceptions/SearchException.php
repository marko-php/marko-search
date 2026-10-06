<?php

declare(strict_types=1);

namespace Marko\Search\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class SearchException extends MarkoException
{
    public static function queryFailed(
        string $query,
        string $reason,
    ): self {
        return new self(
            message: "Search query failed: '$query'",
            context: $reason,
            suggestion: 'Check that the search query is valid and the search engine is available',
        );
    }

    public static function invalidIdentifier(
        string $identifier,
        string $type,
    ): self {
        return new self(
            message: "Invalid $type identifier: '$identifier'",
            context: "The $type '$identifier' contains characters that are not allowed in SQL identifiers",
            suggestion: "Ensure $type names contain only alphanumeric characters and underscores",
        );
    }

    /**
     * @param array<string> $allowed
     */
    public static function fieldNotFilterable(
        string $field,
        array $allowed,
    ): self {
        return new self(
            message: "Field '$field' is not filterable",
            context: "Filterable fields: '" . implode("', '", $allowed) . "'",
            suggestion: 'Filter on one of the filterable fields, or declare the column in getFilterableFields() by'
                . ' implementing FilterableInterface on the searchable',
        );
    }

    /**
     * @param array<string> $allowed
     */
    public static function fieldNotSortable(
        string $field,
        array $allowed,
    ): self {
        return new self(
            message: "Field '$field' is not sortable",
            context: "Sortable fields: '" . implode("', '", $allowed) . "'",
            suggestion: 'Sort by one of the sortable fields, or declare the column in getSortableFields() by'
                . ' implementing SortableInterface on the searchable',
        );
    }

    public static function invalidPage(
        int $page,
    ): self {
        return new self(
            message: "Invalid search page: $page",
            context: 'Search pages are numbered from 1',
            suggestion: 'Pass a page number of 1 or greater to SearchCriteria::withPage()',
        );
    }

    public static function invalidSortDirection(
        string $direction,
    ): self {
        return new self(
            message: "Invalid sort direction: '$direction'",
            context: "Sort direction must be 'asc' or 'desc', got '$direction'",
            suggestion: "Use 'asc' or 'desc' for sort direction",
        );
    }
}

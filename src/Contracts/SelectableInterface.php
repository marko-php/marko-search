<?php

declare(strict_types=1);

namespace Marko\Search\Contracts;

/**
 * Optional companion to SearchableInterface: declares the columns a search returns for each row.
 *
 * A searchable that does not implement this interface returns its searchable fields only. The driver never runs
 * SELECT *, so a column the entity did not declare (a password hash, a reset token) never reaches the results.
 */
interface SelectableInterface
{
    /**
     * Get the column names returned in each result row, usually the primary key plus the fields a listing shows.
     *
     * Example: ['id', 'title', 'slug', 'created_at']
     *
     * @return array<string>
     */
    public function getSelectableFields(): array;
}

<?php

declare(strict_types=1);

namespace Marko\Search\Contracts;

/**
 * Optional companion to SearchableInterface: declares the columns SearchCriteria may sort by.
 *
 * A searchable that does not implement this interface is sortable on its searchable fields only, so a request
 * can never sort by a column the entity did not declare (a password hash, a reset token).
 */
interface SortableInterface
{
    /**
     * Get the column names SearchCriteria may sort by.
     *
     * Example: ['title', 'created_at']
     *
     * @return array<string>
     */
    public function getSortableFields(): array;
}

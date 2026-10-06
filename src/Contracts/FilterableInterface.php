<?php

declare(strict_types=1);

namespace Marko\Search\Contracts;

/**
 * Optional companion to SearchableInterface: declares the columns SearchCriteria filters may target.
 *
 * A searchable that does not implement this interface is filterable on its searchable fields only, so a request
 * can never filter on a column the entity did not declare (a password hash, a reset token).
 */
interface FilterableInterface
{
    /**
     * Get the column names SearchCriteria filters may target.
     *
     * Example: ['status', 'author_id', 'created_at']
     *
     * @return array<string>
     */
    public function getFilterableFields(): array;
}

<?php

declare(strict_types=1);

namespace Marko\Search\Tests\Integration;

use Marko\Database\Attributes\Column;
use Marko\Database\Attributes\Table;
use Marko\Database\Entity\Entity;

/**
 * A searchable row whose columns are reserved words (key, group, order) on MySQL, MariaDB and PostgreSQL, plus a
 * mixed-case column that PostgreSQL folds to lower case unless it is quoted.
 */
#[Table('search_reserved_settings')]
class ReservedWordSetting extends Entity
{
    #[Column(primaryKey: true, autoIncrement: true)]
    public ?int $id = null;

    #[Column(unique: true)]
    public string $key = '';

    #[Column]
    public string $group = '';

    #[Column]
    public int $order = 0;

    #[Column(name: 'displayName')]
    public string $displayName = '';
}

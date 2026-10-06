<?php

declare(strict_types=1);

namespace Marko\Search\Tests\Integration;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Diff\SchemaDiff;
use Marko\Database\Diff\SqlGeneratorInterface;
use Marko\Database\Entity\EntityMetadataFactory;
use Marko\Database\Entity\SchemaBuilder;
use Marko\Search\Config\SearchConfig;
use Marko\Search\Contracts\FilterableInterface;
use Marko\Search\Contracts\SearchableInterface;
use Marko\Search\Contracts\SelectableInterface;
use Marko\Search\Contracts\SortableInterface;
use Marko\Search\Driver\DatabaseSearchDriver;
use Marko\Testing\Fake\FakeConfigRepository;

/**
 * Builds the search_reserved_settings table from ReservedWordSetting through SchemaBuilder and a driver's
 * generator, as db:migrate builds it, and seeds it through hand-written, quoted SQL.
 */
class ReservedWordSearchTable
{
    public const string TABLE = 'search_reserved_settings';

    public static function create(
        ConnectionInterface $connection,
        SqlGeneratorInterface $generator,
    ): void {
        $table = new SchemaBuilder()->build(new EntityMetadataFactory()->parse(ReservedWordSetting::class));

        foreach ($generator->generateUp(new SchemaDiff(tablesToCreate: [$table->name => $table])) as $statement) {
            $connection->execute($statement);
        }
    }

    public static function drop(
        ConnectionInterface $connection,
    ): void {
        $connection->execute('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier(self::TABLE));
    }

    /**
     * Insert three rows: (alpha, admin, 3, Alpha Setting), (beta, general, 1, Beta Setting) and
     * (gamma, general, 2, Gamma Setting).
     */
    public static function seed(
        ConnectionInterface $connection,
    ): void {
        $columns = implode(
            ', ',
            array_map($connection->quoteIdentifier(...), ['key', 'group', 'order', 'displayName']),
        );
        $sql = 'INSERT INTO ' . $connection->quoteIdentifier(self::TABLE) . " ($columns) VALUES (?, ?, ?, ?)";

        foreach ([
            ['alpha', 'admin', 3, 'Alpha Setting'],
            ['beta', 'general', 1, 'Beta Setting'],
            ['gamma', 'general', 2, 'Gamma Setting'],
        ] as $row) {
            $connection->execute($sql, $row);
        }
    }

    /**
     * A driver searching the key, group and displayName columns, filtering and sorting on those plus order, and
     * returning every column.
     */
    public static function driver(
        ConnectionInterface $connection,
    ): DatabaseSearchDriver {
        $searchable = new readonly class () implements
            SearchableInterface,
            FilterableInterface,
            SortableInterface,
            SelectableInterface
        {
            public function getSearchableFields(): array
            {
                return ['key' => 3.0, 'group' => 2.0, 'displayName' => 1.0];
            }

            public function getFilterableFields(): array
            {
                return ['key', 'group', 'order', 'displayName'];
            }

            public function getSortableFields(): array
            {
                return ['key', 'order', 'displayName'];
            }

            public function getSelectableFields(): array
            {
                return ['id', 'key', 'group', 'order', 'displayName'];
            }
        };
        $config = new SearchConfig(new FakeConfigRepository(['search.max_per_page' => 100]));

        return new DatabaseSearchDriver($connection, self::TABLE, $searchable, $config);
    }
}

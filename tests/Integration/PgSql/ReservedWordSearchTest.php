<?php

declare(strict_types=1);

namespace Marko\Search\Tests\Integration\PgSql;

use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Sql\PgSqlGenerator;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase;

use function Marko\Search\Tests\Integration\reservedWordSearchCases;

use Marko\Search\Tests\Integration\ReservedWordSearchTable;

require_once dirname(__DIR__) . '/ReservedWordSearchCases.php';

/*
 * DatabaseSearchDriver against a real PostgreSQL server, over a table with reserved-word (key, group, order) and
 * mixed-case (displayName) columns created from the ReservedWordSetting entity through SchemaBuilder and
 * PgSqlGenerator, as db:migrate creates it. Set MARKO_TEST_PGSQL_HOST (and optionally _PORT, _DATABASE, _USERNAME,
 * _PASSWORD) to enable; the tests skip otherwise. They create and drop the search_reserved_settings table.
 *
 * Settings come from the database-pgsql IntegrationDatabase fixture. With MARKO_INTEGRATION_REQUIRED set (CI), a
 * missing host fails instead of skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new PgSqlConnection($config);
    ReservedWordSearchTable::drop($this->connection);
    ReservedWordSearchTable::create($this->connection, new PgSqlGenerator());
    ReservedWordSearchTable::seed($this->connection);
    $this->driver = ReservedWordSearchTable::driver($this->connection);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        ReservedWordSearchTable::drop($this->connection);
        $this->connection->disconnect();
    }
});

describe('DatabaseSearchDriver on PostgreSQL', function (): void {
    reservedWordSearchCases();
});

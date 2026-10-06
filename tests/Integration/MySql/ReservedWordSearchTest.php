<?php

declare(strict_types=1);

namespace Marko\Search\Tests\Integration\MySql;

use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Sql\MySqlGenerator;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase;

use function Marko\Search\Tests\Integration\reservedWordSearchCases;

use Marko\Search\Tests\Integration\ReservedWordSearchTable;

require_once dirname(__DIR__) . '/ReservedWordSearchCases.php';

/*
 * DatabaseSearchDriver against a real MySQL or MariaDB server, over a table with reserved-word (key, group, order)
 * and mixed-case (displayName) columns created from the ReservedWordSetting entity through SchemaBuilder and
 * MySqlGenerator, as db:migrate creates it. Set MARKO_TEST_MYSQL_HOST (and optionally _PORT, _DATABASE, _USERNAME,
 * _PASSWORD) to enable; the tests skip otherwise. They create and drop the search_reserved_settings table. CI runs
 * this suite against MySQL 8.4, MariaDB 11.8 and MariaDB 10.11.
 *
 * Settings come from the database-mysql IntegrationDatabase fixture. With MARKO_INTEGRATION_REQUIRED set (CI), a
 * missing host fails instead of skipping. Part of the integration-services group.
 */

pest()->group('integration-services');

beforeEach(function (): void {
    $config = IntegrationDatabase::config();

    if ($config === null) {
        $this->markTestSkipped(IntegrationDatabase::SKIP_REASON);
    }

    $this->connection = new MySqlConnection($config);
    ReservedWordSearchTable::drop($this->connection);
    ReservedWordSearchTable::create($this->connection, new MySqlGenerator());
    ReservedWordSearchTable::seed($this->connection);
    $this->driver = ReservedWordSearchTable::driver($this->connection);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        ReservedWordSearchTable::drop($this->connection);
        $this->connection->disconnect();
    }
});

describe('DatabaseSearchDriver on MySQL and MariaDB', function (): void {
    reservedWordSearchCases();
});

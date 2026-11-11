<?php

declare(strict_types=1);

namespace Guild\Framework\Testing;

use Illuminate\Database\Connection;
use Phinx\Config\Config;
use Phinx\Db\Adapter\AdapterFactory;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Runs the framework's real migrations against a test database, so tests use
 * the schema production gets rather than a hand-written copy of it.
 *
 * Inert unless test code calls it.
 */
final class FrameworkSchema
{
    private const string ENVIRONMENT = 'framework';

    /**
     * Create the framework's tables on $connection, e.g. an in-memory SQLite one.
     */
    public static function migrate(Connection $connection): void
    {
        // Phinx picks the adapter by the PDO's driver name, so the corrected
        // SQLite adapter replaces the stock one for the rest of the process.
        if ($connection->getDriverName() === 'sqlite') {
            AdapterFactory::instance()->registerAdapter('sqlite', PrefixAwareSQLiteAdapter::class);
        }

        $config = new Config([
            'paths' => ['migrations' => dirname(__DIR__, 2) . '/db/migrations'],
            'environments' => [
                'default_migration_table' => 'framework_migration_log',
                'default_environment' => self::ENVIRONMENT,
                self::ENVIRONMENT => [
                    'name' => $connection->getDatabaseName(),
                    'connection' => $connection->getPdo(),
                    'table_prefix' => 'framework_',
                ],
            ],
            'version_order' => 'creation',
            'feature_flags' => ['add_timestamps_use_datetime' => true],
        ]);

        new Manager($config, new ArrayInput([]), new NullOutput())->migrate(self::ENVIRONMENT);
    }
}

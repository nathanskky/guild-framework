<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Testing;

use Guild\Framework\Testing\FrameworkSchema;
use Guild\Framework\Testing\PrefixAwareSQLiteAdapter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrameworkSchema::class)]
#[UsesClass(PrefixAwareSQLiteAdapter::class)]
final class FrameworkSchemaTest extends TestCase
{
    private const array GRANT_TABLES = [
        'framework_groups',
        'framework_roles',
        'framework_groups_roles',
        'framework_role_permissions',
    ];

    public function testTheFourGrantTablesExistAfterMigrating(): void
    {
        $connection = self::connection();

        FrameworkSchema::migrate($connection);

        foreach (self::GRANT_TABLES as $table) {
            self::assertTrue($connection->getSchemaBuilder()->hasTable($table), $table . ' is created by the real migrations');
        }
    }

    public function testAuditColumnsHoldSixtyFourCharacters(): void
    {
        $connection = self::connection();

        FrameworkSchema::migrate($connection);

        foreach (self::GRANT_TABLES as $table) {
            $types = [];

            foreach ($connection->select('PRAGMA table_info(' . $table . ')') as $column) {
                self::assertIsObject($column);
                $types[(string) $column->name] = (string) $column->type;
            }

            foreach (['created_by', 'updated_by'] as $audit) {
                self::assertStringContainsString('64', $types[$audit] ?? '', $table . '.' . $audit . ' holds usernames longer than 8 characters, such as persona-roster');
            }
        }
    }

    public function testMigratingTwiceInOneProcessIsHarmless(): void
    {
        $first = self::connection();
        $second = self::connection();

        FrameworkSchema::migrate($first);
        FrameworkSchema::migrate($second);

        self::assertTrue($first->getSchemaBuilder()->hasTable('framework_groups'), 'the first database is migrated');
        self::assertTrue($second->getSchemaBuilder()->hasTable('framework_groups'), 'and so is the second, though the migration classes are already loaded');
    }

    private static function connection(): Connection
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $capsule->getConnection();
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}

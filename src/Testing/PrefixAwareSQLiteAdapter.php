<?php

declare(strict_types=1);

namespace Guild\Framework\Testing;

use Override;
use Phinx\Db\Adapter\SQLiteAdapter;

/**
 * Phinx's SQLite adapter, corrected for a table prefix.
 *
 * After adding a foreign key, Phinx 0.16 checks the constraints of the
 * tables it changed under their unprefixed names ('groups_roles', not
 * 'framework_groups_roles') and fails with "no such table". This adds the
 * prefix before the check. FrameworkSchema registers it as the "sqlite"
 * adapter, so it replaces the stock one for the rest of that process.
 *
 * @internal
 */
final class PrefixAwareSQLiteAdapter extends SQLiteAdapter
{
    /**
     * @param  array<string>  $tableNames
     * @param  array<string, mixed>  $preOptions
     */
    #[Override]
    public function postExecuteActions(array $tableNames, array $preOptions): void
    {
        $prefix = $this->getOption('table_prefix');
        $prefix = is_string($prefix) ? $prefix : '';

        parent::postExecuteActions(
            array_map(
                static fn (string $name): string => str_starts_with($name, $prefix) ? $name : $prefix . $name,
                $tableNames,
            ),
            $preOptions,
        );
    }
}

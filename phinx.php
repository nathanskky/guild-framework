<?php

declare(strict_types=1);

use Guild\Framework\Application;
use Illuminate\Database\Capsule\Manager;

// TODO: explore a better way to load the application and get the database connection for Phinx migrations

// This file works only from <app>/vendor/guild/framework/, so run Phinx from the
// consuming app: vendor/bin/phinx -c vendor/guild/framework/phinx.php migrate -e framework.
// It boots the whole application for a PDO handle, so loading it needs a live
// database connection. The framework_ prefix set here is also hard-coded in each
// model's #[Table]; the migrations name bare tables. Change all three together.

/** @var Application $app */
$app = require dirname(__FILE__, 4) . '/bootstrap/app.php';

/** @var Manager $eloquentCapsule */
$eloquentCapsule = $app->get(Manager::class);

return [
    'paths' => [
        'migrations' => __DIR__ . '/db/migrations',
        'seeds' => __DIR__ . '/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'framework_migration_log',
        'default_environment' => 'framework',
        'framework' => [
            'name' => $eloquentCapsule->getConnection()->getDatabaseName(),
            'connection' => $eloquentCapsule->getConnection()->getPdo(),
            'table_prefix' => 'framework_'
        ]
    ],
    'version_order' => 'creation',
    'feature_flags' => [
        'add_timestamps_use_datetime' => true
    ]
];

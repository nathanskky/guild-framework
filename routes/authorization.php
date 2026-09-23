<?php

declare(strict_types=1);

/**
 * The framework's authorization administration routes, mounted under
 * /framework/authorization by addAuthorization(). Every route in the group
 * is gated on the System Admin group and CSRF-checked.
 */

use Guild\Framework\Controller\Authorization\GroupController;
use Guild\Framework\Controller\Authorization\OverviewController;
use Guild\Framework\Controller\Authorization\RoleController;
use League\Route\RouteGroup;

return static function (RouteGroup $group): void {
    $group->map('GET', '/', [OverviewController::class, 'index']);
    $group->map('GET', '/groups', [GroupController::class, 'index']);
    $group->map('GET', '/roles', [RoleController::class, 'index']);
};

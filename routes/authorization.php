<?php

declare(strict_types=1);

/**
 * The framework's authorization administration routes, mounted under
 * /framework/authorization by addAuthorization(). Every route in the group
 * is gated on the System Admin group, and every POST is CSRF-checked.
 */

use Guild\Framework\Controller\Authorization\GroupController;
use Guild\Framework\Controller\Authorization\OverviewController;
use Guild\Framework\Controller\Authorization\RoleController;
use League\Route\RouteGroup;

return static function (RouteGroup $group): void {
    $group->map('GET', '/', [OverviewController::class, 'index']);

    $group->map('GET', '/groups', [GroupController::class, 'index']);
    $group->map('GET', '/groups/register', [GroupController::class, 'registerForm']);
    $group->map('POST', '/groups/register', [GroupController::class, 'register']);
    $group->map('POST', '/groups/register/choose', [GroupController::class, 'choose']);
    $group->map('GET', '/groups/{id:number}', [GroupController::class, 'edit']);
    $group->map('POST', '/groups/{id:number}', [GroupController::class, 'update']);
    $group->map('GET', '/groups/{id:number}/delete', [GroupController::class, 'confirmDelete']);
    $group->map('POST', '/groups/{id:number}/delete', [GroupController::class, 'delete']);

    $group->map('GET', '/roles', [RoleController::class, 'index']);
    $group->map('GET', '/roles/new', [RoleController::class, 'createForm']);
    $group->map('POST', '/roles/new', [RoleController::class, 'create']);
    $group->map('GET', '/roles/{id:number}', [RoleController::class, 'edit']);
    $group->map('POST', '/roles/{id:number}', [RoleController::class, 'update']);
    $group->map('GET', '/roles/{id:number}/delete', [RoleController::class, 'confirmDelete']);
    $group->map('POST', '/roles/{id:number}/delete', [RoleController::class, 'delete']);
};

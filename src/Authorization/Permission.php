<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use BackedEnum;

/**
 * Marker for the application's permission catalog.
 *
 * The application declares one string-backed enum implementing this interface
 * and passes its class to addAuthorization(). Each case's value is the name
 * stored in framework_role_permissions, e.g.:
 *
 *     enum AppPermission: string implements Permission
 *     {
 *         case InvoicesApprove = 'invoices.approve';
 *     }
 *
 * Only enums can implement an interface that extends BackedEnum, so nothing
 * else can be passed where a Permission is expected.
 */
interface Permission extends BackedEnum
{
}

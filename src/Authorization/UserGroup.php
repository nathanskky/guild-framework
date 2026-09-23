<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

/**
 * One Grouper group the user belongs to.
 *
 * Match on $identifier only. $label is for display: it can be renamed in ACM
 * at any time and is not unique, so a rule written against it can silently
 * start matching a different group, or stop matching at all.
 */
final readonly class UserGroup
{
    /**
     * @param  string  $identifier  The Grouper system name, e.g. 'iu:roles:sys:acm:your-app-editors'.
     * @param  string  $label  The short ACM label, e.g. 'Your App Editors'.
     */
    public function __construct(
        public string $identifier,
        public string $label,
    ) {
    }
}

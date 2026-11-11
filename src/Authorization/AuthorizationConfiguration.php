<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Guild\Framework\Exception\ConfigurationException;

/**
 * The deployment-specific settings for addAuthorization(), returned by
 * config/authorization.php.
 *
 * Everything here varies between environments. Code-level facts (the
 * identity source, the permission enum) are addAuthorization() arguments
 * instead, and Grouper connection settings are in config/grouper.php.
 */
final readonly class AuthorizationConfiguration
{
    public ?string $systemAdminGroup;

    /**
     * @param  ?string  $systemAdminGroup  The ACM label (displayExtension) of the group whose members
     *                                     administer the application. Membership bypasses every check.
     *                                     Blank is null, which is valid only when an installed extension
     *                                     supplies the label; otherwise addAuthorization() refuses to boot.
     * @param  int  $membershipTtl  Seconds a user's cached Grouper membership is used before
     *                              Grouper is asked again.
     * @param  int  $staleCap  Seconds past the TTL cached membership may still be used while
     *                         Grouper is unreachable. Zero never uses stale membership.
     */
    public function __construct(
        ?string $systemAdminGroup = null,
        public int $membershipTtl = 900,
        public int $staleCap = 3600,
    ) {
        $systemAdminGroup = trim($systemAdminGroup ?? '');

        if ($membershipTtl <= 0) {
            throw new ConfigurationException('The membership TTL must be a positive number of seconds.');
        }

        if ($staleCap < 0) {
            throw new ConfigurationException('The stale cap must not be negative.');
        }

        $this->systemAdminGroup = $systemAdminGroup === '' ? null : $systemAdminGroup;
    }
}

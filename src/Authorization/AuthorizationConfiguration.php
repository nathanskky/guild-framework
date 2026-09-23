<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Guild\Framework\Exception\ConfigurationException;
use Guild\Grouper\GrouperConfiguration;

/**
 * The deployment-specific settings for addAuthorization(), returned by
 * config/authorization.php.
 *
 * Everything here varies between environments, and the Grouper credentials
 * are a secret. Code-level facts (the identity source, the permission enum)
 * are addAuthorization() arguments instead.
 */
final readonly class AuthorizationConfiguration
{
    public string $systemAdminGroup;

    /**
     * @param  GrouperConfiguration  $grouper  Grouper connection settings. Validated on construction,
     *                                         which is what checks them at startup.
     * @param  string  $systemAdminGroup  The ACM label (displayExtension) of the group whose members
     *                                    administer the application. Membership bypasses every check.
     * @param  int  $membershipTtl  Seconds a user's cached Grouper membership is used before
     *                              Grouper is asked again.
     * @param  int  $staleCap  Seconds past the TTL cached membership may still be used while
     *                         Grouper is unreachable. Zero never uses stale membership.
     * @param  float  $grouperTimeout  Seconds to wait for a Grouper response.
     * @param  float  $grouperConnectTimeout  Seconds to wait for a connection to Grouper.
     */
    public function __construct(
        public GrouperConfiguration $grouper,
        string $systemAdminGroup,
        public int $membershipTtl = 900,
        public int $staleCap = 3600,
        public float $grouperTimeout = 10.0,
        public float $grouperConnectTimeout = 3.0,
    ) {
        $systemAdminGroup = trim($systemAdminGroup);

        if ($systemAdminGroup === '') {
            throw new ConfigurationException('The system admin group label must not be blank.');
        }

        if ($membershipTtl <= 0) {
            throw new ConfigurationException('The membership TTL must be a positive number of seconds.');
        }

        if ($staleCap < 0) {
            throw new ConfigurationException('The stale cap must not be negative.');
        }

        if ($grouperTimeout <= 0 || $grouperConnectTimeout <= 0) {
            throw new ConfigurationException('Grouper timeouts must be positive numbers of seconds.');
        }

        $this->systemAdminGroup = $systemAdminGroup;
    }
}

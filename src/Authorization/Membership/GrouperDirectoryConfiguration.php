<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Membership;

use Guild\Framework\Exception\ConfigurationException;
use Guild\Grouper\GrouperConfiguration;

/**
 * How to reach live Grouper, returned by config/grouper.php.
 *
 * Kept apart from AuthorizationConfiguration so an application whose groups
 * come from another directory never needs Grouper credentials to start.
 */
final readonly class GrouperDirectoryConfiguration
{
    /**
     * @param  GrouperConfiguration  $grouper  Grouper connection settings. Validated on construction,
     *                                         which is what checks them at startup.
     * @param  float  $timeout  Seconds to wait for a Grouper response.
     * @param  float  $connectTimeout  Seconds to wait for a connection to Grouper.
     */
    public function __construct(
        public GrouperConfiguration $grouper,
        public float $timeout = 10.0,
        public float $connectTimeout = 3.0,
    ) {
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new ConfigurationException('Grouper timeouts must be positive numbers of seconds.');
        }
    }
}

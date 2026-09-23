<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization;

use Guild\Framework\Exception\ConfigurationException;

/**
 * Where the framework's administration menu appears in the application's
 * header navigation. Pass null to addAuthorization() instead to leave the
 * header alone; the /framework pages stay reachable, and gated, either way.
 */
final readonly class AdminMenu
{
    /**
     * @param  string  $label  The top-level menu label.
     * @param  int|null  $position  Zero-based position among the application's items; null places
     *                              it last. A position past the end also places it last.
     */
    public function __construct(
        public string $label = 'System settings',
        public ?int $position = null,
    ) {
        if (trim($label) === '') {
            throw new ConfigurationException('The admin menu label must not be blank.');
        }

        if ($position !== null && $position < 0) {
            throw new ConfigurationException('The admin menu position must not be negative.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Guild\Framework\Extension;

/**
 * What a slot was set to, and who set it: an extension's class name, or
 * 'the application'.
 *
 * @internal
 */
final readonly class SlotClaim
{
    public function __construct(
        public string $value,
        public string $claimant,
    ) {
    }
}

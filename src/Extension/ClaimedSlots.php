<?php

declare(strict_types=1);

namespace Guild\Framework\Extension;

use Closure;
use Guild\Framework\Exception\ConfigurationException;

/**
 * The slots chosen through ApplicationBuilder's use*() methods, each with the
 * extension that chose it, and the takeover notices to log at create().
 *
 * A slot can be chosen once. A second choice fails at boot naming both
 * claimants, so two extensions never silently override each other.
 *
 * @internal
 */
final class ClaimedSlots
{
    private const string APPLICATION = 'the application';

    public private(set) ?SlotClaim $signInMethod = null;

    public private(set) ?SlotClaim $groupDirectory = null;

    public private(set) ?SlotClaim $systemAdminGroup = null;

    private string $claimant = self::APPLICATION;

    /** @var list<string> */
    private array $takeovers = [];

    private bool $created = false;

    /**
     * Run $configure with $claimant named as the maker of any claim in it.
     */
    public function during(string $claimant, Closure $configure): void
    {
        $previous = $this->claimant;
        $this->claimant = $claimant;

        try {
            $configure();
        } finally {
            $this->claimant = $previous;
        }
    }

    public function claimSignInMethod(string $class): void
    {
        $this->signInMethod = $this->claim('The sign-in method', $this->signInMethod, $class);
    }

    public function claimGroupDirectory(string $class): void
    {
        $this->groupDirectory = $this->claim('The group directory', $this->groupDirectory, $class);
    }

    public function claimSystemAdminGroup(string $label): void
    {
        $this->systemAdminGroup = $this->claim('The System Admin group', $this->systemAdminGroup, $label);
    }

    /**
     * Record a notice, logged at create(), that a claim replaced the application's own setting.
     */
    public function takeover(string $message): void
    {
        $this->takeovers[] = $message;
    }

    /**
     * @return list<string>
     */
    public function takeovers(): array
    {
        return $this->takeovers;
    }

    /**
     * Record that create() has run. True the first time only, so the
     * takeover notices are logged and the extensions booted once.
     */
    public function markCreated(): bool
    {
        if ($this->created) {
            return false;
        }

        $this->created = true;

        return true;
    }

    private function claim(string $slot, ?SlotClaim $existing, string $value): SlotClaim
    {
        if ($existing !== null) {
            throw new ConfigurationException(sprintf(
                '%s was already chosen by %s; %s cannot choose it too. Remove one of the two packages.',
                $slot,
                $existing->claimant,
                $this->claimant,
            ));
        }

        return new SlotClaim($value, $this->claimant);
    }
}

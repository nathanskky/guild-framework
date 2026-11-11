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
 * claimants, so two extensions never silently override each other. A choice
 * made after the add*() call that reads the slot fails too, because it could
 * no longer take effect.
 *
 * @internal
 */
final class ClaimedSlots
{
    private const string APPLICATION = 'the application';

    private const array NAMES = [
        'signInMethod' => 'Sign-in method',
        'groupDirectory' => 'Group directory',
        'systemAdminGroup' => 'System Admin group',
    ];

    private const array SUBJECTS = [
        'signInMethod' => 'The sign-in method',
        'groupDirectory' => 'The group directory',
        'systemAdminGroup' => 'The System Admin group',
    ];

    private const array READERS = [
        'signInMethod' => 'addAuthentication()',
        'groupDirectory' => 'addAuthorization()',
        'systemAdminGroup' => 'addAuthorization()',
    ];

    public private(set) ?SlotClaim $signInMethod = null;

    public private(set) ?SlotClaim $groupDirectory = null;

    public private(set) ?SlotClaim $systemAdminGroup = null;

    private string $claimant = self::APPLICATION;

    /** @var list<string> */
    private array $takeovers = [];

    private bool $created = false;

    /**
     * The add*() call that read each slot, keyed by slot.
     *
     * @var array<'signInMethod'|'groupDirectory'|'systemAdminGroup', string>
     */
    private array $readBy = [];

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
        $this->signInMethod = $this->claim('signInMethod', $this->signInMethod, $class);
    }

    public function claimGroupDirectory(string $class): void
    {
        $this->groupDirectory = $this->claim('groupDirectory', $this->groupDirectory, $class);
    }

    public function claimSystemAdminGroup(string $label): void
    {
        $this->systemAdminGroup = $this->claim('systemAdminGroup', $this->systemAdminGroup, $label);
    }

    /**
     * Record that $call has decided the sign-in method; a later choice is refused.
     */
    public function markSignInMethodRead(string $call): void
    {
        $this->readBy['signInMethod'] ??= $call;
    }

    /**
     * Record that $call has decided the group directory; a later choice is refused.
     */
    public function markGroupDirectoryRead(string $call): void
    {
        $this->readBy['groupDirectory'] ??= $call;
    }

    /**
     * Record that $call has decided the System Admin group; a later choice is refused.
     */
    public function markSystemAdminGroupRead(string $call): void
    {
        $this->readBy['systemAdminGroup'] ??= $call;
    }

    /**
     * One notice per choice that no add*() call read, e.g. a group directory in an
     * application that never calls addAuthorization().
     *
     * @return list<string>
     */
    public function unusedClaims(): array
    {
        $notices = [];

        foreach ($this->claims() as $slot => $claim) {
            if (! isset($this->readBy[$slot])) {
                $notices[] = sprintf(
                    '%s %s was chosen by %s, but %s was not called, so it is not used.',
                    self::NAMES[$slot],
                    $slot === 'systemAdminGroup' ? "'" . $claim->value . "'" : $claim->value,
                    $claim->claimant,
                    self::READERS[$slot],
                );
            }
        }

        return $notices;
    }

    /**
     * One message per chosen class that was read but that $canBuild says the
     * container cannot build.
     *
     * @param  Closure(string): bool  $canBuild
     * @return list<string>
     */
    public function unbuildableClaims(Closure $canBuild): array
    {
        $messages = [];

        foreach (['signInMethod' => $this->signInMethod, 'groupDirectory' => $this->groupDirectory] as $slot => $claim) {
            if ($claim === null || ! isset($this->readBy[$slot]) || $canBuild($claim->value)) {
                continue;
            }

            $messages[] = sprintf(
                '%s %s was chosen by %s, but the container cannot build it. %s, or call enableAutoWiring().',
                self::NAMES[$slot],
                $claim->value,
                $claim->claimant,
                $claim->claimant === self::APPLICATION ? 'Bind it in the container' : 'Bind it in ' . $claim->claimant . '::boot()',
            );
        }

        return $messages;
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

    /**
     * @return array<'signInMethod'|'groupDirectory'|'systemAdminGroup', SlotClaim>
     */
    private function claims(): array
    {
        return array_filter([
            'signInMethod' => $this->signInMethod,
            'groupDirectory' => $this->groupDirectory,
            'systemAdminGroup' => $this->systemAdminGroup,
        ]);
    }

    /**
     * @param  'signInMethod'|'groupDirectory'|'systemAdminGroup'  $slot
     */
    private function claim(string $slot, ?SlotClaim $existing, string $value): SlotClaim
    {
        $name = self::SUBJECTS[$slot];

        if ($existing !== null) {
            throw new ConfigurationException(sprintf(
                '%s was already chosen by %s; %s cannot choose it too. Remove one of the two packages.',
                $name,
                $existing->claimant,
                $this->claimant,
            ));
        }

        if (isset($this->readBy[$slot])) {
            throw new ConfigurationException(sprintf(
                "%s was chosen by %s after %s had already run without it. Choose it before that call; an extension's "
                . "configure() runs before the application's own calls.",
                $name,
                $this->claimant,
                $this->readBy[$slot],
            ));
        }

        return new SlotClaim($value, $this->claimant);
    }
}

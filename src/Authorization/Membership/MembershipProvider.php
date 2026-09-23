<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Membership;

use Closure;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Grouper\GrouperClient;
use Guild\Grouper\GroupMembership;
use Psr\Log\LoggerInterface;

/**
 * Answers "which Grouper groups is this user in", with a session cache in
 * front of Grouper and bounded staleness when Grouper is unreachable.
 *
 * - A cache entry younger than the TTL is used without calling Grouper.
 * - Otherwise Grouper is asked, and a successful answer (including an empty
 *   one) replaces the cache entry.
 * - If Grouper is unreachable, an entry younger than TTL + stale cap is
 *   served as stale, with a warning logged every time.
 * - Past that, or with no entry, the answer is unavailable and this throws.
 *
 * Grouper errors that a person must fix (rejected credentials, malformed
 * responses) are not caught; they propagate from guild/grouper as thrown.
 *
 * @internal
 */
final readonly class MembershipProvider
{
    /**
     * @param  int  $ttl  Seconds a cache entry is used without asking Grouper.
     * @param  int  $staleCap  Seconds past the TTL an entry may still be served while Grouper
     *                         is unreachable. Zero means never serve stale membership.
     * @param  Closure(): int  $clock  Returns the current Unix time.
     */
    public function __construct(
        private GrouperClient $grouper,
        private MembershipCache $cache,
        private LoggerInterface $logger,
        private int $ttl,
        private int $staleCap,
        private Closure $clock,
    ) {
    }

    /**
     * @throws AuthorizationUnavailableException when no usable membership exists
     */
    public function membershipFor(string $username): Membership
    {
        $now = ($this->clock)();
        $cached = $this->cache->get($username);

        if ($cached !== null && $now - $cached['fetchedAt'] < $this->ttl) {
            return new Membership($cached['groups'], true, $cached['fetchedAt']);
        }

        $result = $this->grouper->groupsFor($username);

        if ($result instanceof GroupMembership) {
            $this->cache->put($username, $result->groups, $now);

            return new Membership($result->groups, true, $now);
        }

        if ($cached !== null && $now - $cached['fetchedAt'] < $this->ttl + $this->staleCap) {
            $this->logger->warning(
                'Grouper is unavailable ({reason}); serving cached group membership for {username} '
                . 'that is {age} seconds old. It stops being served {remaining} seconds from now.',
                [
                    'reason' => $result->reason,
                    'username' => $username,
                    'age' => $now - $cached['fetchedAt'],
                    'remaining' => $cached['fetchedAt'] + $this->ttl + $this->staleCap - $now,
                ],
            );

            return new Membership($cached['groups'], false, $cached['fetchedAt']);
        }

        $this->logger->error(
            'Grouper is unavailable ({reason}) and there is no usable cached group membership for '
            . '{username}; authorization checks for this user cannot be answered.',
            ['reason' => $result->reason, 'username' => $username],
        );

        throw new AuthorizationUnavailableException(
            "Group membership for {$username} could not be determined: Grouper is unavailable "
            . "({$result->reason}) and no cached membership is recent enough to use.",
            previous: $result->previous,
        );
    }
}

<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Membership;

use Guild\Grouper\GrouperGroup;

/**
 * Caches one user's Grouper membership in the PHP session.
 *
 * Groups are stored as plain arrays rather than serialized GrouperGroup
 * objects, so a change to guild/grouper's classes cannot leave unreadable
 * entries in sessions that are already live. An entry that does not match the
 * expected shape reads as a miss.
 *
 * @internal
 */
final readonly class MembershipCache
{
    private const string KEY = 'guild_framework_membership';

    public function __construct(private Session $session)
    {
    }

    /**
     * The cached membership for $username, or null if there is none. An entry
     * belonging to a different username is ignored.
     *
     * @return array{fetchedAt: int, groups: list<GrouperGroup>}|null
     */
    public function get(string $username): ?array
    {
        $this->session->ensureStarted();

        $entry = $_SESSION[self::KEY] ?? null;

        if (
            ! is_array($entry)
            || ($entry['username'] ?? null) !== $username
            || ! is_int($entry['fetched_at'] ?? null)
            || ! is_array($entry['groups'] ?? null)
        ) {
            return null;
        }

        $groups = [];

        foreach ($entry['groups'] as $group) {
            $group = $this->groupFrom($group);

            if ($group === null) {
                return null;
            }

            $groups[] = $group;
        }

        return ['fetchedAt' => $entry['fetched_at'], 'groups' => $groups];
    }

    /**
     * @param  list<GrouperGroup>  $groups
     */
    public function put(string $username, array $groups, int $fetchedAt): void
    {
        $this->session->ensureStarted();

        $_SESSION[self::KEY] = [
            'username' => $username,
            'fetched_at' => $fetchedAt,
            'groups' => array_map(
                static fn (GrouperGroup $group): array => [
                    'identifier' => $group->identifier,
                    'displayName' => $group->displayName,
                    'displayExtension' => $group->displayExtension,
                    'uuid' => $group->uuid,
                    'description' => $group->description,
                ],
                $groups,
            ),
        ];
    }

    private function groupFrom(mixed $group): ?GrouperGroup
    {
        if (! is_array($group)) {
            return null;
        }

        $identifier = $group['identifier'] ?? null;
        $displayName = $group['displayName'] ?? null;
        $displayExtension = $group['displayExtension'] ?? null;
        $uuid = $group['uuid'] ?? null;
        $description = $group['description'] ?? null;

        if (
            ! is_string($identifier)
            || ! is_string($displayName)
            || ! is_string($displayExtension)
            || ! is_string($uuid)
            || ($description !== null && ! is_string($description))
        ) {
            return null;
        }

        return new GrouperGroup($identifier, $displayName, $displayExtension, $uuid, $description);
    }
}

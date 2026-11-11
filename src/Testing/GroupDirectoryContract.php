<?php

declare(strict_types=1);

namespace Guild\Framework\Testing;

use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Grouper\GroupMembership;
use Guild\Grouper\GrouperUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every GroupDirectory shares, so a stand-in directory cannot
 * drift from what live Grouper does. Extend it once per implementation and
 * supply the three directories below.
 *
 * Inert unless test code extends it; PHPUnit is needed only by those tests.
 */
abstract class GroupDirectoryContract extends TestCase
{
    private const string MEMBER = 'contract-member';

    private const string UNKNOWN = 'contract-unknown';

    private const string IDENTIFIER = 'iu:roles:sys:acm:contract-editors';

    private const string LABEL = 'Contract Editors';

    /**
     * A directory in which $username belongs to exactly the group ($identifier, $label).
     */
    abstract protected function directoryWhere(string $username, string $identifier, string $label): GroupDirectory;

    /**
     * A directory that has never heard of $username.
     */
    abstract protected function directoryWithout(string $username): GroupDirectory;

    /**
     * A directory that cannot answer right now.
     */
    abstract protected function directoryDuringOutage(): GroupDirectory;

    public function testAnUnknownUserHasAnEmptyMembershipNotAnOutage(): void
    {
        $result = $this->directoryWithout(self::UNKNOWN)->groupsFor(self::UNKNOWN);

        self::assertInstanceOf(GroupMembership::class, $result, 'an unknown user is an answer, so authorization denies cleanly');
        self::assertSame(self::UNKNOWN, $result->username, 'the membership names who was asked about');
        self::assertSame([], $result->groups, 'an unknown user belongs to nothing');
    }

    public function testAKnownUserHasTheirGroupWithIdentifierAndLabel(): void
    {
        $result = $this->directoryWhere(self::MEMBER, self::IDENTIFIER, self::LABEL)->groupsFor(self::MEMBER);

        self::assertInstanceOf(GroupMembership::class, $result, 'a known user gets a membership');
        self::assertCount(1, $result->groups, 'exactly the one group');
        self::assertSame(self::IDENTIFIER, $result->groups[0]->identifier, 'grants match on the Grouper system name');
        self::assertSame(self::LABEL, $result->groups[0]->displayExtension, 'the System Admin check matches on the ACM label');
    }

    public function testAnOutageIsUnavailableWithAReason(): void
    {
        $result = $this->directoryDuringOutage()->groupsFor(self::MEMBER);

        self::assertInstanceOf(GrouperUnavailable::class, $result, 'an outage is not an empty membership');
        self::assertNotSame('', trim($result->reason), 'the reason is logged, so it must say something');
    }
}

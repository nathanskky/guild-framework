<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Membership;

use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Framework\Authorization\Membership\GrouperGroupDirectory;
use Guild\Framework\Testing\GroupDirectoryContract;
use Guild\Grouper\Exception\GrouperConfigurationException;
use Guild\Grouper\Testing\FakeGrouper;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(GrouperGroupDirectory::class)]
#[UsesClass(GroupDirectoryContract::class)]
final class GrouperGroupDirectoryTest extends GroupDirectoryContract
{
    public function testRejectedCredentialsAreThrownNotReportedAsAnOutage(): void
    {
        $directory = new GrouperGroupDirectory(new FakeGrouper([FakeGrouper::rejectedCredentials()])->client);

        $this->expectException(GrouperConfigurationException::class);

        $directory->groupsFor('jdoe');
    }

    #[Override]
    protected function directoryWhere(string $username, string $identifier, string $label): GroupDirectory
    {
        return new GrouperGroupDirectory(new FakeGrouper([FakeGrouper::membership([$identifier => $label])])->client);
    }

    #[Override]
    protected function directoryWithout(string $username): GroupDirectory
    {
        return new GrouperGroupDirectory(new FakeGrouper([FakeGrouper::subjectNotFound()])->client);
    }

    #[Override]
    protected function directoryDuringOutage(): GroupDirectory
    {
        return new GrouperGroupDirectory(new FakeGrouper([FakeGrouper::unavailable()])->client);
    }
}

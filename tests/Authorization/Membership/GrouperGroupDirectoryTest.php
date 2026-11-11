<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Membership;

use Guild\Framework\Authorization\Membership\GroupDirectory;
use Guild\Framework\Authorization\Membership\GrouperGroupDirectory;
use Guild\Framework\Testing\GroupDirectoryContract;
use Guild\Grouper\Exception\GrouperConfigurationException;
use Guild\Grouper\GrouperClient;
use Guild\Grouper\GrouperConfiguration;
use Guild\Grouper\Testing\FakeGrouper;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
        // Answers by subject, as Grouper does, rather than with one canned reply for anyone asked about.
        $answer = static fn (RequestInterface $request): ResponseInterface => str_ends_with(
            $request->getUri()->getPath(),
            '/subjects/' . rawurlencode($username) . '/groups',
        )
            ? FakeGrouper::membership([$identifier => $label])
            : FakeGrouper::subjectNotFound();

        return new GrouperGroupDirectory(new GrouperClient(
            new GrouperConfiguration(serviceUrl: 'https://grouperws.apps.iu.edu/grouper-ws/servicesRest', username: 'svc', password: 'secret'),
            new Client(['handler' => HandlerStack::create(new MockHandler([$answer]))]),
        ));
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

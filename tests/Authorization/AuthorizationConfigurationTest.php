<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Grouper\GrouperConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationConfiguration::class)]
final class AuthorizationConfigurationTest extends TestCase
{
    public function testDefaultsMatchThePlannedValues(): void
    {
        $config = new AuthorizationConfiguration($this->grouper(), '  My App Admins  ');

        self::assertSame('My App Admins', $config->systemAdminGroup, 'the label is trimmed');
        self::assertSame(900, $config->membershipTtl, 'membership is cached for 15 minutes by default');
        self::assertSame(3600, $config->staleCap, 'stale membership is served for up to 60 minutes by default');
    }

    public function testAZeroStaleCapIsAllowed(): void
    {
        $config = new AuthorizationConfiguration($this->grouper(), 'Admins', staleCap: 0);

        self::assertSame(0, $config->staleCap, 'zero means pure fail-closed');
    }

    /**
     * @return iterable<string, array{string, int, int, float}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'blank label' => ['  ', 900, 3600, 10.0];
        yield 'zero TTL' => ['Admins', 0, 3600, 10.0];
        yield 'negative stale cap' => ['Admins', 900, -1, 10.0];
        yield 'zero timeout' => ['Admins', 900, 3600, 0.0];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsThrow(string $label, int $ttl, int $staleCap, float $timeout): void
    {
        $this->expectException(ConfigurationException::class);

        new AuthorizationConfiguration($this->grouper(), $label, $ttl, $staleCap, $timeout);
    }

    private function grouper(): GrouperConfiguration
    {
        return new GrouperConfiguration('https://grouperws.apps.iu.edu/grouper-ws/servicesRest', 'svc', 'secret');
    }
}

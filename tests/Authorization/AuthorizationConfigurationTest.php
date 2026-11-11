<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization;

use Guild\Framework\Authorization\AuthorizationConfiguration;
use Guild\Framework\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizationConfiguration::class)]
final class AuthorizationConfigurationTest extends TestCase
{
    public function testDefaultsMatchThePlannedValues(): void
    {
        $config = new AuthorizationConfiguration('  My App Admins  ');

        self::assertSame('My App Admins', $config->systemAdminGroup, 'the label is trimmed');
        self::assertSame(900, $config->membershipTtl, 'membership is cached for 15 minutes by default');
        self::assertSame(3600, $config->staleCap, 'stale membership is served for up to 60 minutes by default');
    }

    public function testAZeroStaleCapIsAllowed(): void
    {
        $config = new AuthorizationConfiguration('Admins', staleCap: 0);

        self::assertSame(0, $config->staleCap, 'zero means pure fail-closed');
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'blank label' => ['  ', 900, 3600];
        yield 'zero TTL' => ['Admins', 0, 3600];
        yield 'negative stale cap' => ['Admins', 900, -1];
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidSettingsThrow(string $label, int $ttl, int $staleCap): void
    {
        $this->expectException(ConfigurationException::class);

        new AuthorizationConfiguration($label, $ttl, $staleCap);
    }
}

<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Membership;

use Guild\Framework\Authorization\Membership\GrouperDirectoryConfiguration;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Grouper\GrouperConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GrouperDirectoryConfiguration::class)]
final class GrouperDirectoryConfigurationTest extends TestCase
{
    public function testDefaultsAreTenAndThreeSeconds(): void
    {
        $config = new GrouperDirectoryConfiguration($this->grouper());

        self::assertSame(10.0, $config->timeout, 'a Grouper response is waited on for 10 seconds by default');
        self::assertSame(3.0, $config->connectTimeout, 'a connection is waited on for 3 seconds by default');
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function nonPositiveTimeouts(): iterable
    {
        yield 'zero timeout' => [0.0, 3.0];
        yield 'zero connect timeout' => [10.0, 0.0];
        yield 'negative timeout' => [-1.0, 3.0];
    }

    #[DataProvider('nonPositiveTimeouts')]
    public function testNonPositiveTimeoutsThrow(float $timeout, float $connectTimeout): void
    {
        $this->expectException(ConfigurationException::class);

        new GrouperDirectoryConfiguration($this->grouper(), $timeout, $connectTimeout);
    }

    private function grouper(): GrouperConfiguration
    {
        return new GrouperConfiguration('https://grouperws.apps.iu.edu/grouper-ws/servicesRest', 'svc', 'secret');
    }
}

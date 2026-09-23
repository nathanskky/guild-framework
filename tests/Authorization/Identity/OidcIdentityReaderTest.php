<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Identity;

use Guild\Access\Authentication\OIDC\OidcAuthenticationService;
use Guild\Access\Authentication\OIDC\OidcConfiguration;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Identity\OidcIdentityReader;
use Guild\Framework\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * guild/access keeps the login in the PHP session, so each test runs in its
 * own process with a real session.
 */
#[CoversClass(OidcIdentityReader::class)]
#[UsesClass(Identity::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OidcIdentityReaderTest extends TestCase
{
    public function testAnAuthenticatedLoginReadsAsTheUsernameAndClaims(): void
    {
        $this->login(['username' => 'jdoe', 'email' => 'jdoe@iu.edu']);

        $identity = $this->reader()->read();

        self::assertNotNull($identity, 'an unexpired login is a user');
        self::assertSame('jdoe', $identity->username, 'the username claim is the identity');
        self::assertSame('jdoe@iu.edu', $identity->attributes['email'], 'other claims are carried as attributes');
    }

    public function testNoLoginIsAGuest(): void
    {
        session_start();

        self::assertNull($this->reader()->read(), 'nothing in the session means a guest');
    }

    public function testAnExpiredLoginIsAGuestEvenThoughUserinfoRemains(): void
    {
        $this->login(['username' => 'jdoe'], expiresAt: time() - 1);

        self::assertNull($this->reader()->read(), 'an expired login must not read as a user');
    }

    public function testAMissingUsernameClaimThrows(): void
    {
        $this->login(['email' => 'jdoe@iu.edu']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'username' claim");

        $this->reader()->read();
    }

    /**
     * @param  array<string, string>  $claims
     */
    private function login(array $claims, ?int $expiresAt = null): void
    {
        session_start();
        $_SESSION['guild_oidc_user_info'] = (object) $claims;
        $_SESSION['guild_oidc_authenticated_until'] = $expiresAt ?? time() + 3600;
    }

    private function reader(): OidcIdentityReader
    {
        return new OidcIdentityReader(new OidcAuthenticationService(
            new OidcConfiguration('https://idp.login.iu.edu', 'id', 'secret', 'https://app.iu.edu/cb'),
        ));
    }
}

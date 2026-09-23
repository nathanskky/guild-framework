<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Identity;

use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Identity\RemoteUserIdentityReader;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RemoteUserIdentityReader::class)]
#[UsesClass(Identity::class)]
final class RemoteUserIdentityReaderTest extends TestCase
{
    public function testRemoteUserIsTheUsername(): void
    {
        $identity = $this->readerFor(['REMOTE_USER' => 'jdoe'])->read();

        self::assertNotNull($identity, 'a populated REMOTE_USER is a user');
        self::assertSame('jdoe', $identity->username, 'REMOTE_USER is the username');
        self::assertSame([], $identity->attributes, 'CAS provides nothing beyond the username');
    }

    public function testAnAbsentRemoteUserIsAGuest(): void
    {
        self::assertNull($this->readerFor([])->read(), 'no REMOTE_USER means a guest');
    }

    public function testABlankRemoteUserIsAGuest(): void
    {
        self::assertNull($this->readerFor(['REMOTE_USER' => '  '])->read(), 'a blank REMOTE_USER means a guest');
    }

    public function testARemoteUserHeaderIsIgnored(): void
    {
        $request = new ServerRequest(serverParams: [], headers: ['Remote-User' => 'attacker']);

        self::assertNull(new RemoteUserIdentityReader($request)->read(), 'a client-supplied header is never an identity');
    }

    /**
     * @param  array<string, string>  $serverParams
     */
    private function readerFor(array $serverParams): RemoteUserIdentityReader
    {
        return new RemoteUserIdentityReader(new ServerRequest(serverParams: $serverParams));
    }
}

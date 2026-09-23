<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Identity;

use Guild\Access\Authentication\OIDC\OidcAuthenticationService;
use Guild\Framework\Exception\ConfigurationException;

/**
 * Reads the identity guild/access stored in the session at login.
 */
final readonly class OidcIdentityReader implements IdentityReader
{
    /**
     * IU Login releases the IU username as 'username' under both the openid
     * and profile scopes (IU KB0024076). The discovery document's
     * claims_supported omits it; that list is not exhaustive. Do not switch
     * to 'sub', which may be a pairwise identifier.
     */
    private const string USERNAME_CLAIM = 'username';

    public function __construct(private OidcAuthenticationService $authentication)
    {
    }

    public function read(): ?Identity
    {
        // getUserInfo() does not check expiry, so an expired login would
        // otherwise still read as a user.
        if (! $this->authentication->isAuthenticated()) {
            return null;
        }

        $userInfo = $this->authentication->getUserInfo();
        $attributes = [];

        foreach (is_object($userInfo) ? get_object_vars($userInfo) : [] as $claim => $value) {
            $attributes[(string) $claim] = $value;
        }

        $username = $attributes[self::USERNAME_CLAIM] ?? null;

        if (! is_string($username) || trim($username) === '') {
            throw new ConfigurationException(
                "The OIDC userinfo for this login has no '" . self::USERNAME_CLAIM . "' claim. "
                . 'Check that the identity provider releases it to this client.'
            );
        }

        return new Identity(trim($username), $attributes);
    }
}

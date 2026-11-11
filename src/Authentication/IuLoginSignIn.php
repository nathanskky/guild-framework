<?php

declare(strict_types=1);

namespace Guild\Framework\Authentication;

use Guild\Access\Authentication\OIDC\Exception\OidcAuthenticationServiceException;
use Guild\Access\Authentication\OIDC\Exception\OidcProviderErrorException;
use Guild\Access\Authentication\OIDC\OidcAuthenticationService;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Session\SessionStore;
use Jumbojett\OpenIDConnectClientException as OidcClientException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\TextResponse;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Signs people in through IU Login (OIDC), using guild/access.
 *
 * @internal
 */
final readonly class IuLoginSignIn implements SignInMethod
{
    /**
     * IU Login releases the IU username as 'username' under both the openid
     * and profile scopes (IU KB0024076). The discovery document's
     * claims_supported omits it; that list is not exhaustive. Do not switch
     * to 'sub', which may be a pairwise identifier.
     */
    private const string USERNAME_CLAIM = 'username';

    public function __construct(
        private OidcAuthenticationService $authentication,
        private SessionStore $session,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
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

    #[Override]
    public function requireSignIn(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $response = $this->guard($request);

        if ($response !== null) {
            return $response;
        }

        // Outside the try in guard(): a failure in the handler is the
        // application's, not a sign-in failure.
        return $next->handle($request);
    }

    #[Override]
    public function challenge(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guard($request) ?? new EmptyResponse(403);
    }

    #[Override]
    public function signOut(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->authentication->logoutResponse();
        $this->session->regenerateId();

        return $response;
    }

    /**
     * The sign-in redirect, an error response, or null when already signed in.
     * Failures map as guild/access's OidcAuthenticationMiddleware maps them.
     */
    private function guard(ServerRequestInterface $request): ?ResponseInterface
    {
        try {
            return $this->authentication->guard($request);
        } catch (OidcProviderErrorException) {
            // The detail (attacker-controllable error/error_description) is
            // logged by the service; the body stays generic so it is not reflected.
            return new TextResponse('OIDC login failed.', 400);
        } catch (OidcAuthenticationServiceException | OidcClientException $exception) {
            $this->logger->warning('OIDC authentication failed', ['exception' => $exception]);

            return new EmptyResponse(401);
        } catch (Throwable $exception) {
            // Not an authentication failure but a real fault (misconfiguration,
            // network, bug): log it and let it propagate rather than mask it as a 401.
            $this->logger->error('Unexpected error during OIDC authentication', ['exception' => $exception]);

            throw $exception;
        }
    }
}

# Add sign-in

> For app developers requiring IU Login sign-in on pages of a Guild app, and adding a sign-out button.

This guide shows you how to turn on IU Login, require sign-in on routes, read who signed in, and add a
sign-out button.

## Before you start

- Your app has an OIDC client registered with IU Login: a client ID, a client secret and an `https` redirect URI.
- The client's values are in environment variables, never in a committed file.

If your app runs behind Apache `mod_auth_cas` instead, Apache signs people in before PHP runs; skip this
guide and use `IdentitySource::Cas` in [Set up authorization](set-up-authorization.md).

## Turn on IU Login

1. Create `config/authentication.php`, returning guild/access's `OidcConfiguration`:

   ```php
   use Guild\Access\Authentication\OIDC\OidcConfiguration;

   return new OidcConfiguration(
       providerUrl: $_ENV['OIDC_ISSUER'],
       clientId: $_ENV['OIDC_CLIENT_ID'],
       clientSecret: $_ENV['OIDC_CLIENT_SECRET'],
       redirectUri: $_ENV['OIDC_REDIRECT_URI'],
       scopes: ['profile', 'email'],
   );
   ```

2. Call `addAuthentication()` in `config/app.php`.

A missing or invalid file stops the app at startup with `ConfigurationException`. `OidcConfiguration`'s
docblock lists every field; [how sign-in works](https://github.com/nathanskky/guild-access/blob/main/docs/concepts/how-sign-in-works.md)
in guild/access explains the sessions and redirect rules.

## Require sign-in on routes

Add `RequireSignIn` to every route or group that needs a signed-in user, and to the route at your
redirect URI:

```php
use Guild\Framework\Middleware\RequireSignIn;

$router->group('/reports', function ($group): void {
    $group->map('GET', '/', [ReportController::class, 'index']);
})->lazyMiddleware(RequireSignIn::class);
```

`RequireSignIn` uses whichever sign-in method is in effect, so the same route works with persona sign-in
from guild/dev-tools. Don't add guild/access's `OidcAuthenticationMiddleware` yourself; it always uses
IU Login, and it isn't in the container when an extension chose the sign-in method.

To protect pages by permission instead of only by sign-in, follow
[Check permissions](check-permissions.md). A guest who fails a check is asked to sign in.

## Read who signed in

With `addAuthorization()`, ask `UserResolver` for the current user:

```php
$user = $this->users->current();   // a User, or null for a guest

$user?->username();
$user?->attribute('email');        // an IU Login claim, or null
```

## Add a sign-out button

`addAuthentication()` mounts `POST /framework/sign-out`. To show a sign-out button, call
`sign_out_form()` in a template:

```twig
{{ sign_out_form() }}
```

```latte
{sign_out_form()}
```

The function needs a template engine, set up before or after `addAuthentication()`. The form carries the
session's CSRF token, so a link or a `GET` request can't sign anyone out.

After IU Login sign-out, the user lands on IU Login's signed-out page, or on `defaultReturnUrl` when
there was no IU Login session. Signing out also replaces the session's CSRF token, so a form issued
before sign-out is refused afterward.

## Related

- [Set up authorization](set-up-authorization.md) adds permissions on top of sign-in.
- [Template functions](../reference/template-functions.md) lists `sign_out_form()` with the other functions.

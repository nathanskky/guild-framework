# Upgrading

> For app developers moving a Guild app to a newer guild/framework release.

Each section lists what a release changes for an app, newest first. To upgrade, raise the
`guild/framework` constraint if needed and run `composer update guild/framework`. Then run the
framework's migrations, as in [Set up authorization](guides/set-up-authorization.md#turn-on-authorization),
because a release can add one.

## 2.1.5

Nothing needs action. An application built with `composer dump-autoload --no-dev` over a development install
boots without its `require-dev` extensions, as one built with `composer install --no-dev` does. In 2.1.4 and
earlier it failed at boot, naming the extension's class as missing.

## 2.1.4

Nothing needs action. A form refused by `VerifyCsrfToken`, sign-out included, gets the "Form expired" status
page: the application's layout with `addRivet()`, plain HTML without it. Without Rivet it used to carry an
"Administration" header linking to `/framework/authorization`, even in an application with no administration
pages.

## 2.1.3

Nothing needs action. A signed-in visitor whose request throws `AuthenticationRequiredException` gets the
403 "Not authorized" page instead of the sign-in method's challenge, because signing in again cannot help
them. `Gate` throws that exception only for a guest, so this changes nothing unless application code throws
it itself. A custom `SignInMethod::challenge()` is only ever called for a guest.

## 2.1.2

Nothing needs action. 2.1.2 fixes 2.1.1, where a request no route answers failed with a `TypeError` in an
application with router middleware registered by class name.

## 2.1.1

A request no route answers is a 404 or 405 page instead of an uncaught `League\Route\Http\Exception\NotFoundException`
or `MethodNotAllowedException`. Code that caught those exceptions around `Router::dispatch()` no longer sees them.

## 2.1

Run the framework's migrations (step 5 of [Set up authorization](guides/set-up-authorization.md#turn-on-authorization)): 2.1 widens the `created_by` and
`updated_by` columns to 64 characters. Nothing else needs action.

## 2.0

Changes that need action or can break code written against 1.x:

- Grouper settings move from `config/authorization.php` to a new `config/grouper.php`
- `OidcIdentityReader` is removed; use `SignInMethod`
- The `AuthenticationServiceProvider` and `AuthorizationServiceProvider` constructors changed (internal wiring)
- `AuthorizationConfiguration::$systemAdminGroup` is `?string`
- Under OIDC the administration pages use `RequireSignIn`
- `addAuthorization()` answers authorization failures for every route
- After upgrading, sign in and sign out once where the redirect URI is registered with IU Login

The Grouper move is the one every application with `addAuthorization()` hits: `AuthorizationConfiguration`
no longer takes a `GrouperConfiguration` or Grouper timeouts. Return them from `config/grouper.php` as a
`GrouperDirectoryConfiguration` instead (step 5 of [Set up authorization](guides/set-up-authorization.md#turn-on-authorization)); the error at boot says the
same. Sign-in and sign-out against IU Login are not covered by automated tests, so confirm them once in an
environment whose redirect URI is registered.

# Failure responses

> For app developers and support staff identifying a status page a Guild app showed.

With `addRivet()`, these pages use the app's `rvt_page` layout; without it, they're plain HTML. Router-level
middleware runs around each of them.

| Status | Title | When |
|---|---|---|
| 403 | Not authorized | A signed-in user failed a permission check, or isn't in the System Admin group on an admin page |
| Sign-in challenge | — | A guest failed a permission check, or opened an admin page, under IU Login; the sign-in method decides the response |
| 403 | Sign in required | A guest failed a check under CAS, where the web server must require sign-in |
| 503 | Temporarily unavailable | Grouper is unreachable and no cached membership is recent enough, or the System Admin check couldn't use fresh membership |
| 403 | Form expired | A form arrived without the session's CSRF token, such as one issued before sign-out |
| 404 | Page not found | No route matches the path |
| 405 | Method not allowed | A route matches the path but not the method; the response has an `Allow` header |

The authorization pages come from `AuthorizationFailureResponder`, which `addAuthorization()` adds at
router level, so they answer for your routes as well as the framework's. It handles three exceptions:

| Exception | Response |
|---|---|
| `AuthorizationException` | 403 Not authorized |
| `AuthenticationRequiredException` | The sign-in challenge for a guest, 403 Sign in required under CAS, and 403 Not authorized for a user who is already signed in |
| `AuthorizationUnavailableException` | 503 Temporarily unavailable |

`Gate::authorize()` throws `AuthenticationRequiredException` for a guest and `AuthorizationException` for
a signed-in user. Any other exception passes through to your error handling.

A `View::render()` call rethrows `AuthorizationUnavailableException` raised inside a template as itself,
so a `can()` call in a template still produces the 503. Rendering through Twig's `Environment` directly
wraps it instead.

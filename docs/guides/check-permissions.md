# Check permissions

> For app developers allowing or refusing an action in a controller or a template.

This guide shows you how to check a permission in PHP and in a template, and how to show a page only to
users who hold a permission.

## Before you start

- Authorization is set up, as in [Set up authorization](set-up-authorization.md).
- For a check about one resource, such as one report, a policy for its class exists; [Write a policy](write-a-policy.md) shows how.

## Refuse a request

To refuse a request the user isn't allowed to make, inject `Gate` and call `authorize()`:

```php
use Guild\Framework\Authorization\Gate;

final readonly class ReportController
{
    public function __construct(private Gate $gate, private View $view)
    {
    }

    public function index(): HtmlResponse
    {
        $this->gate->authorize(AppPermission::ReportsView);

        return new HtmlResponse($this->view->render('reports/index.html.twig'));
    }
}
```

`authorize()` throws when the check fails, and the framework turns the exception into a page: a guest is
asked to sign in, and a signed-in user gets a 403. If Grouper can't be reached and no recent membership
is cached, the user gets a 503. [Failure responses](../reference/failure-responses.md) lists each page.

## Check without refusing

To branch on a permission, call `allows()` or `denies()`, which return a `bool`:

```php
if ($this->gate->allows(AppPermission::ReportsEdit, $report)) {
    // show the edit form
}
```

Pass a resource as the second argument to ask about that one resource. The user's roles must grant the
permission, and the resource's policy must agree. Without a resource, the roles alone decide.

## Check in a template

Call `can()` or `cannot()`, naming the permission by its value:

```twig
{% if can('reports.edit', report) %}<a href="/reports/{{ report.id }}/edit">Edit</a>{% endif %}
```

```latte
{if can('reports.edit', $report)}<a href="/reports/{$report->id}/edit">Edit</a>{/if}
```

A value that isn't a case of your permission enum throws `ConfigurationException` when the template
renders. Hiding a link doesn't protect the page it points to, so the controller still calls `authorize()`.

## Read the user's roles and groups

To read more than one permission, use the `User` from `UserResolver::current()`, which is `null` for a
guest. `User` documents `roles()`, `permissions()`, `groups()`, `inGroup()` and `isSystemAdmin()` in its
docblocks. Each can throw `AuthorizationUnavailableException` while Grouper is down.

## Related

- [Authorization](../concepts/authorization.md) explains how a check is decided.
- [Template functions](../reference/template-functions.md) lists `can()`, `cannot()` and `current_user()`.

# guild/framework docs

> For app developers building a Guild app, and package authors writing extensions.

The [glossary](reference/glossary.md) defines permission, role, grant, policy and the other terms these
pages use. To create and run a new app, start with
[guild/starter's docs](https://github.com/nathanskky/guild-starter/blob/main/docs/index.md).

## Concepts

- [How a request is handled](concepts/how-a-request-is-handled.md) — read when you need to know how the app starts, what each `add*()` call does, and where status pages come from.
- [Authorization](concepts/authorization.md) — read when you need to know how a permission check is decided, or why a Grouper outage gives a 503.
- [Extensions](concepts/extensions.md) — read when an installed package changes how your app signs in or finds groups.

## Guides

- [Add sign-in](guides/add-sign-in.md) — read when requiring IU Login on pages, or adding a sign-out button.
- [Set up authorization](guides/set-up-authorization.md) — read when turning on permissions, Grouper and the System Admin group.
- [Check permissions](guides/check-permissions.md) — read when allowing or refusing an action in a controller or template.
- [Write a policy](guides/write-a-policy.md) — read when a permission depends on which resource the user acts on.
- [Manage groups and roles](guides/manage-groups-and-roles.md) — read when granting access in the administration pages.
- [Write an extension](guides/write-an-extension.md) — read when building a package that replaces sign-in, groups or the System Admin group.

## Reference

- [Config files](reference/config-files.md) — read when checking which file a builder call reads and what it returns.
- [Failure responses](reference/failure-responses.md) — read when identifying a 403, 404, 405 or 503 page.
- [Template functions](reference/template-functions.md) — read when using `can()`, `current_user()` or `sign_out_form()`.
- [Glossary](reference/glossary.md) — read when a framework term is new to you.
- [Upgrading](upgrading.md) — read when moving to a newer framework release.

`ApplicationBuilder`, `Gate`, `User` and the configuration classes document their methods and arguments
in docblocks, which your IDE shows on hover.

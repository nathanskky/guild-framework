# guild/framework

The application kernel for Guild apps: IU web apps in PHP. It wires routing, Eloquent, Twig or Latte,
IU Rivet components, IU Login sign-in and Grouper-based authorization into one container, and each app
turns on only what it needs.

The quickest way to a running app is [guild/starter](https://github.com/nathanskky/guild-starter), which
is built on this package.

## Install

guild/framework requires PHP 8.5. Composer reads `repositories` only from your app's own
`composer.json`, so add the repositories of the Guild packages the framework requires as well:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-framework.git" },
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-access.git" },
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-grouper.git" },
        { "type": "vcs", "url": "https://github.com/nathanskky/guild-rivet.git" }
    ]
}
```

Then install it:

```bash
composer require guild/framework:^2.1
```

## Start here

[How a request is handled](docs/concepts/how-a-request-is-handled.md) explains how a Guild app starts
and what each builder call adds. [Set up authorization](docs/guides/set-up-authorization.md) turns on
permissions and Grouper groups.

## All docs

The [docs index](docs/index.md) lists every guide, concept and reference page, and
[Upgrading](docs/upgrading.md) lists what each release changes.

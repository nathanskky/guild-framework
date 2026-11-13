# Write a policy

> For app developers limiting a permission to some instances of a resource, such as a user's own reports.

This guide shows you how to write a policy, register it and check it.

## Before you start

- Authorization is set up, as in [Set up authorization](set-up-authorization.md).
- The permission the policy decides is a case of your permission enum.

## Write the policy

1. Create a class that names its resource class with `#[HandlesResource]`.
2. Add one public method per permission, marked `#[Handles]`. It takes the user and the resource and returns `bool`:

   ```php
   use Guild\Framework\Authorization\Handles;
   use Guild\Framework\Authorization\HandlesResource;
   use Guild\Framework\Authorization\User;

   #[HandlesResource(Report::class)]
   final class ReportPolicy
   {
       #[Handles(AppPermission::ReportsEdit)]
       public function edit(User $user, Report $report): bool
       {
           return $report->owner === $user->username();
       }

       #[Handles(AppPermission::ReportsView)]
       public function view(?User $user, Report $report): bool
       {
           return $report->public || $report->owner === $user?->username();
       }
   }
   ```

3. List the class in `addAuthorization()`'s `policies:` argument.

## Decide about guests

A method whose user parameter is `User` is never asked about a guest; the guest is denied. To let a guest
through for some resources, type the parameter `?User`. The framework asks that method about a guest even
though a guest holds no grants, so `view()` above lets anyone see a public report.

## Rules the framework checks

The framework reads a policy the first time a check uses it. A mistake throws `ConfigurationException`
for every user, with a message naming the fix:

- No policy lists the resource's exact class; a subclass doesn't match its parent's policy.
- The policy has no method for the permission being checked.
- Two policies handle the same class, or two methods the same permission.
- A method handles a case from another enum.
- A method's first parameter isn't `User` or `?User`, or it doesn't return `bool`.
- A listed class has no `#[HandlesResource]`.

The container builds each policy, so its constructor can ask for services. Those services need
`enableAutoWiring()` or a binding.

## Check it

Pass the resource to a check, and the policy runs after the user's roles grant the permission:

```php
$this->gate->authorize(AppPermission::ReportsEdit, $report);
```

A member of the System Admin group skips both the grant and the policy.

## Related

- [Check permissions](check-permissions.md) shows every way to run a check.
- [Authorization](../concepts/authorization.md) shows where the policy sits in a decision.

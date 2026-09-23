<?php

declare(strict_types=1);

namespace Guild\Framework\Authorization\Policy;

use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\Permission;
use Guild\Framework\Authorization\User;
use Guild\Framework\Exception\ConfigurationException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Finds the policy method that decides a permission for a resource.
 *
 * Nothing is read at startup. The first check with a resource reads
 * #[HandlesResource] from every listed policy; the first check against a
 * given policy reads and validates all of its #[Handles] methods. Both are
 * remembered for the rest of the request, as are policy instances.
 *
 * Every mistake in a policy's declaration throws ConfigurationException when
 * the policy is first used, whichever user is asking.
 *
 * @internal
 */
final class PolicyRegistry
{
    /**
     * @var array<string, class-string>|null resource class => policy class
     */
    private ?array $resources = null;

    /**
     * @var array<class-string, array<string, ReflectionMethod>> policy class => permission value => method
     */
    private array $methods = [];

    /**
     * @var array<class-string, object>
     */
    private array $instances = [];

    /**
     * @param  list<class-string>  $policies
     * @param  class-string<Permission>  $permissionEnum
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $policies,
        private readonly string $permissionEnum,
    ) {
    }

    /**
     * @throws ConfigurationException when no policy method decides $permission for $resource
     */
    public function methodFor(object $resource, Permission $permission): PolicyMethod
    {
        $resourceClass = $resource::class;
        $policyClass = $this->resources()[$resourceClass] ?? null;

        if ($policyClass === null) {
            throw new ConfigurationException(sprintf(
                'No policy is registered for %s. List a policy declaring #[HandlesResource(%s::class)] in addAuthorization().',
                $resourceClass,
                $resourceClass,
            ));
        }

        $method = $this->methods($policyClass)[(string) $permission->value] ?? null;

        if ($method === null) {
            throw new ConfigurationException(sprintf(
                '%s has no method handling %s::%s for %s. Add one with #[Handles(%s::%s)].',
                $policyClass,
                $permission::class,
                $permission->name,
                $resourceClass,
                $permission::class,
                $permission->name,
            ));
        }

        return new PolicyMethod($this->instance($policyClass), $method, $this->firstParameterAllowsNull($method));
    }

    /**
     * @return array<string, class-string>
     */
    private function resources(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }

        $resources = [];

        foreach ($this->policies as $policyClass) {
            $attributes = $this->reflect($policyClass)->getAttributes(HandlesResource::class);

            if ($attributes === []) {
                throw new ConfigurationException(
                    "{$policyClass} is listed as a policy but has no #[HandlesResource] attribute."
                );
            }

            $resource = $attributes[0]->newInstance()->resource;

            if (isset($resources[$resource])) {
                throw new ConfigurationException(sprintf(
                    'Both %s and %s declare #[HandlesResource(%s::class)]. A resource has one policy.',
                    $resources[$resource],
                    $policyClass,
                    $resource,
                ));
            }

            $resources[$resource] = $policyClass;
        }

        return $this->resources = $resources;
    }

    /**
     * @param  class-string  $policyClass
     * @return array<string, ReflectionMethod>
     */
    private function methods(string $policyClass): array
    {
        if (isset($this->methods[$policyClass])) {
            return $this->methods[$policyClass];
        }

        $methods = [];

        foreach ($this->reflect($policyClass)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Handles::class) as $attribute) {
                $permission = $attribute->newInstance()->permission;
                $this->validate($policyClass, $method, $permission);

                $key = (string) $permission->value;

                if (isset($methods[$key])) {
                    throw new ConfigurationException(sprintf(
                        '%s::%s() and %s::%s() both handle %s::%s. A permission has one method per policy.',
                        $policyClass,
                        $methods[$key]->getName(),
                        $policyClass,
                        $method->getName(),
                        $permission::class,
                        $permission->name,
                    ));
                }

                $methods[$key] = $method;
            }
        }

        return $this->methods[$policyClass] = $methods;
    }

    private function validate(string $policyClass, ReflectionMethod $method, Permission $permission): void
    {
        $name = "{$policyClass}::{$method->getName()}()";

        if (! $permission instanceof $this->permissionEnum) {
            throw new ConfigurationException(sprintf(
                '%s handles %s::%s, which is not a case of the registered permission enum %s.',
                $name,
                $permission::class,
                $permission->name,
                $this->permissionEnum,
            ));
        }

        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType || $returnType->getName() !== 'bool' || $returnType->allowsNull()) {
            throw new ConfigurationException("{$name} must declare a bool return type.");
        }

        $parameters = $method->getParameters();
        $userType = ($parameters[0] ?? null)?->getType();

        if (
            count($parameters) < 2
            || $method->getNumberOfRequiredParameters() > 2
            || ! $userType instanceof ReflectionNamedType
            || $userType->getName() !== User::class
        ) {
            throw new ConfigurationException(
                "{$name} must take the acting user (User, or ?User to allow guests) and then the resource."
            );
        }
    }

    private function firstParameterAllowsNull(ReflectionMethod $method): bool
    {
        return $method->getParameters()[0]->allowsNull();
    }

    /**
     * @param  class-string  $policyClass
     */
    private function instance(string $policyClass): object
    {
        if (isset($this->instances[$policyClass])) {
            return $this->instances[$policyClass];
        }

        $instance = $this->container->get($policyClass);

        if (! $instance instanceof $policyClass) {
            throw new ConfigurationException("The container did not return an instance of {$policyClass}.");
        }

        return $this->instances[$policyClass] = $instance;
    }

    /**
     * @param  class-string  $policyClass
     * @return ReflectionClass<object>
     */
    private function reflect(string $policyClass): ReflectionClass
    {
        if (! class_exists($policyClass)) {
            throw new ConfigurationException("{$policyClass} is listed as a policy but no such class exists.");
        }

        return new ReflectionClass($policyClass);
    }
}

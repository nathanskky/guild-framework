<?php

declare(strict_types=1);

namespace Guild\Framework\Test\Authorization\Policy;

use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\Policy\PolicyMethod;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Exception\ConfigurationException;
use Guild\Framework\Test\Authorization\Support\Policy\BadSignaturePolicy;
use Guild\Framework\Test\Authorization\Support\Policy\Document;
use Guild\Framework\Test\Authorization\Support\Policy\DocumentPolicy;
use Guild\Framework\Test\Authorization\Support\Policy\DuplicateMethodPolicy;
use Guild\Framework\Test\Authorization\Support\Policy\Invoice;
use Guild\Framework\Test\Authorization\Support\Policy\NoAttributePolicy;
use Guild\Framework\Test\Authorization\Support\Policy\NonBoolPolicy;
use Guild\Framework\Test\Authorization\Support\Policy\SecondDocumentPolicy;
use Guild\Framework\Test\Authorization\Support\Policy\WrongEnumPolicy;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use League\Container\Container;
use League\Container\ReflectionContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PolicyRegistry::class)]
#[CoversClass(PolicyMethod::class)]
#[UsesClass(Handles::class)]
#[UsesClass(HandlesResource::class)]
final class PolicyRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        DocumentPolicy::$constructed = 0;
    }

    public function testAHandlerIsFoundForTheResourceAndPermission(): void
    {
        $registry = $this->registry([DocumentPolicy::class]);

        $update = $registry->methodFor(new Document('jdoe'), TestPermission::DocumentsUpdate);
        $view = $registry->methodFor(new Document('jdoe'), TestPermission::DocumentsView);

        self::assertFalse($update->allowsGuests, 'a User parameter excludes guests');
        self::assertTrue($view->allowsGuests, 'a ?User parameter opts into guests');
        self::assertTrue($view->decide(null, new Document('jdoe', public: true)), 'the method decides');
    }

    public function testAPolicyIsInstantiatedOncePerRequest(): void
    {
        $registry = $this->registry([DocumentPolicy::class]);

        $registry->methodFor(new Document('jdoe'), TestPermission::DocumentsUpdate);
        $registry->methodFor(new Document('asmith'), TestPermission::DocumentsView);

        self::assertSame(1, DocumentPolicy::$constructed, 'the instance is reused');
    }

    public function testNothingIsReadBeforeTheFirstCheck(): void
    {
        $registry = $this->registry(['Guild\\Framework\\Test\\DoesNotExist']);

        self::assertInstanceOf(PolicyRegistry::class, $registry, 'a bad listing is not detected, or loaded, until first use');
    }

    /**
     * @return iterable<string, array{list<class-string>, object, TestPermission, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'no policy for the resource' => [[DocumentPolicy::class], new Invoice(), TestPermission::InvoicesApprove, 'No policy is registered for'];
        yield 'no method for the permission' => [[DocumentPolicy::class], new Document('jdoe'), TestPermission::DocumentsCreate, 'has no method handling'];
        yield 'listed class without the attribute' => [[NoAttributePolicy::class], new Document('jdoe'), TestPermission::DocumentsUpdate, 'has no #[HandlesResource]'];
        yield 'two policies for one resource' => [[DocumentPolicy::class, SecondDocumentPolicy::class], new Document('jdoe'), TestPermission::DocumentsUpdate, 'A resource has one policy'];
        yield 'two methods for one permission' => [[DuplicateMethodPolicy::class], new Invoice(), TestPermission::InvoicesApprove, 'A permission has one method per policy'];
        yield 'non-bool return type' => [[NonBoolPolicy::class], new Invoice(), TestPermission::InvoicesApprove, 'must declare a bool return type'];
        yield 'a case of another enum' => [[WrongEnumPolicy::class], new Invoice(), TestPermission::InvoicesApprove, 'not a case of the registered permission enum'];
        yield 'no user parameter' => [[BadSignaturePolicy::class], new Invoice(), TestPermission::InvoicesApprove, 'must take the acting user'];
        yield 'a class that does not exist' => [['Guild\\Framework\\Test\\DoesNotExist'], new Document('jdoe'), TestPermission::DocumentsUpdate, 'no such class exists'];
    }

    /**
     * @param  list<class-string>  $policies
     */
    #[DataProvider('mistakes')]
    public function testPolicyMistakesThrow(array $policies, object $resource, TestPermission $permission, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->registry($policies)->methodFor($resource, $permission);
    }

    /**
     * @param  list<class-string>  $policies
     */
    private function registry(array $policies): PolicyRegistry
    {
        $container = new Container();
        $container->delegate(new ReflectionContainer());

        return new PolicyRegistry($container, $policies, TestPermission::class);
    }
}

<?php

declare(strict_types=1);

namespace Guild\Framework\Test;

use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Membership\Session;
use Guild\Framework\Authorization\Policy\PolicyMethod;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\TemplateAuthorization;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Latte\AuthorizationExtension as LatteAuthorizationExtension;
use Guild\Framework\Test\Authorization\Support\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GateFactory;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\Policy\Document;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Framework\Twig\AuthorizationExtension as TwigAuthorizationExtension;
use Guild\Framework\View;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The same checks, written the same way, in both engines.
 */
#[CoversClass(TwigAuthorizationExtension::class)]
#[CoversClass(LatteAuthorizationExtension::class)]
#[CoversClass(View::class)]
#[UsesClass(TemplateAuthorization::class)]
#[UsesClass(Gate::class)]
#[UsesClass(UserResolver::class)]
#[UsesClass(User::class)]
#[UsesClass(Identity::class)]
#[UsesClass(PolicyRegistry::class)]
#[UsesClass(PolicyMethod::class)]
#[UsesClass(Handles::class)]
#[UsesClass(HandlesResource::class)]
#[UsesClass(MembershipProvider::class)]
#[UsesClass(MembershipCache::class)]
#[UsesClass(Session::class)]
#[UsesClass(GrantRepository::class)]
final class TemplateFunctionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function engines(): iterable
    {
        yield 'Twig' => [
            'twig',
            "{{ can('documents.view', doc) ? 'Y' : 'N' }}"
            . "{{ can(enum('Guild\\\\Framework\\\\Test\\\\Authorization\\\\Support\\\\TestPermission').DocumentsView, doc) ? 'Y' : 'N' }}"
            . "{{ cannot('documents.update') ? 'Y' : 'N' }}",
        ];
        yield 'Latte' => [
            'latte',
            "{can('documents.view', \$doc) ? 'Y' : 'N'}"
            . "{can(Guild\\Framework\\Test\\Authorization\\Support\\TestPermission::DocumentsView, \$doc) ? 'Y' : 'N'}"
            . "{cannot('documents.update') ? 'Y' : 'N'}",
        ];
    }

    #[DataProvider('engines')]
    public function testCanAndCannotRenderInBothEngines(string $engine, string $template): void
    {
        $view = $this->view($engine, $template, GateFactory::guest());

        self::assertSame('YYY', $view->render('t', ['doc' => new Document('jdoe', public: true)]), "{$engine} renders the checks");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unavailableTemplates(): iterable
    {
        yield 'Twig' => ['twig', "{{ can('documents.update') ? 'Y' : 'N' }}"];
        yield 'Latte' => ['latte', "{can('documents.update') ? 'Y' : 'N'}"];
    }

    #[DataProvider('unavailableTemplates')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUnavailableMembershipEscapesRenderingUnwrapped(string $engine, string $template): void
    {
        $gate = GateFactory::make(new FakeGrouper([FakeGrouper::unavailable()]), new Identity('jdoe'), new GrantsDatabase());

        $this->expectException(AuthorizationUnavailableException::class);

        $this->view($engine, $template, $gate)->render('t');
    }

    private function view(string $engine, string $template, Gate $gate): View
    {
        $authorization = new TemplateAuthorization(static fn (): Gate => $gate, TestPermission::class);

        if ($engine === 'twig') {
            $twig = new Environment(new ArrayLoader(['t' => $template]));
            $twig->addExtension(new TwigAuthorizationExtension($authorization));

            return new View($twig);
        }

        $latte = new Engine();
        $latte->setLoader(new StringLoader(['t' => $template]));
        $latte->setTempDirectory(null);
        $latte->addExtension(new LatteAuthorizationExtension($authorization));

        return new View($latte);
    }
}

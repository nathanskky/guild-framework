<?php

declare(strict_types=1);

namespace Guild\Framework\Test;

use Guild\Framework\Authorization\Membership\GrouperGroupDirectory;
use Guild\Framework\Testing\ArraySessionStore;
use Guild\Framework\Admin\Csrf;
use Guild\Framework\Application;
use Guild\Framework\ApplicationBuilder;
use Guild\Framework\Authentication\SignOutForm;
use Guild\Framework\Authorization\Gate;
use Guild\Framework\Authorization\GrantRepository;
use Guild\Framework\Authorization\Handles;
use Guild\Framework\Authorization\HandlesResource;
use Guild\Framework\Authorization\Identity\Identity;
use Guild\Framework\Authorization\Membership\MembershipCache;
use Guild\Framework\Authorization\Membership\MembershipProvider;
use Guild\Framework\Authorization\Policy\PolicyMethod;
use Guild\Framework\Authorization\Policy\PolicyRegistry;
use Guild\Framework\Authorization\TemplateAuthorization;
use Guild\Framework\Authorization\User;
use Guild\Framework\Authorization\UserResolver;
use Guild\Framework\Exception\AuthorizationUnavailableException;
use Guild\Framework\Latte\AuthenticationExtension as LatteAuthenticationExtension;
use Guild\Framework\Latte\AuthorizationExtension as LatteAuthorizationExtension;
use Guild\Framework\ServiceProvider\AuthenticationServiceProvider;
use Guild\Framework\ServiceProvider\AuthenticationTemplateServiceProvider;
use Guild\Framework\ServiceProvider\FrameworkPageServiceProvider;
use Guild\Framework\ServiceProvider\ViewServiceProvider;
use Guild\Framework\Session\SessionStore;
use Guild\Framework\TemplateEngine;
use Guild\Grouper\Testing\FakeGrouper;
use Guild\Framework\Test\Authorization\Support\GateFactory;
use Guild\Framework\Test\Authorization\Support\GrantsDatabase;
use Guild\Framework\Test\Authorization\Support\Policy\Document;
use Guild\Framework\Test\Authorization\Support\TestPermission;
use Guild\Framework\Twig\AuthenticationExtension as TwigAuthenticationExtension;
use Guild\Framework\Twig\AuthorizationExtension as TwigAuthorizationExtension;
use Guild\Framework\View;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The same checks, written the same way, in both engines.
 */
#[CoversClass(TwigAuthorizationExtension::class)]
#[CoversClass(LatteAuthorizationExtension::class)]
#[CoversClass(TwigAuthenticationExtension::class)]
#[CoversClass(LatteAuthenticationExtension::class)]
#[CoversClass(SignOutForm::class)]
#[CoversClass(AuthenticationTemplateServiceProvider::class)]
#[CoversClass(View::class)]
#[UsesClass(Application::class)]
#[UsesClass(ApplicationBuilder::class)]
#[UsesClass(AuthenticationServiceProvider::class)]
#[UsesClass(FrameworkPageServiceProvider::class)]
#[UsesClass(ViewServiceProvider::class)]
#[UsesClass(Csrf::class)]
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
#[UsesClass(ArraySessionStore::class)]
#[UsesClass(GrantRepository::class)]
#[UsesClass(GrouperGroupDirectory::class)]
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
     * @return iterable<string, array{string, string, string}> engine, signed-in template, guest template
     */
    public static function currentUserTemplates(): iterable
    {
        yield 'Twig' => ['twig', "{{ current_user().username }}", "{{ current_user() is null ? 'guest' : 'user' }}"];
        yield 'Latte' => ['latte', '{current_user()->username()}', "{current_user() === null ? 'guest' : 'user'}"];
    }

    #[DataProvider('currentUserTemplates')]
    public function testCurrentUserRendersTheSignedInUserInBothEngines(string $engine, string $signedIn, string $guest): void
    {
        $jdoe = new Identity('jdoe');
        $gate = GateFactory::make(new FakeGrouper([]), $jdoe, new GrantsDatabase());
        $users = GateFactory::resolver(new FakeGrouper([]), $jdoe, new GrantsDatabase());

        self::assertSame('jdoe', $this->view($engine, $signedIn, $gate, $users)->render('t'), "{$engine} renders the username");
    }

    #[DataProvider('currentUserTemplates')]
    public function testCurrentUserIsNullForAGuestInBothEngines(string $engine, string $signedIn, string $guest): void
    {
        $view = $this->view($engine, $guest, GateFactory::guest());

        self::assertSame('guest', $view->render('t'), "{$engine} sees no user");
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
    public function testUnavailableMembershipEscapesRenderingUnwrapped(string $engine, string $template): void
    {
        $gate = GateFactory::make(new FakeGrouper([FakeGrouper::unavailable()]), new Identity('jdoe'), new GrantsDatabase());

        $this->expectException(AuthorizationUnavailableException::class);

        $this->view($engine, $template, $gate)->render('t');
    }

    /**
     * @return iterable<string, array{TemplateEngine, string, bool}>
     */
    public static function signOutForms(): iterable
    {
        yield 'Twig, authentication first' => [TemplateEngine::Twig, 'form.twig', true];
        yield 'Twig, engine first' => [TemplateEngine::Twig, 'form.twig', false];
        yield 'Latte, authentication first' => [TemplateEngine::Latte, 'form.latte', true];
        yield 'Latte, engine first' => [TemplateEngine::Latte, 'form.latte', false];
    }

    #[DataProvider('signOutForms')]
    public function testSignOutFormRendersTheProtectedFormUnescaped(TemplateEngine $engine, string $template, bool $authenticationFirst): void
    {
        $basePath = sys_get_temp_dir() . '/guild-framework-sign-out-form-' . bin2hex(random_bytes(6));
        mkdir($basePath . '/config', recursive: true);
        mkdir($basePath . '/templates');
        file_put_contents($basePath . '/config/authentication.php', <<<'PHP'
            <?php

            return new Guild\Access\Authentication\OIDC\OidcConfiguration(
                'https://idp.login.iu.edu', 'id', 'secret', 'https://app.iu.edu/cb',
            );
            PHP);
        file_put_contents($basePath . '/templates/form.twig', '{{ sign_out_form() }}');
        file_put_contents($basePath . '/templates/form.latte', '{sign_out_form()}');

        try {
            $builder = Application::configure($basePath);

            $builder = $authenticationFirst
                ? $builder->addAuthentication()->addTemplateEngine($engine)
                : $builder->addTemplateEngine($engine)->addAuthentication();

            $app = $builder->create();
            $app->addShared(SessionStore::class, new ArraySessionStore(['guild_framework_csrf' => 'abc123']), overwrite: true);

            $view = $app->get(View::class);
            self::assertInstanceOf(View::class, $view);

            self::assertSame(
                '<form method="post" action="/framework/sign-out"><input type="hidden" name="_csrf" value="abc123">'
                . '<button type="submit" class="rvt-button rvt-button--secondary">Sign out</button></form>',
                $view->render($template),
                "{$engine->name} renders the form with the session's token, as markup",
            );
        } finally {
            foreach ([...(glob($basePath . '/config/*') ?: []), ...(glob($basePath . '/templates/*') ?: [])] as $file) {
                unlink($file);
            }

            rmdir($basePath . '/config');
            rmdir($basePath . '/templates');
            rmdir($basePath);
        }
    }

    private function view(string $engine, string $template, Gate $gate, ?UserResolver $users = null): View
    {
        $users ??= GateFactory::resolver(new FakeGrouper([]), null, new GrantsDatabase());
        $authorization = new TemplateAuthorization(
            static fn (): Gate => $gate,
            static fn (): UserResolver => $users,
            TestPermission::class,
        );

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

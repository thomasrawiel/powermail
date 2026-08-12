<?php

declare(strict_types=1);

namespace In2code\Powermail\Tests\Functional\Middleware;

use In2code\Powermail\Controller\ModuleController;
use In2code\Powermail\Utility\BackendUtility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Middleware\BackendModuleValidator;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Class BackendModuleValidatorBypassTest
 *
 * Pins down *why* powermail has to enforce page access itself instead of relying on the framework.
 *
 * \TYPO3\CMS\Backend\Middleware\BackendModuleValidator is the only page-access gate in front of the powermail
 * backend module, and it only evaluates canonical integer ids (MathUtility::canBeInterpretedAsInteger()).
 * A non-canonical id such as "050" therefore passes the middleware untouched while the module controller's
 * raw (int) cast still resolves it to the foreign pid 50 - which is the reported IDOR.
 *
 * These tests assert both halves: the framework gate does *not* protect the non-canonical id (it lets the
 * request through to the module), and In2code\Powermail\Utility\BackendUtility::isPageAccessGranted() - the
 * check added to ModuleController::initializeAction() - denies it.
 */
#[CoversClass(BackendUtility::class)]
#[CoversMethod(BackendUtility::class, 'isPageAccessGranted')]
#[CoversClass(ModuleController::class)]
final class BackendModuleValidatorBypassTest extends FunctionalTestCase
{
    /**
     * A backend user with the "editor" group whose only DB mount is the accessible page.
     */
    private const USER_RESTRICTED = 2;

    /**
     * Page outside the restricted editor's permissions (owned by another group).
     */
    private const PAGE_FOREIGN = 50;

    /**
     * The powermail submodule that lists the form submissions of a page.
     */
    private const MODULE_IDENTIFIER = 'powermail_list';

    /**
     * Exception code thrown by BackendModuleValidator::validateModuleAccess() on a denied page.
     */
    private const CORE_NO_PAGE_ACCESS_CODE = 1289917924;

    protected array $testExtensionsToLoad = [
        'in2code/powermail',
    ];

    /**
     * The request handler standing in for the module, recording whether the middleware called it.
     */
    private ?object $handler = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_access.csv');
    }

    /**
     * Control case: for a canonical id the framework gate does its job, so no extension code is needed.
     */
    #[Test]
    public function canonicalForeignIdIsRejectedByTheFrameworkGate(): void
    {
        $this->setUpBackendUser(self::USER_RESTRICTED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(self::CORE_NO_PAGE_ACCESS_CODE);

        $this->processMiddleware((string)self::PAGE_FOREIGN);
    }

    /**
     * The vulnerability precondition: a non-canonical id is not even looked at by the framework gate, so the
     * request reaches the module - while the controller's (int) cast resolves it to the foreign pid.
     */
    #[Test]
    #[DataProvider('nonCanonicalForeignIdDataProvider')]
    public function nonCanonicalForeignIdIsNotRejectedByTheFrameworkGate(string $requestId): void
    {
        $this->setUpBackendUser(self::USER_RESTRICTED);

        self::assertFalse(MathUtility::canBeInterpretedAsInteger($requestId));
        // the controller does: $this->id = (int)$requestId;
        self::assertSame(self::PAGE_FOREIGN, (int)$requestId);

        $response = $this->processMiddleware($requestId);

        self::assertTrue($this->handlerWasReached(), 'BackendModuleValidator passed the foreign page id through.');
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * ... and the check added to ModuleController::initializeAction() closes exactly that gap.
     */
    #[Test]
    #[DataProvider('nonCanonicalForeignIdDataProvider')]
    public function nonCanonicalForeignIdIsRejectedByPowermail(string $requestId): void
    {
        $this->setUpBackendUser(self::USER_RESTRICTED);

        self::assertFalse(BackendUtility::isPageAccessGranted((int)$requestId));
    }

    public static function nonCanonicalForeignIdDataProvider(): \Iterator
    {
        yield 'leading zero' => ['050'];
        yield 'leading plus' => ['+50'];
        yield 'trailing space' => ['50 '];
        yield 'leading space' => [' 50'];
        yield 'decimal notation' => ['50.0'];
        yield 'trailing garbage' => ['50abc'];
    }

    /**
     * Run the real core middleware for the powermail list module with the given "id" query parameter.
     *
     * @param string $requestId the raw, unvalidated id from the request
     */
    private function processMiddleware(string $requestId): ResponseInterface
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/powermail/list', 'GET'))
            ->withQueryParams(['id' => $requestId])
            ->withAttribute('route', $this->buildModuleRoute());

        $this->handler = new class() implements RequestHandlerInterface {
            public bool $reached = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;
                return new Response();
            }
        };

        return $this->get(BackendModuleValidator::class)->process($request, $this->handler);
    }

    private function handlerWasReached(): bool
    {
        return ($this->handler->reached ?? false) === true;
    }

    /**
     * Build the route the middleware expects, exactly as the backend router registers it for the module.
     */
    private function buildModuleRoute(): Route
    {
        $module = $this->resolveModule();
        $routeOptions = $module->getDefaultRouteOptions()['_default'];
        $routeOptions['_identifier'] = self::MODULE_IDENTIFIER;

        return new Route($module->getPath(), $routeOptions);
    }

    private function resolveModule(): ModuleInterface
    {
        $module = $this->get(ModuleProvider::class)->getModule(self::MODULE_IDENTIFIER, $GLOBALS['BE_USER']);
        self::assertInstanceOf(
            ModuleInterface::class,
            $module,
            'The restricted backend user must have access to the powermail module itself - otherwise this '
            . 'test would pass for the wrong reason.'
        );

        return $module;
    }
}

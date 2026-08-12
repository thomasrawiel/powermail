<?php

declare(strict_types=1);

namespace In2code\Powermail\Tests\Functional\Controller;

use In2code\Powermail\Controller\AbstractController;
use In2code\Powermail\Controller\ModuleController;
use In2code\Powermail\Exception\NoPageAccessException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Class ModuleControllerTest
 *
 * Regression coverage for the backend IDOR fix at its point of use: whatever page id the request carries,
 * ModuleController::initializeAction() must not hand a page the current backend user may not see to the
 * submission-reading actions (list, exportXls, exportCsv, reportingFormBe, reportingMarketingBe).
 */
#[CoversClass(ModuleController::class)]
#[CoversMethod(ModuleController::class, 'initializeAction')]
final class ModuleControllerTest extends FunctionalTestCase
{
    /**
     * A backend user with the "editor" group whose only DB mount is the accessible page.
     */
    private const USER_RESTRICTED = 2;

    /**
     * Page the restricted editor is allowed to access (group has show permission).
     */
    private const PAGE_ACCESSIBLE = 20;

    /**
     * Page outside the restricted editor's permissions (owned by another group).
     */
    private const PAGE_FOREIGN = 50;

    protected array $testExtensionsToLoad = [
        'in2code/powermail',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_access.csv');
        $this->setUpBackendUser(self::USER_RESTRICTED);
    }

    #[Test]
    public function foreignPageIsDenied(): void
    {
        $this->expectException(NoPageAccessException::class);
        $this->expectExceptionCode(1755000000);

        $this->initializeController(['id' => (string)self::PAGE_FOREIGN]);
    }

    /**
     * The core IDOR: \TYPO3\CMS\Backend\Middleware\BackendModuleValidator lets non-canonical ids pass
     * unchecked, but the raw (int) cast here still resolves them to the foreign pid.
     */
    #[Test]
    #[DataProvider('nonCanonicalForeignIdDataProvider')]
    public function foreignPageIsDeniedViaNonCanonicalId(string $requestId): void
    {
        $this->expectException(NoPageAccessException::class);
        $this->expectExceptionCode(1755000000);

        $this->initializeController(['id' => $requestId]);
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
     * The second half of the IDOR: BackendModuleValidator reads "id" from the query params first, so a
     * foreign id smuggled into the request body must not win over the validated query value.
     */
    #[Test]
    public function foreignPageInTheRequestBodyDoesNotOverrideTheValidatedQueryValue(): void
    {
        $controller = $this->initializeController(
            ['id' => (string)self::PAGE_ACCESSIBLE],
            ['id' => (string)self::PAGE_FOREIGN]
        );

        self::assertSame(self::PAGE_ACCESSIBLE, $this->readResolvedId($controller));
    }

    /**
     * The request body stays a valid fallback when the query carries no id at all - otherwise the assertion
     * above would also hold for an implementation that simply ignores the body.
     */
    #[Test]
    public function pageIdIsReadFromTheRequestBodyWhenTheQueryHasNone(): void
    {
        $controller = $this->initializeController([], ['id' => (string)self::PAGE_ACCESSIBLE]);

        self::assertSame(self::PAGE_ACCESSIBLE, $this->readResolvedId($controller));
    }

    #[Test]
    public function accessiblePageIsAllowed(): void
    {
        $controller = $this->initializeController(['id' => (string)self::PAGE_ACCESSIBLE]);

        self::assertSame(self::PAGE_ACCESSIBLE, $this->readResolvedId($controller));
    }

    /**
     * No page selected: the guard must stay out of the way, just like the framework's own check does.
     */
    #[Test]
    public function missingPageIdIsAllowed(): void
    {
        $controller = $this->initializeController([]);

        self::assertSame(0, $this->readResolvedId($controller));
    }

    /**
     * Run initializeAction() with the given query and body parameters.
     *
     * Everything after the page-access guard (module template, doc header menu) needs a full backend request
     * context that is out of scope here, so a later failure is swallowed - reaching it already proves the
     * guard let the request pass. A NoPageAccessException is deliberately not swallowed.
     *
     * @param array<string, string> $queryParams
     * @param array<string, string> $parsedBody
     * @throws NoPageAccessException
     */
    private function initializeController(array $queryParams, array $parsedBody = []): ModuleController
    {
        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/module/powermail/list', 'GET'))
            ->withAttribute('extbase', new ExtbaseRequestParameters(ModuleController::class))
            ->withQueryParams($queryParams);
        if ($parsedBody !== []) {
            $serverRequest = $serverRequest->withParsedBody($parsedBody);
        }

        $controller = $this->get(ModuleController::class);
        $requestProperty = new \ReflectionProperty(ActionController::class, 'request');
        $requestProperty->setValue($controller, new Request($serverRequest));

        try {
            $controller->initializeAction();
        } catch (NoPageAccessException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            unset($exception);
        }

        return $controller;
    }

    /**
     * Read the page id the reading actions would use.
     */
    private function readResolvedId(ModuleController $controller): int
    {
        return (int)(new \ReflectionProperty(AbstractController::class, 'id'))->getValue($controller);
    }
}

<?php

declare(strict_types=1);

namespace In2code\Powermail\Tests\Functional\Utility;

use In2code\Powermail\Utility\BackendUtility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Class BackendUtilityTest
 *
 * Regression coverage for the backend IDOR fix: a non-admin backend user must not be able to read a page
 * outside of their permitted pages, including via a non-canonical id string (e.g. "050") that resolves to
 * a foreign pid through the controller's raw (int) cast.
 */
#[CoversClass(BackendUtility::class)]
#[CoversMethod(BackendUtility::class, 'isPageAccessGranted')]
final class BackendUtilityTest extends FunctionalTestCase
{
    /**
     * A backend user with the "editor" group whose only DB mount is the accessible page.
     */
    private const USER_RESTRICTED = 2;

    /**
     * A backend administrator.
     */
    private const USER_ADMIN = 1;

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
    }

    #[Test]
    public function restrictedUserIsAllowedToAccessOwnPage(): void
    {
        $this->setUpBackendUser(self::USER_RESTRICTED);

        self::assertTrue(BackendUtility::isPageAccessGranted(self::PAGE_ACCESSIBLE));
    }

    #[Test]
    public function restrictedUserIsDeniedForeignPage(): void
    {
        $this->setUpBackendUser(self::USER_RESTRICTED);

        self::assertFalse(BackendUtility::isPageAccessGranted(self::PAGE_FOREIGN));
    }

    /**
     * The framework's BackendModuleValidator only page-access-checks canonical integer ids
     * (MathUtility::canBeInterpretedAsInteger()). The controller resolves the id with a raw (int) cast, so a
     * non-canonical string still targets the foreign pid. isPageAccessGranted() is evaluated on that cast
     * value and must therefore deny each of these encodings.
     */
    #[Test]
    #[DataProvider('nonCanonicalForeignIdDataProvider')]
    public function restrictedUserIsDeniedForeignPageViaNonCanonicalId(string $requestId): void
    {
        $this->setUpBackendUser(self::USER_RESTRICTED);

        // the controller does: $this->id = (int)$requestId;
        self::assertSame(self::PAGE_FOREIGN, (int)$requestId);
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

    #[Test]
    public function adminIsAllowedToAccessAnyPage(): void
    {
        $this->setUpBackendUser(self::USER_ADMIN);

        self::assertTrue(BackendUtility::isPageAccessGranted(self::PAGE_ACCESSIBLE));
        self::assertTrue(BackendUtility::isPageAccessGranted(self::PAGE_FOREIGN));
    }

    #[Test]
    public function nonPositivePageIdIsNeverGranted(): void
    {
        $this->setUpBackendUser(self::USER_RESTRICTED);

        self::assertFalse(BackendUtility::isPageAccessGranted(0));
        self::assertFalse(BackendUtility::isPageAccessGranted(-1));
    }
}

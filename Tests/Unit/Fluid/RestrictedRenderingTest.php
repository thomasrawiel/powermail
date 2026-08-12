<?php

declare(strict_types=1);
namespace In2code\Powermail\Tests\Unit\Fluid;

use In2code\Powermail\Fluid\Parser\NeutralizeModifiersTemplateProcessor;
use In2code\Powermail\Fluid\ViewHelper\BlockedViewHelper;
use In2code\Powermail\Fluid\ViewHelper\RestrictedViewHelperInvoker;
use In2code\Powermail\Fluid\ViewHelper\RestrictedViewHelperResolver;
use In2code\Powermail\Fluid\ViewHelper\ViewHelperPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3Fluid\Fluid\Core\Parser\TemplateProcessor\NamespaceDetectionTemplateProcessor;
use TYPO3Fluid\Fluid\Core\Parser\UnknownNamespaceException;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Renders strings through the real Fluid TemplateParser with the powermail restriction installed.
 *
 * This is the regression test for the ViewHelper injection: a value that a website visitor submits
 * ends up as a Fluid template source, which allowed executing any ViewHelper.
 */
#[CoversClass(RestrictedViewHelperResolver::class)]
#[CoversClass(RestrictedViewHelperInvoker::class)]
#[CoversClass(NeutralizeModifiersTemplateProcessor::class)]
#[CoversClass(BlockedViewHelper::class)]
#[CoversClass(ViewHelperPolicy::class)]
final class RestrictedRenderingTest extends UnitTestCase
{
    /**
     * blocked ViewHelpers are logged, which instantiates a LogManager singleton
     *
     * @var bool
     */
    protected bool $resetSingletonInstances = true;

    /**
     * The payload from the security report
     */
    private const PAYLOAD = '{namespace i=TYPO3\CMS\Install\ViewHelpers}'
        . '<f:asset.script identifier="pi"><i:phpInfo/></f:asset.script>';

    /**
     * @param string[] $allowedViewHelpers
     * @param array<string, mixed> $variables
     */
    private function render(string $source, array $allowedViewHelpers = [], array $variables = []): string
    {
        $policy = ViewHelperPolicy::fromIdentifiers($allowedViewHelpers);

        // a resolver with the namespaces a TYPO3 installation registers for "f"
        $decorated = new ViewHelperResolver();
        $decorated->setNamespaces([
            'f' => ['TYPO3Fluid\\Fluid\\ViewHelpers', 'TYPO3\\CMS\\Fluid\\ViewHelpers'],
        ]);

        $renderingContext = new RenderingContext();
        $resolver = new RestrictedViewHelperResolver($decorated, $policy);
        $renderingContext->setViewHelperResolver($resolver);
        $renderingContext->setViewHelperInvoker(new RestrictedViewHelperInvoker($policy, $resolver));
        $renderingContext->setTemplateProcessors([
            new NeutralizeModifiersTemplateProcessor(),
            new NamespaceDetectionTemplateProcessor(),
        ]);
        $renderingContext->setControllerName('PowermailRestrictedString');
        $renderingContext->setControllerAction('Parse' . $policy->getFingerprint());
        $renderingContext->getTemplatePaths()->setTemplateSource($source);

        $view = new TemplateView($renderingContext);
        $view->assignMultiple($variables);

        return $view->render();
    }

    #[Test]
    public function payloadFromTheSecurityReportDoesNotExecuteAnyViewHelper(): void
    {
        $result = $this->render(self::PAYLOAD, ['f:cObject']);

        self::assertStringNotContainsStringIgnoringCase('phpinfo', $result);
        self::assertStringNotContainsStringIgnoringCase('php version', $result);
        self::assertStringNotContainsString('<script', $result);
    }

    /**
     * The injected namespace stays unknown even inside an allowed ViewHelper, so "i:phpInfo" can
     * never resolve - allowing a ViewHelper does not open a door for the ones nested in it
     */
    #[Test]
    public function injectedNamespaceDoesNotResolveInsideAnAllowedViewHelper(): void
    {
        $result = $this->render(
            '{namespace i=TYPO3\CMS\Install\ViewHelpers}<f:format.trim><i:phpInfo/></f:format.trim>',
            ['f:format.trim']
        );

        // the tag survives as inert, escaped text instead of being executed
        self::assertSame('&lt;i:phpInfo/&gt;', $result);
        self::assertStringNotContainsStringIgnoringCase('php version', $result);
    }

    #[Test]
    public function namespaceImportCannotReAliasAnAllowedIdentifier(): void
    {
        $result = $this->render(
            '{namespace f=TYPO3\CMS\Install\ViewHelpers}<f:phpInfo/>',
            ['f:phpInfo']
        );

        self::assertStringNotContainsStringIgnoringCase('phpinfo', $result);
        self::assertStringNotContainsStringIgnoringCase('php version', $result);
    }

    /**
     * Dataprovider markerHandlingReturnsString()
     */
    public static function markerHandlingReturnsStringDataProvider(): \Iterator
    {
        yield 'plain marker' => [
            'Hello {firstname}',
            ['firstname' => 'Max'],
            'Hello Max',
        ];
        yield 'marker value is escaped' => [
            'Hello {firstname}',
            ['firstname' => '<b>Max</b>'],
            'Hello &lt;b&gt;Max&lt;/b&gt;',
        ];
        yield 'marker value containing fluid syntax is not parsed again' => [
            'Hello {firstname}',
            ['firstname' => '{f:cObject(typoscriptObjectPath:\'lib.evil\')}'],
            'Hello {f:cObject(typoscriptObjectPath:&#039;lib.evil&#039;)}',
        ];
        yield 'unknown marker stays empty' => [
            'Hello {unknown}',
            [],
            'Hello ',
        ];
    }

    #[DataProvider('markerHandlingReturnsStringDataProvider')]
    #[Test]
    public function markerHandlingReturnsString(string $source, array $variables, string $expectedResult): void
    {
        self::assertSame($expectedResult, $this->render($source, ['f:cObject'], $variables));
    }

    /**
     * Different from Fluid 5 (powermail 14): Fluid 2 cannot ignore a namespace in inline notation, it
     * always raises an UnknownNamespaceException. RestrictedStringRenderer catches it and returns the
     * value with the Fluid syntax removed, so nothing is executed either way.
     */
    #[Test]
    public function unknownNamespaceInInlineNotationThrowsInsteadOfExecuting(): void
    {
        $this->expectException(UnknownNamespaceException::class);

        $this->render('{i:phpInfo()}', ['f:cObject']);
    }

    #[Test]
    public function blockedViewHelperOfAKnownNamespaceRendersNothing(): void
    {
        self::assertSame('', $this->render('<f:asset.script identifier="x">alert(1)</f:asset.script>', ['f:cObject']));
    }

    #[Test]
    public function allowedViewHelperIsStillExecuted(): void
    {
        self::assertSame(
            'a<br />' . "\n" . 'b',
            $this->render('{firstname -> f:format.nl2br()}', ['f:format.nl2br'], ['firstname' => "a\nb"])
        );
    }

    #[Test]
    public function notAllowedViewHelperIsNotExecutedInInlineNotation(): void
    {
        self::assertSame(
            '',
            $this->render('{firstname -> f:format.raw()}', ['f:cObject'], ['firstname' => '<script>alert(1)</script>'])
        );
    }

    /**
     * Dataprovider conditionViewHelperReturnsString()
     */
    public static function conditionViewHelperReturnsStringDataProvider(): \Iterator
    {
        yield 'f:if is dropped when it is not allowed' => [
            ['f:cObject'],
            '',
        ];
        yield 'f:if works when it is allowed' => [
            ['f:if', 'f:then', 'f:else'],
            'yes',
        ];
    }

    #[DataProvider('conditionViewHelperReturnsStringDataProvider')]
    #[Test]
    public function conditionViewHelperReturnsString(array $allowedViewHelpers, string $expectedResult): void
    {
        self::assertSame(
            $expectedResult,
            $this->render('<f:if condition="1">yes</f:if>', $allowedViewHelpers)
        );
    }

    /**
     * "{escaping=false}" would switch output escaping off for the whole string
     */
    #[Test]
    public function escapingModifierIsStrippedWithoutEffect(): void
    {
        self::assertSame(
            'Hello &lt;b&gt;Max&lt;/b&gt;',
            $this->render('{escaping=false}Hello {firstname}', ['f:cObject'], ['firstname' => '<b>Max</b>'])
        );
    }

    /**
     * "{parsing off}" would make Fluid return the source verbatim, skipping marker replacement and
     * escaping altogether
     */
    #[Test]
    public function parsingModifierIsStrippedWithoutEffect(): void
    {
        self::assertSame(
            'Hello &lt;b&gt;Max&lt;/b&gt;',
            $this->render('{parsing off}Hello {firstname}', ['f:cObject'], ['firstname' => '<b>Max</b>'])
        );
    }

    #[Test]
    public function localNamespaceDeclarationIsRemovedFromTheOutput(): void
    {
        self::assertSame('x', $this->render('{namespace i=TYPO3\CMS\Install\ViewHelpers}x', ['f:cObject']));
    }
}

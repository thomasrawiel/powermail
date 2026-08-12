<?php

declare(strict_types=1);
namespace In2code\Powermail\Tests\Unit\Fluid;

use In2code\Powermail\Fluid\RestrictedStringRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers the two paths of RestrictedStringRenderer that do not need the Fluid engine: the fast path
 * for values without Fluid syntax, and the fallback used when parsing failed.
 */
#[CoversClass(RestrictedStringRenderer::class)]
final class RestrictedStringRendererTest extends UnitTestCase
{
    /**
     * @var RestrictedStringRenderer
     */
    protected $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->getAccessibleMock(RestrictedStringRenderer::class, null, [], '', false);
    }

    /**
     * A string without Fluid syntax never reaches the parser
     */
    #[Test]
    public function renderReturnsAStringWithoutFluidSyntaxUnchanged(): void
    {
        self::assertSame('Max Mustermann', $this->subject->render('Max Mustermann', ['firstname' => 'Max']));
    }

    /**
     * Dataprovider removeFluidSyntaxReturnsString()
     */
    public static function removeFluidSyntaxReturnsStringDataProvider(): \Iterator
    {
        yield 'the payload of the security report' => [
            '{namespace i=TYPO3\CMS\Install\ViewHelpers}<f:asset.script identifier="pi"><i:phpInfo/></f:asset.script>',
            '',
        ];
        yield 'namespaced tags are removed' => [
            'Hello <f:asset.script identifier="x">alert(1)</f:asset.script>',
            'Hello alert(1)',
        ];
        yield 'inline notation is removed' => [
            'Hello {f:cObject(typoscriptObjectPath:\'lib.evil\')}',
            'Hello',
        ];
        yield 'markers are removed' => [
            'Hello {firstname}',
            'Hello',
        ];
        yield 'nested curly braces are removed' => [
            'Hello {f:if(condition:\'{firstname}\',then:\'x\')}',
            'Hello',
        ];
        yield 'a plain value is kept' => [
            'Max Mustermann',
            'Max Mustermann',
        ];
        yield 'plain html is kept' => [
            'Max <b>Mustermann</b>',
            'Max <b>Mustermann</b>',
        ];
    }

    #[DataProvider('removeFluidSyntaxReturnsStringDataProvider')]
    #[Test]
    public function removeFluidSyntaxReturnsString(string $string, string $expectedResult): void
    {
        self::assertSame($expectedResult, $this->subject->_call('removeFluidSyntax', $string));
    }
}

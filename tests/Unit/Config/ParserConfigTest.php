<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sugar\Core\Config\ParserConfig;

/**
 * Test ParserConfig
 */
final class ParserConfigTest extends TestCase
{
    public function testDefaultDirectivePrefix(): void
    {
        $config = new ParserConfig();

        $this->assertSame('s', $config->directivePrefix);
    }

    public function testCustomDirectivePrefix(): void
    {
        $config = new ParserConfig(directivePrefix: 'x');

        $this->assertSame('x', $config->directivePrefix);
    }

    public function testIsDirectiveWithDefaultPrefix(): void
    {
        $config = new ParserConfig();

        $this->assertTrue($config->isDirective('s:if'));
        $this->assertTrue($config->isDirective('s:foreach'));
        $this->assertTrue($config->isDirective('s:text'));
        $this->assertTrue($config->isDirective('s:html'));
        $this->assertFalse($config->isDirective('class'));
        $this->assertFalse($config->isDirective('id'));
        $this->assertFalse($config->isDirective('x:if'));
    }

    public function testIsDirectiveWithCustomPrefix(): void
    {
        $config = new ParserConfig(directivePrefix: 'v');

        $this->assertTrue($config->isDirective('v:if'));
        $this->assertTrue($config->isDirective('v:foreach'));
        $this->assertFalse($config->isDirective('s:if'));
        $this->assertFalse($config->isDirective('class'));
    }

    public function testExtractDirectiveNameWithDefaultPrefix(): void
    {
        $config = new ParserConfig();

        $this->assertSame('if', $config->extractDirectiveName('s:if'));
        $this->assertSame('foreach', $config->extractDirectiveName('s:foreach'));
        $this->assertSame('text', $config->extractDirectiveName('s:text'));
        $this->assertSame('html', $config->extractDirectiveName('s:html'));
        $this->assertSame('elseif', $config->extractDirectiveName('s:elseif'));
        $this->assertNull($config->extractDirectiveName('class'));
        $this->assertNull($config->extractDirectiveName('id'));
    }

    public function testExtractDirectiveNameWithCustomPrefix(): void
    {
        $config = new ParserConfig(directivePrefix: 'app');

        $this->assertSame('if', $config->extractDirectiveName('app:if'));
        $this->assertSame('foreach', $config->extractDirectiveName('app:foreach'));
        $this->assertNull($config->extractDirectiveName('s:if'));
        $this->assertNull($config->extractDirectiveName('class'));
    }

    public function testExtractDirectiveNameWithLongerDirective(): void
    {
        $config = new ParserConfig();

        $this->assertSame('custom-directive', $config->extractDirectiveName('s:custom-directive'));
    }

    public function testIsDirectiveWithEmptyString(): void
    {
        $config = new ParserConfig();

        $this->assertFalse($config->isDirective(''));
    }

    public function testExtractDirectiveNameWithEmptyString(): void
    {
        $config = new ParserConfig();

        $this->assertNull($config->extractDirectiveName(''));
    }

    public function testIsDirectiveWithOnlyPrefix(): void
    {
        $config = new ParserConfig();

        $this->assertFalse($config->isDirective('s:'));
    }

    public function testExtractDirectiveNameWithOnlyPrefix(): void
    {
        $config = new ParserConfig();

        $this->assertNull($config->extractDirectiveName('s:'));
    }

    public function testConfigIsReadonly(): void
    {
        $config = new ParserConfig();

        $reflection = new ReflectionClass($config);
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testDefaultVoidElements(): void
    {
        $config = new ParserConfig();

        $this->assertCount(14, $config->voidElements);
        $this->assertContains('br', $config->voidElements);
        $this->assertContains('img', $config->voidElements);
        $this->assertContains('input', $config->voidElements);
    }

    public function testCustomVoidElements(): void
    {
        $config = new ParserConfig(
            voidElements: ['br', 'img', 'custom-element'],
        );

        $this->assertCount(3, $config->voidElements);
        $this->assertContains('custom-element', $config->voidElements);
        $this->assertNotContains('input', $config->voidElements); // Default replaced
    }

    public function testAdditionalVoidElements(): void
    {
        $config = new ParserConfig(
            additionalVoidElements: ['custom-widget', 'app-icon'],
        );

        // Should have 14 defaults + 2 additional = 16
        $this->assertCount(16, $config->voidElements);
        $this->assertContains('br', $config->voidElements); // Default present
        $this->assertContains('img', $config->voidElements); // Default present
        $this->assertContains('custom-widget', $config->voidElements); // Added
        $this->assertContains('app-icon', $config->voidElements); // Added
    }

    public function testIsVoidElement(): void
    {
        $config = new ParserConfig();

        $this->assertTrue($config->isVoidElement('br'));
        $this->assertTrue($config->isVoidElement('BR')); // Case insensitive
        $this->assertTrue($config->isVoidElement('img'));
        $this->assertFalse($config->isVoidElement('div'));
        $this->assertFalse($config->isVoidElement('span'));
    }

    public function testIsVoidElementWithCustomElements(): void
    {
        $config = new ParserConfig(
            additionalVoidElements: ['custom-widget'],
        );

        $this->assertTrue($config->isVoidElement('br')); // Default
        $this->assertTrue($config->isVoidElement('img')); // Default
        $this->assertTrue($config->isVoidElement('custom-widget')); // Added
        $this->assertTrue($config->isVoidElement('CUSTOM-WIDGET')); // Case insensitive
        $this->assertFalse($config->isVoidElement('div'));
    }

    public function testIsVoidElementWithReplacedElements(): void
    {
        $config = new ParserConfig(
            voidElements: ['br', 'custom-widget'],
        );

        $this->assertTrue($config->isVoidElement('br'));
        $this->assertTrue($config->isVoidElement('custom-widget'));
        $this->assertFalse($config->isVoidElement('img')); // Not in replacement list
        $this->assertFalse($config->isVoidElement('div'));
    }
}

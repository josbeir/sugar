<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Pass\Context;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Pass\Context\AnalysisContext;

/**
 * Test context analysis helper
 */
final class AnalysisContextTest extends TestCase
{
    public function testEmptyContextDefaultsToHtml(): void
    {
        $context = new AnalysisContext();

        $result = $context->determineContext();

        $this->assertSame(OutputContext::HTML, $result);
    }

    public function testPushElementIsImmutable(): void
    {
        $original = new AnalysisContext();
        $modified = $original->push('div');

        $this->assertNotSame($original, $modified);
        $this->assertEmpty($original->getElementStack());
        $this->assertSame(['div'], $modified->getElementStack());
    }

    public function testScriptTagSetsJavascriptContext(): void
    {
        $context = new AnalysisContext();
        $scriptContext = $context->push('script');

        $result = $scriptContext->determineContext();

        $this->assertSame(OutputContext::JAVASCRIPT, $result);
    }

    public function testStyleTagSetsCssContext(): void
    {
        $context = new AnalysisContext();
        $styleContext = $context->push('style');

        $result = $styleContext->determineContext();

        $this->assertSame(OutputContext::CSS, $result);
    }

    public function testNestedElementsWithScript(): void
    {
        $context = new AnalysisContext();
        $context = $context->push('div');
        $context = $context->push('script');
        $context = $context->push('span');
        // Inside script - should still be JS

        $result = $context->determineContext();

        $this->assertSame(OutputContext::JAVASCRIPT, $result);
        $this->assertSame(['div', 'script', 'span'], $context->getElementStack());
    }

    public function testTagNamesAreCaseInsensitive(): void
    {
        $context = new AnalysisContext();
        $scriptContext = $context->push('SCRIPT');

        $result = $scriptContext->determineContext();

        $this->assertSame(OutputContext::JAVASCRIPT, $result);
        $this->assertSame(['script'], $scriptContext->getElementStack());
    }

    public function testPopElementIsImmutable(): void
    {
        $context = new AnalysisContext();
        $context = $context->push('div')->push('span');

        $popped = $context->pop('span');

        $this->assertNotSame($context, $popped);
        $this->assertSame(['div', 'span'], $context->getElementStack());
        $this->assertSame(['div'], $popped->getElementStack());
    }

    public function testPopRemovesLastOccurrence(): void
    {
        $context = new AnalysisContext();
        $context = $context->push('div')->push('div');

        $popped = $context->pop('div');

        $this->assertSame(['div'], $popped->getElementStack());
    }

    public function testPopNonExistentTagDoesNothing(): void
    {
        $context = new AnalysisContext();
        $context = $context->push('div');

        $popped = $context->pop('span');

        $this->assertSame(['div'], $popped->getElementStack());
    }
}

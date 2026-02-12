<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\AttributeValue;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Exception\SyntaxException;

/**
 * Test CompilationContext for template metadata and exception creation
 */
final class CompilationContextTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $context = new CompilationContext(
            templatePath: 'views/profile.sugar.php',
            source: '<div>test</div>',
            debug: true,
        );

        $this->assertSame('views/profile.sugar.php', $context->templatePath);
        $this->assertSame('<div>test</div>', $context->source);
        $this->assertTrue($context->debug);
    }

    public function testDebugDefaultsToFalse(): void
    {
        $context = new CompilationContext(
            templatePath: 'test.sugar.php',
            source: '<div>test</div>',
        );

        $this->assertFalse($context->debug);
    }

    public function testCreateExceptionWithLocationMetadata(): void
    {
        $source = <<<'PHP'
<div s:if="$user">
    <p s:forech="$items">
        <?= $item ?>
    </p>
</div>
PHP;

        $context = new CompilationContext('test.sugar.php', $source);

        $exception = $context->createException(
            SyntaxException::class,
            'Invalid directive syntax',
            line: 2,
            column: 8,
        );

        $this->assertInstanceOf(SyntaxException::class, $exception);
        $this->assertSame('test.sugar.php', $exception->templatePath);
        $this->assertSame(2, $exception->templateLine);
        $this->assertSame(8, $exception->templateColumn);
        $this->assertStringContainsString('template: test.sugar.php', $exception->getMessage());
    }

    public function testCreateExceptionWithoutLineOrColumn(): void
    {
        $context = new CompilationContext('test.sugar.php', '<div>test</div>');

        $exception = $context->createException(
            SyntaxException::class,
            'General syntax error',
        );

        $this->assertInstanceOf(SyntaxException::class, $exception);
        $this->assertSame('test.sugar.php', $exception->templatePath);
        $this->assertNull($exception->templateLine);
        $this->assertNull($exception->templateColumn);
    }

    public function testCreateExceptionWithLineButNoColumn(): void
    {
        $source = '<div s:if="$test">content</div>';
        $context = new CompilationContext('test.sugar.php', $source);

        $exception = $context->createException(
            SyntaxException::class,
            'Error on line',
            line: 1,
        );
        $this->assertInstanceOf(SyntaxException::class, $exception);
    }

    public function testCreateExceptionWithDifferentExceptionTypes(): void
    {
        $source = '<div>test</div>';
        $context = new CompilationContext('test.sugar.php', $source);

        $syntax = $context->createException(
            SyntaxException::class,
            'Syntax error',
            1,
            5,
        );
        $this->assertInstanceOf(SyntaxException::class, $syntax);
        $this->assertStringContainsString('Syntax error', $syntax->getMessage());
    }

    public function testCreateExceptionPreservesExceptionMessage(): void
    {
        $context = new CompilationContext('test.sugar.php', '<div>test</div>');

        $exception = $context->createException(
            SyntaxException::class,
            'Custom error message',
            1,
            5,
        );

        $this->assertStringContainsString('Custom error message', $exception->getMessage());
    }

    public function testMultipleExceptionsFromSameContext(): void
    {
        $source = '<div s:if="$test">content</div>';
        $context = new CompilationContext('views/test.sugar.php', $source);

        $exception1 = $context->createException(
            SyntaxException::class,
            'First error',
            1,
            5,
        );

        $exception2 = $context->createException(
            SyntaxException::class,
            'Second error',
            1,
            10,
        );

        // Both should reference same template
        $this->assertSame('views/test.sugar.php', $exception1->templatePath);
        $this->assertSame('views/test.sugar.php', $exception2->templatePath);

        // But different column positions
        $this->assertSame(5, $exception1->templateColumn);
        $this->assertSame(10, $exception2->templateColumn);
    }

    public function testCreateSyntaxExceptionUsesDefaultTemplatePath(): void
    {
        $context = new CompilationContext('default.sugar.php', '<div>test</div>');

        $exception = $context->createSyntaxException('Syntax failure', 3, 9);

        $this->assertInstanceOf(SyntaxException::class, $exception);
        $this->assertSame('default.sugar.php', $exception->templatePath);
        $this->assertSame(3, $exception->templateLine);
        $this->assertSame(9, $exception->templateColumn);
    }

    public function testCreateSyntaxExceptionForNodeUsesNodeTemplatePath(): void
    {
        $context = new CompilationContext('root.sugar.php', '<div>test</div>');
        $node = new ElementNode('div', [], [], false, 4, 12);
        $node->setTemplatePath('partials/card.sugar.php');

        $exception = $context->createSyntaxExceptionForNode('Node error', $node);

        $this->assertSame('partials/card.sugar.php', $exception->templatePath);
        $this->assertSame(4, $exception->templateLine);
        $this->assertSame(12, $exception->templateColumn);
    }

    public function testCreateExceptionForNodeUsesNodeTemplatePath(): void
    {
        $context = new CompilationContext('root.sugar.php', '<div>test</div>');
        $node = new ElementNode('div', [], [], false, 2, 6);
        $node->setTemplatePath('partials/footer.sugar.php');

        $exception = $context->createExceptionForNode(
            SyntaxException::class,
            'Node error',
            $node,
        );

        $this->assertSame('partials/footer.sugar.php', $exception->templatePath);
        $this->assertSame(2, $exception->templateLine);
        $this->assertSame(6, $exception->templateColumn);
    }

    public function testCreateSyntaxExceptionForAttributeUsesAttributeTemplatePath(): void
    {
        $context = new CompilationContext('root.sugar.php', '<div>test</div>');
        $attribute = new AttributeNode('s:class', AttributeValue::static('test'), 7, 3);
        $attribute->setTemplatePath('partials/button.sugar.php');

        $exception = $context->createSyntaxExceptionForAttribute('Attribute error', $attribute);

        $this->assertSame('partials/button.sugar.php', $exception->templatePath);
        $this->assertSame(7, $exception->templateLine);
        $this->assertSame(3, $exception->templateColumn);
    }

    public function testCreateExceptionForAttributeUsesAttributeTemplatePath(): void
    {
        $context = new CompilationContext('root.sugar.php', '<div>test</div>');
        $attribute = new AttributeNode('s:if', AttributeValue::static('$ok'), 9, 2);
        $attribute->setTemplatePath('partials/nav.sugar.php');

        $exception = $context->createExceptionForAttribute(
            SyntaxException::class,
            'Attribute error',
            $attribute,
        );

        $this->assertSame('partials/nav.sugar.php', $exception->templatePath);
        $this->assertSame(9, $exception->templateLine);
        $this->assertSame(2, $exception->templateColumn);
    }

    public function testStampTemplatePathAssignsNodeAndAttributePaths(): void
    {
        $attribute = new AttributeNode('class', AttributeValue::static('card'), 1, 5);
        $element = new ElementNode('div', [$attribute], [], false, 1, 1);
        $document = new DocumentNode([$element]);

        $context = new CompilationContext('views/index.sugar.php', '<div class="card"></div>');
        $context->stampTemplatePath($document);

        $this->assertSame('views/index.sugar.php', $document->getTemplatePath());
        $this->assertSame('views/index.sugar.php', $element->getTemplatePath());
        $this->assertSame('views/index.sugar.php', $attribute->getTemplatePath());
    }
}

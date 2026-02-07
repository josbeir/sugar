<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Escape\Escaper;
use Sugar\Exception\ComponentNotFoundException;
use Sugar\Runtime\EmptyHelper;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Test compiler orchestration
 */
final class CompilerTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;
    use TemplateTestHelperTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
    }

    public function testCompileStaticText(): void
    {
        $source = 'Hello World';

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('Hello World', $result);
        $this->assertStringContainsString('<?php', $result);
        $this->assertStringContainsString('declare(strict_types=1);', $result);
    }

    public function testCompileSimpleOutput(): void
    {
        $source = $this->loadTemplate('simple-output.sugar.php');

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('Hello ', $result);
        $this->assertStringContainsString(Escaper::class . '::html($name)', $result);
        $this->assertStringContainsString('!', $result);
    }

    public function testCompileWithJavascriptContext(): void
    {
        $source = $this->loadTemplate('javascript-context.sugar.php');

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('<script>', $result);
        $this->assertStringContainsString(Escaper::class . '::js($data', $result);
        $this->assertStringNotContainsString('Escaper::html', $result);
    }

    public function testCompileWithCssContext(): void
    {
        $source = $this->loadTemplate('css-context.sugar.php');

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('<style>', $result);
        // CSS context uses specific escaping
        $this->assertStringContainsString('$color', $result);
    }

    public function testCompileWithAttributeContext(): void
    {
        $source = $this->loadTemplate('attribute-context.sugar.php');

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('<a href="', $result);
        $this->assertStringContainsString(Escaper::class . '::html($url)', $result);
        $this->assertStringContainsString('Link</a>', $result);
    }

    public function testCompileWithRawPhp(): void
    {
        $source = $this->loadTemplate('raw-php.sugar.php');

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('<?php $x = 42; ?>', $result);
        $this->assertStringContainsString(Escaper::class . '::html($x)', $result);
    }

    public function testCompileComplexTemplate(): void
    {
        $source = $this->loadTemplate('complex-template.sugar.php');

        $result = $this->compiler->compile($source);

        // Check title is HTML escaped
        $this->assertStringContainsString(Escaper::class . '::html($title)', $result);

        // Check CSS context
        $this->assertStringContainsString(Escaper::class . '::css($bgColor)', $result);

        // Check JS context
        $this->assertStringContainsString(Escaper::class . '::js($config', $result);

        // Check attribute context
        $this->assertStringContainsString(Escaper::class . '::html($link)', $result);

        // Check body HTML context
        $this->assertStringContainsString(Escaper::class . '::html($heading)', $result);

        // Check raw() pipe outputs without escaping
        $this->assertStringContainsString('<?php echo $articleBody; ?>', $result);
        $this->assertStringNotContainsString(Escaper::class . '::html($articleBody)', $result);

        // Check raw() pipe outputs without escaping
        $this->assertStringContainsString('<?php echo $sidebarHtml; ?>', $result);
        $this->assertStringNotContainsString(Escaper::class . '::html($sidebarHtml)', $result);

        // Check footer raw content
        $this->assertStringContainsString('<?php echo $footerContent; ?>', $result);

        // Check year is still escaped (regular output)
        $this->assertStringContainsString(Escaper::class . '::html($year)', $result);

        // Verify raw() pipe does not emit raw() calls in output
        $this->assertStringNotContainsString('raw($articleBody)', $result);
        $this->assertStringNotContainsString('raw($sidebarHtml)', $result);

        // Check s:if directive compiles
        $this->assertStringContainsString('<?php if ($showBanner): ?>', $result);
        $this->assertStringContainsString('<?php endif; ?>', $result);

        // Check s:foreach directive compiles
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $result);
        $this->assertStringContainsString('<?php endforeach; ?>', $result);

        // Check s:forelse directive compiles with if/else wrapper
        $this->assertStringContainsString('<?php if (!' . EmptyHelper::class . '::isEmpty($products)): ?>', $result);
        $this->assertStringContainsString('<?php foreach ($products as $product): ?>', $result);
        $this->assertStringContainsString('<?php else: ?>', $result);
        $this->assertStringContainsString('No products available', $result);

        // Check loop metadata
        $this->assertStringContainsString('$loop', $result);
        $this->assertStringContainsString('->iteration', $result);
        $this->assertStringContainsString('->count', $result);
    }

    public function testCompiledCodeIsExecutable(): void
    {
        $source = $this->loadTemplate('user-profile.sugar.php');

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'name' => '<script>alert("xss")</script>',
            'age' => 25,
        ]);

        $this->assertNotFalse($output);
        $this->assertStringContainsString('User:', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('Age: 25', $output);
    }

    public function testEmptyTemplate(): void
    {
        $source = '';

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('<?php', $result);
        $this->assertStringContainsString('declare(strict_types=1);', $result);
    }

    public function testCompileComponentThrowsWhenComponentMissing(): void
    {
        $this->expectException(ComponentNotFoundException::class);
        $this->expectExceptionMessage('Component "button" not found');

        $this->compiler->compileComponent('button');
    }

    public function testMultipleContextSwitches(): void
    {
        $source = $this->loadTemplate('multiple-contexts.sugar.php');

        $result = $this->compiler->compile($source);

        // Find each output and check its escaping
        preg_match_all('/echo\s+(.+?);/', $result, $matches);
        $outputs = $matches[1];

        $this->assertCount(3, $outputs);

        // First should be HTML escaped
        $this->assertStringContainsString(Escaper::class . '::html($html)', $outputs[0]);

        // Second should be JSON encoded (JavaScript context)
        $this->assertStringContainsString(Escaper::class . '::js($js', $outputs[1]);

        // Third should be HTML escaped again
        $this->assertStringContainsString(Escaper::class . '::html($html2)', $outputs[2]);
    }

    public function testCompileUnclosedPhpBlock(): void
    {
        $source = $this->loadTemplate('unclosed-php.sugar.php');

        $result = $this->compiler->compile($source);

        // Should contain the PHP code
        $this->assertStringContainsString('$items = [', $result);
        $this->assertStringContainsString('foreach ($items as $item)', $result);
        $this->assertStringContainsString('strtoupper($item)', $result);
    }

    public function testCompileMixedUnclosedPhp(): void
    {
        $source = $this->loadTemplate('mixed-unclosed.sugar.php');

        $result = $this->compiler->compile($source);

        // Should have the h1 element
        $this->assertStringContainsString('<h1>Welcome</h1>', $result);
        // Should have the PHP code
        $this->assertStringContainsString('$greeting = "Hello, World!";', $result);
        $this->assertStringContainsString('echo $greeting;', $result);
    }

    public function testCompileIfDirective(): void
    {
        $source = '<div s:if="$isAdmin">Admin Panel</div>';

        $result = $this->compiler->compile($source);

        // Should contain if control structure
        $this->assertStringContainsString('<?php if ($isAdmin): ?>', $result);
        $this->assertStringContainsString('<?php endif; ?>', $result);
        $this->assertStringContainsString('<div>Admin Panel</div>', $result);
    }

    public function testCompileIfDirectiveExecutable(): void
    {
        $source = '<div s:if="$showMessage">Hello <?= $name ?></div>';

        $compiled = $this->compiler->compile($source);

        // Test with condition true
        $output = $this->executeTemplate($compiled, [
            'showMessage' => true,
            'name' => 'Alice',
        ]);
        $this->assertStringContainsString('<div>Hello Alice</div>', $output);

        // Test with condition false
        $output = $this->executeTemplate($compiled, [
            'showMessage' => false,
            'name' => 'Bob',
        ]);
        $this->assertStringNotContainsString('Hello', $output);
        $this->assertStringNotContainsString('Bob', $output);
    }

    public function testCompileForeachDirective(): void
    {
        $source = '<ul><li s:foreach="$items as $item"><?= $item ?></li></ul>';

        $result = $this->compiler->compile($source);

        // Should contain foreach control structure
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $result);
        $this->assertStringContainsString('<?php endforeach; ?>', $result);
        $this->assertStringContainsString('<li>', $result);
    }

    public function testCompileForeachDirectiveExecutable(): void
    {
        $source = '<ul><li s:foreach="$items as $item"><?= $item ?></li></ul>';

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'items' => ['Apple', 'Banana', 'Cherry'],
        ]);

        $this->assertStringContainsString('<ul>', $output);
        $this->assertStringContainsString('<li>Apple</li>', $output);
        $this->assertStringContainsString('<li>Banana</li>', $output);
        $this->assertStringContainsString('<li>Cherry</li>', $output);
        $this->assertStringContainsString('</ul>', $output);
    }

    public function testCompileNestedDirectives(): void
    {
        $source = '<div s:if="$show"><ul><li s:foreach="$items as $item"><?= $item ?></li></ul></div>';

        $result = $this->compiler->compile($source);

        // Should contain nested control structures
        $this->assertStringContainsString('<?php if ($show): ?>', $result);
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $result);
        $this->assertStringContainsString('<?php endforeach; ?>', $result);
        $this->assertStringContainsString('<?php endif; ?>', $result);
    }

    public function testCompileNestedDirectivesExecutable(): void
    {
        $source = '<div s:if="$show"><ul><li s:foreach="$items as $item"><?= $item ?></li></ul></div>';

        $compiled = $this->compiler->compile($source);

        // Test with condition true
        $output = $this->executeTemplate($compiled, [
            'show' => true,
            'items' => ['One', 'Two'],
        ]);
        $this->assertStringContainsString('<div>', $output);
        $this->assertStringContainsString('<li>One</li>', $output);
        $this->assertStringContainsString('<li>Two</li>', $output);

        // Test with condition false
        $output = $this->executeTemplate($compiled, [
            'show' => false,
            'items' => ['One', 'Two'],
        ]);
        $this->assertStringNotContainsString('<div>', $output);
        $this->assertStringNotContainsString('One', $output);
    }

    public function testCompileDirectiveWithEscaping(): void
    {
        $source = '<div s:if="$isUser">Welcome <?= $name ?></div>';

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'isUser' => true,
            'name' => '<script>alert("xss")</script>',
        ]);

        // Should escape the name variable
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('Welcome', $output);
    }

    public function testCompileTextDirective(): void
    {
        $source = '<div s:text="$userName"></div>';

        $compiled = $this->compiler->compile($source);

        // Verify compilation
        $this->assertStringContainsString('<div>', $compiled);
        $this->assertStringContainsString(Escaper::class . '::html($userName)', $compiled);
        $this->assertStringContainsString('</div>', $compiled);

        // Test execution with XSS attempt
        $output = $this->executeTemplate($compiled, [
            'userName' => '<script>alert("xss")</script>',
        ]);

        $this->assertStringContainsString('<div>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>alert', $output);
        $this->assertStringContainsString('</div>', $output);
    }

    public function testCompileHtmlDirective(): void
    {
        $source = '<div s:html="$trustedContent"></div>';

        $compiled = $this->compiler->compile($source);

        // Verify compilation - should NOT escape
        $this->assertStringContainsString('<div>', $compiled);
        $this->assertStringNotContainsString('Escaper::html', $compiled);
        $this->assertStringContainsString('echo $trustedContent', $compiled);
        $this->assertStringContainsString('</div>', $compiled);

        // Test execution - raw HTML should pass through
        $output = $this->executeTemplate($compiled, [
            'trustedContent' => '<strong>Bold</strong>',
        ]);

        $this->assertStringContainsString('<div>', $output);
        $this->assertStringContainsString('<strong>Bold</strong>', $output);
        $this->assertStringContainsString('</div>', $output);
    }

    public function testTextDirectiveWithComplexExpression(): void
    {
        $source = '<span s:text="$user->getName()"></span>';

        $compiled = $this->compiler->compile($source);

        $this->assertStringContainsString(Escaper::class . '::html($user->getName()', $compiled);
    }

    public function testMixingTextAndHtmlDirectives(): void
    {
        $source = <<<'TEMPLATE'
<div s:text="$safeText"></div>
<div s:html="$trustedHtml"></div>
TEMPLATE;

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'safeText' => '<script>bad</script>',
            'trustedHtml' => '<em>Good</em>',
        ]);

        // safeText should be escaped
        $this->assertStringContainsString('&lt;script&gt;bad&lt;/script&gt;', $output);
        // trustedHtml should not be escaped
        $this->assertStringContainsString('<em>Good</em>', $output);
    }

    public function testCombineIfWithText(): void
    {
        $source = '<div s:if="$show" s:text="$message"></div>';

        $compiled = $this->compiler->compile($source);

        // Should have if wrapper
        $this->assertStringContainsString('<?php if ($show): ?>', $compiled);
        $this->assertStringContainsString('<?php endif; ?>', $compiled);
        $this->assertStringContainsString(Escaper::class . '::html($message)', $compiled);

        // Test with condition true
        $output = $this->executeTemplate($compiled, [
            'show' => true,
            'message' => '<script>xss</script>',
        ]);

        $this->assertStringContainsString('<div>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>xss</script>', $output);

        // Test with condition false
        $output = $this->executeTemplate($compiled, [
            'show' => false,
            'message' => 'hidden',
        ]);

        $this->assertStringNotContainsString('hidden', $output);
        $this->assertStringNotContainsString('<div>', $output);
    }

    public function testCombineForeachWithText(): void
    {
        $source = '<li s:foreach="$items as $item" s:text="$item"></li>';

        $compiled = $this->compiler->compile($source);

        // Should have foreach loop
        $this->assertStringContainsString('foreach ($items as $item)', $compiled);
        $this->assertStringContainsString(Escaper::class . '::html($item)', $compiled);

        // Test execution
        $output = $this->executeTemplate($compiled, [
            'items' => ['<b>One</b>', '<i>Two</i>', 'Three'],
        ]);

        // All items should be escaped
        $this->assertStringContainsString('&lt;b&gt;One&lt;/b&gt;', $output);
        $this->assertStringContainsString('&lt;i&gt;Two&lt;/i&gt;', $output);
        $this->assertStringContainsString('Three', $output);
        $this->assertStringNotContainsString('<b>One</b>', $output);
        $this->assertStringContainsString('<li>', $output);
    }

    public function testCombineForeachWithHtml(): void
    {
        $source = '<div s:foreach="$items as $item" s:html="$item"></div>';

        $compiled = $this->compiler->compile($source);

        // Should NOT escape (s:html)
        $this->assertStringNotContainsString('Escaper::html', $compiled);

        // Test execution - HTML should pass through
        $output = $this->executeTemplate($compiled, [
            'items' => ['<strong>Bold</strong>', '<em>Italic</em>'],
        ]);

        $this->assertStringContainsString('<strong>Bold</strong>', $output);
        $this->assertStringContainsString('<em>Italic</em>', $output);
    }

    public function testCombineClassWithText(): void
    {
        $source = '<div s:class="[\'active\' => $isActive]" s:text="$content"></div>';

        $compiled = $this->compiler->compile($source);

        // Should have both class helper and escaped text
        $this->assertStringContainsString('classNames', $compiled);
        $this->assertStringContainsString(Escaper::class . '::html($content)', $compiled);

        // Test execution
        $output = $this->executeTemplate($compiled, [
            'isActive' => true,
            'content' => 'Hello',
        ]);

        $this->assertStringContainsString('class="active"', $output);
        $this->assertStringContainsString('Hello', $output);
    }

    public function testMultipleDirectivesProcessingOrder(): void
    {
        // Test that directives are processed in correct order
        $source = '<span s:if="$show" s:class="[\'highlight\']" s:text="$text"></span>';

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'show' => true,
            'text' => '<script>test</script>',
        ]);

        // All directives should work together
        $this->assertStringContainsString('<span', $output);
        $this->assertStringContainsString('class="highlight"', $output);
        $this->assertStringContainsString('&lt;script&gt;test&lt;/script&gt;', $output);
        $this->assertStringNotContainsString('<script>test</script>', $output);
    }

    public function testCompileForelseDirective(): void
    {
        $source = '<ul s:forelse="$items as $item"><li><?= $item ?></li></ul><div s:empty>No items</div>';

        $result = $this->compiler->compile($source);

        // Should contain if/else wrapper
        $this->assertStringContainsString('<?php if (!' . EmptyHelper::class . '::isEmpty($items)): ?>', $result);
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $result);
        $this->assertStringContainsString('<?php endforeach; ?>', $result);
        $this->assertStringContainsString('<?php else: ?>', $result);
        $this->assertStringContainsString('<?php endif; ?>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<div>No items</div>', $result);
    }

    public function testCompileForelseDirectiveExecutableWithItems(): void
    {
        $source = '<ul s:forelse="$items as $item"><li><?= $item ?></li></ul><div s:empty>No items found</div>';

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'items' => ['Apple', 'Banana', 'Cherry'],
        ]);

        $this->assertStringContainsString('<ul>', $output);
        $this->assertStringContainsString('<li>Apple</li>', $output);
        $this->assertStringContainsString('<li>Banana</li>', $output);
        $this->assertStringContainsString('<li>Cherry</li>', $output);
        $this->assertStringContainsString('</ul>', $output);
        $this->assertStringNotContainsString('No items found', $output);
    }

    public function testCompileForelseDirectiveExecutableWithEmptyArray(): void
    {
        $source = '<ul s:forelse="$items as $item"><li><?= $item ?></li></ul><div s:empty>No items found</div>';

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'items' => [],
        ]);

        $this->assertStringNotContainsString('<li>', $output);
        $this->assertStringContainsString('<div>No items found</div>', $output);
    }

    public function testCompileForelseDirectiveExecutableWithNullItems(): void
    {
        $source = '<ul s:forelse="$items as $item"><li><?= $item ?></li></ul><div s:empty>No items found</div>';

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'items' => null,
        ]);

        $this->assertStringNotContainsString('<li>', $output);
        $this->assertStringContainsString('<div>No items found</div>', $output);
    }

    public function testCompileForelseWithLoopVariable(): void
    {
        $source = '<ul s:forelse="$items as $item"><li><?= $loop->index ?>: <?= $item ?></li></ul><div s:empty>Empty</div>';

        $compiled = $this->compiler->compile($source);

        $output = $this->executeTemplate($compiled, [
            'items' => ['First', 'Second'],
        ]);

        $this->assertStringContainsString('0: First', $output);
        $this->assertStringContainsString('1: Second', $output);
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Compiler;
use Sugar\Escape\Escaper;
use Sugar\Parser\Parser;
use Sugar\Pass\ContextAnalysisPass;
use Sugar\Tests\ExecuteTemplateTrait;
use Sugar\Tests\TemplateTestHelperTrait;

/**
 * Test compiler orchestration
 */
final class CompilerTest extends TestCase
{
    use ExecuteTemplateTrait;
    use TemplateTestHelperTrait;

    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler(
            new Parser(),
            new ContextAnalysisPass(),
            new CodeGenerator(new Escaper()),
        );
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
        $this->assertStringContainsString('htmlspecialchars((string)($name)', $result);
        $this->assertStringContainsString('!', $result);
    }

    public function testCompileWithJavascriptContext(): void
    {
        $source = $this->loadTemplate('javascript-context.sugar.php');

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('<script>', $result);
        $this->assertStringContainsString('json_encode($data', $result);
        $this->assertStringContainsString('JSON_HEX_TAG', $result);
        $this->assertStringNotContainsString('htmlspecialchars', $result);
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
        $this->assertStringContainsString('htmlspecialchars((string)($url)', $result);
        $this->assertStringContainsString('Link</a>', $result);
    }

    public function testCompileWithRawPhp(): void
    {
        $source = $this->loadTemplate('raw-php.sugar.php');

        $result = $this->compiler->compile($source);

        $this->assertStringContainsString('<?php $x = 42; ?>', $result);
        $this->assertStringContainsString('htmlspecialchars((string)($x)', $result);
    }

    public function testCompileComplexTemplate(): void
    {
        $source = $this->loadTemplate('complex-template.sugar.php');

        $result = $this->compiler->compile($source);

        // Check title is HTML escaped
        $this->assertStringContainsString('htmlspecialchars((string)($title)', $result);

        // Check CSS context
        $this->assertStringContainsString('escapeCss((string)($bgColor))', $result);

        // Check JS context
        $this->assertStringContainsString('json_encode($config', $result);

        // Check attribute context
        $this->assertStringContainsString('htmlspecialchars((string)($link)', $result);

        // Check body HTML context
        $this->assertStringContainsString('htmlspecialchars((string)($heading)', $result);
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

    public function testMultipleContextSwitches(): void
    {
        $source = $this->loadTemplate('multiple-contexts.sugar.php');

        $result = $this->compiler->compile($source);

        // Find each output and check its escaping
        preg_match_all('/echo\s+(.+?);/', $result, $matches);
        $outputs = $matches[1];

        $this->assertCount(3, $outputs);

        // First should be HTML escaped
        $this->assertStringContainsString('htmlspecialchars((string)($html)', $outputs[0]);

        // Second should be JSON encoded (JavaScript context)
        $this->assertStringContainsString('json_encode($js', $outputs[1]);

        // Third should be HTML escaped again
        $this->assertStringContainsString('htmlspecialchars((string)($html2)', $outputs[2]);
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
}

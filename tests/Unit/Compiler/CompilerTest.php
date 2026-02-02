<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Sugar\Core\CodeGen\CodeGenerator;
use Sugar\Core\Compiler\Compiler;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Parser\Parser;
use Sugar\Core\Pass\ContextAnalysisPass;
use Sugar\Tests\TemplateTestHelperTrait;

/**
 * Test compiler orchestration
 */
final class CompilerTest extends TestCase
{
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

        // Variables used in eval scope
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $name = '<script>alert("xss")</script>';
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        $age = 25;

        ob_start();
        // phpcs:ignore Squiz.PHP.Eval.Discouraged
        eval('?>' . $compiled);
        $output = ob_get_clean();

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
}

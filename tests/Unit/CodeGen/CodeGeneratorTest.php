<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\CodeGen;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\AttributeNode;
use Sugar\Ast\DirectiveNode;
use Sugar\Ast\DocumentNode;
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\OutputNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Ast\TextNode;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Enum\OutputContext;
use Sugar\Escape\Escaper;
use Sugar\Tests\ExecuteTemplateTrait;

/**
 * Test code generation from AST
 */
final class CodeGeneratorTest extends TestCase
{
    use ExecuteTemplateTrait;

    private CodeGenerator $generator;

    private CodeGenerator $debugGenerator;

    protected function setUp(): void
    {
        $escaper = new Escaper();
        $this->generator = new CodeGenerator($escaper);
        $this->debugGenerator = new CodeGenerator($escaper, debug: true, sourceFile: 'test.sugar.php');
    }

    public function testGenerateSimpleTextNode(): void
    {
        $ast = new DocumentNode([
            new TextNode('Hello World', 1, 1),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('Hello World', $code);
        $this->assertStringContainsString('<?php', $code);
        $this->assertStringContainsString('declare(strict_types=1);', $code);
    }

    public function testGenerateEscapedOutput(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$userName', true, OutputContext::HTML, 1, 1),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('htmlspecialchars((string)($userName)', $code);
        $this->assertStringContainsString('ENT_QUOTES', $code);
        $this->assertStringNotContainsString('$escaper', $code); // Inline, not method call
    }

    public function testGenerateUnescapedOutput(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$htmlContent', false, OutputContext::RAW, 1, 1),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('echo $htmlContent', $code);
        $this->assertStringNotContainsString('htmlspecialchars', $code);
    }

    public function testGenerateJavascriptContext(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$data', true, OutputContext::JAVASCRIPT, 1, 1),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('json_encode($data', $code);
        $this->assertStringContainsString('JSON_HEX_TAG', $code);
    }

    public function testGenerateUrlContext(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$url', true, OutputContext::URL, 1, 1),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('rawurlencode((string)($url))', $code);
    }

    public function testGenerateMixedContent(): void
    {
        $ast = new DocumentNode([
            new TextNode('<div>', 1, 1),
            new OutputNode('$title', true, OutputContext::HTML, 1, 6),
            new TextNode('</div>', 1, 13),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<div>', $code);
        $this->assertStringContainsString('htmlspecialchars((string)($title)', $code);
        $this->assertStringContainsString('</div>', $code);
    }

    public function testGeneratedCodeIsExecutable(): void
    {
        $ast = new DocumentNode([
            new TextNode('Hello ', 1, 1),
            new OutputNode('$name', true, OutputContext::HTML, 1, 7),
            new TextNode('!', 1, 13),
        ]);

        $code = $this->generator->generate($ast);

        $output = $this->executeTemplate($code, [
            'name' => '<script>alert("xss")</script>',
        ]);

        $this->assertNotFalse($output);
        $this->assertStringContainsString('Hello', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('!', $output);
    }

    public function testEmptyDocument(): void
    {
        $ast = new DocumentNode([]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php', $code);
        $this->assertStringContainsString('declare(strict_types=1);', $code);
    }

    public function testGenerateRawPhpCode(): void
    {
        $ast = new DocumentNode([
            new RawPhpNode(' $x = 42; ', 1, 1),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php $x = 42; ?>', $code);
    }

    public function testGenerateRawPhpWithLogic(): void
    {
        $ast = new DocumentNode([
            new RawPhpNode(' if ($condition): ', 1, 1),
            new TextNode('Content', 1, 20),
            new RawPhpNode(' endif; ', 2, 1),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php if ($condition): ?>', $code);
        $this->assertStringContainsString('Content', $code);
        $this->assertStringContainsString('<?php endif; ?>', $code);
    }

    public function testGenerateMixedPhpAndOutput(): void
    {
        $ast = new DocumentNode([
            new RawPhpNode(' $greeting = "Hello"; ', 1, 1),
            new OutputNode('$greeting', true, OutputContext::HTML, 1, 24),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php $greeting = "Hello"; ?>', $code);
        $this->assertStringContainsString('htmlspecialchars((string)($greeting)', $code);
    }

    public function testDebugModeAddsSourceInfo(): void
    {
        $ast = new DocumentNode([
            new TextNode('Hello', 1, 0),
        ]);

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringContainsString('// Source: test.sugar.php', $code);
        $this->assertStringContainsString('// Debug mode: enabled', $code);
        $this->assertStringContainsString('// Compiled: ', $code);
    }

    public function testDebugModeDisabledShowsDefaultHeader(): void
    {
        $ast = new DocumentNode([
            new TextNode('Hello', 1, 0),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('// DO NOT EDIT - auto-generated', $code);
        $this->assertStringNotContainsString('// Source:', $code);
        $this->assertStringNotContainsString('// Debug mode:', $code);
    }

    public function testDebugCommentOnRawPhpNode(): void
    {
        $ast = new DocumentNode([
            new RawPhpNode('if ($user):', 2, 4),
        ]);

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringContainsString('/* L2:C4 */', $code);
        $this->assertStringContainsString('<?php if ($user): /* L2:C4 */ ?>', $code);
    }

    public function testDebugCommentOnOutputNode(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$user->name', true, OutputContext::HTML, 3, 8),
        ]);

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringContainsString('/* L3:C8 s:text */', $code);
    }

    public function testDebugCommentOnRawOutputNode(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$html', false, OutputContext::RAW, 4, 10),
        ]);

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringContainsString('/* L4:C10 s:html */', $code);
    }

    public function testNoDebugCommentsWhenDisabled(): void
    {
        $ast = new DocumentNode([
            new RawPhpNode('if ($user):', 2, 4),
            new OutputNode('$user->name', true, OutputContext::HTML, 3, 8),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringNotContainsString('/* L', $code);
        $this->assertStringNotContainsString('s:text', $code);
        $this->assertStringNotContainsString('<!-- L', $code);
    }

    public function testDebugModeWithoutSourceFile(): void
    {
        $generator = new CodeGenerator(new Escaper(), debug: true);
        $ast = new DocumentNode([new TextNode('Hello', 1, 0)]);

        $code = $generator->generate($ast);

        // Should not include source file info when sourceFile is null
        $this->assertStringContainsString('// DO NOT EDIT - auto-generated', $code);
        $this->assertStringNotContainsString('// Source:', $code);
        $this->assertStringNotContainsString('// Debug mode:', $code);
    }

    public function testGenerateElementWithAttributes(): void
    {
        $ast = new DocumentNode([
            new ElementNode(
                'div',
                [
                    new AttributeNode('id', 'main', 1, 1),
                    new AttributeNode('class', 'container', 1, 1),
                ],
                [new TextNode('Content', 1, 1)],
                false,
                1,
                1,
            ),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<div id="main" class="container">', $code);
        $this->assertStringContainsString('Content', $code);
        $this->assertStringContainsString('</div>', $code);
    }

    public function testGenerateSelfClosingElement(): void
    {
        $ast = new DocumentNode([
            new ElementNode(
                'img',
                [new AttributeNode('src', 'image.jpg', 1, 1)],
                [],
                true,
                1,
                1,
            ),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<img src="image.jpg" />', $code);
    }

    public function testGenerateElementWithDynamicAttribute(): void
    {
        $ast = new DocumentNode([
            new ElementNode(
                'div',
                [
                    new AttributeNode(
                        'title',
                        new OutputNode('$title', true, OutputContext::HTML_ATTRIBUTE, 1, 1),
                        1,
                        1,
                    ),
                ],
                [],
                false,
                1,
                1,
            ),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<div title="', $code);
        $this->assertStringContainsString('htmlspecialchars', $code);
    }

    public function testGenerateBooleanAttribute(): void
    {
        $ast = new DocumentNode([
            new ElementNode(
                'input',
                [new AttributeNode('disabled', null, 1, 1)],
                [],
                true,
                1,
                1,
            ),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<input disabled />', $code);
    }

    public function testGenerateFragmentNode(): void
    {
        $ast = new DocumentNode([
            new FragmentNode(
                [],
                [
                    new TextNode('First', 1, 1),
                    new TextNode('Second', 1, 1),
                ],
                1,
                1,
            ),
        ]);

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('First', $code);
        $this->assertStringContainsString('Second', $code);
        // Fragment itself should not be rendered
        $this->assertStringNotContainsString('<fragment', $code);
    }

    public function testGenerateDirectiveNode(): void
    {
        $ast = new DocumentNode([
            new DirectiveNode(
                'if',
                '$condition',
                [new TextNode('Content', 1, 1)],
                1,
                1,
            ),
        ]);

        $code = $this->generator->generate($ast);

        // DirectiveNode outputs as comment in current implementation
        $this->assertStringContainsString('<!-- Directive: if', $code);
        $this->assertStringContainsString('$condition', $code);
    }

    public function testGenerateElementInDebugMode(): void
    {
        $ast = new DocumentNode([
            new ElementNode(
                'div',
                [],
                [new TextNode('Content', 1, 1)],
                false,
                5,
                10,
            ),
        ]);

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringContainsString('<!-- L5:C10 -->', $code);
    }

    public function testGenerateSelfClosingElementInDebugMode(): void
    {
        $ast = new DocumentNode([
            new ElementNode(
                'br',
                [],
                [],
                true,
                3,
                8,
            ),
        ]);

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringContainsString('<br /> <!-- L3:C8 -->', $code);
    }

    public function testGenerateSimplePipe(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$name', true, OutputContext::HTML, 1, 1, ['upper(...)']),
        ]);

        $code = $this->generator->generate($ast);

        // Should compile to upper($name)
        $this->assertStringContainsString('upper($name)', $code);
        $this->assertStringContainsString('htmlspecialchars', $code);
    }

    public function testGenerateMultiplePipes(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$name', true, OutputContext::HTML, 1, 1, ['upper(...)', 'truncate(..., 20)']),
        ]);

        $code = $this->generator->generate($ast);

        // Should compile to truncate(upper($name), 20)
        $this->assertStringContainsString('truncate(upper($name), 20)', $code);
        $this->assertStringContainsString('htmlspecialchars', $code);
    }

    public function testGeneratePipeWithMultipleArguments(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$price', true, OutputContext::HTML, 1, 1, ['money(..., "USD", 2)']),
        ]);

        $code = $this->generator->generate($ast);

        // Should compile to money($price, "USD", 2)
        $this->assertStringContainsString('money($price, "USD", 2)', $code);
    }

    public function testGeneratePipeWithoutEscaping(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$html', false, OutputContext::RAW, 1, 1, ['upper(...)']),
        ]);

        $code = $this->generator->generate($ast);

        // Should compile to upper($html) without escaping
        $this->assertStringContainsString('echo upper($html)', $code);
        $this->assertStringNotContainsString('htmlspecialchars', $code);
    }

    public function testGeneratePipeInJavascriptContext(): void
    {
        $ast = new DocumentNode([
            new OutputNode('$data', true, OutputContext::JAVASCRIPT, 1, 1, ['upper(...)']),
        ]);

        $code = $this->generator->generate($ast);

        // Should compile to json_encode(upper($data))
        $this->assertStringContainsString('json_encode(upper($data)', $code);
        $this->assertStringContainsString('JSON_HEX_TAG', $code);
    }
}

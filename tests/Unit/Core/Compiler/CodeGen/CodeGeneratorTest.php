<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Compiler\CodeGen;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\DirectiveNode;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\RawBodyNode;
use Sugar\Core\Ast\RuntimeCallNode;
use Sugar\Core\Compiler\CodeGen\CodeGenerator;
use Sugar\Core\Escape\Enum\OutputContext;
use Sugar\Core\Escape\Escaper;
use Sugar\Core\Exception\UnsupportedNodeException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;
use Sugar\Tests\Helper\Trait\NodeBuildersTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Test code generation from AST
 */
final class CodeGeneratorTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;
    use NodeBuildersTrait;
    use TemplateTestHelperTrait;

    private CodeGenerator $generator;

    private CodeGenerator $debugGenerator;

    protected function setUp(): void
    {
        $escaper = $this->createEscaper();
        $this->generator = new CodeGenerator($escaper, $this->createContext());
        $this->debugGenerator = new CodeGenerator($escaper, $this->createContext(debug: true));
    }

    public function testGenerateSimpleTextNode(): void
    {
        $ast = $this->document()
            ->withChild($this->text('Hello World', 1, 1))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('Hello World', $code);
        $this->assertStringContainsString('<?php', $code);
        $this->assertStringContainsString('declare(strict_types=1);', $code);
    }

    public function testGenerateEscapedOutput(): void
    {
        $ast = $this->document()
            ->withChild($this->outputNode('$userName', true, OutputContext::HTML, 1, 1))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('__SugarEscaper::html($userName)', $code);
        $this->assertStringNotContainsString('$escaper', $code); // Inline, not method call
    }

    public function testGenerateUnescapedOutput(): void
    {
        $ast = $this->document()
            ->withChild($this->outputNode('$htmlContent', false, OutputContext::RAW, 1, 1))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('echo $htmlContent', $code);
        $this->assertStringNotContainsString(Escaper::class . '::html', $code);
    }

    public function testGenerateJavascriptContext(): void
    {
        $ast = $this->document()
            ->withChild($this->outputNode('$data', true, OutputContext::JAVASCRIPT, 1, 1))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('__SugarEscaper::js($data', $code);
        $this->assertStringContainsString('__SugarEscaper::js', $code);
    }

    public function testGenerateUrlContext(): void
    {
        $ast = $this->document()
            ->withChild($this->outputNode('$url', true, OutputContext::URL, 1, 1))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('__SugarEscaper::url($url)', $code);
    }

    public function testGenerateMixedContent(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('<div>', 1, 1),
                $this->outputNode('$title', true, OutputContext::HTML, 1, 6),
                $this->text('</div>', 1, 13),
            ])
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<div>', $code);
        $this->assertStringContainsString('__SugarEscaper::html($title)', $code);
        $this->assertStringContainsString('</div>', $code);
    }

    public function testGeneratedCodeIsExecutable(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->text('Hello ', 1, 1),
                $this->outputNode('$name', true, OutputContext::HTML, 1, 7),
                $this->text('!', 1, 13),
            ])
            ->build();

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
        $ast = $this->document()->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php', $code);
        $this->assertStringContainsString('declare(strict_types=1);', $code);
    }

    public function testGenerateRawPhpCode(): void
    {
        $ast = $this->document()
            ->withChild($this->rawPhp(' $x = 42; ', 1, 1))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php $x = 42; ?>', $code);
    }

    public function testGenerateRawPhpWithLogic(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->rawPhp(' if ($condition): ', 1, 1),
                $this->text('Content', 1, 20),
                $this->rawPhp(' endif; ', 2, 1),
            ])
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php if ($condition): ?>', $code);
        $this->assertStringContainsString('Content', $code);
        $this->assertStringContainsString('<?php endif; ?>', $code);
    }

    public function testGenerateTextWithPhpOpenTagRendersLiteralContent(): void
    {
        $ast = $this->document()
            ->withChild($this->text('<?php echo "hidden"; ?>', 1, 1))
            ->build();

        $code = $this->generator->generate($ast);
        $output = $this->executeTemplate($code);

        $this->assertSame('<?php echo "hidden"; ?>', $output);
    }

    public function testGenerateRawBodyNodeRendersLiteralContent(): void
    {
        $ast = $this->document()
            ->withChild(new RawBodyNode('<?= $value ?>', 1, 1))
            ->build();

        $code = $this->generator->generate($ast);
        $output = $this->executeTemplate($code, ['value' => 'executed']);

        $this->assertSame('<?= $value ?>', $output);
    }

    public function testGenerateMixedPhpAndOutput(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->rawPhp(' $greeting = "Hello"; ', 1, 1),
                $this->outputNode('$greeting', true, OutputContext::HTML, 1, 24),
            ])
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<?php $greeting = "Hello"; ?>', $code);
        $this->assertStringContainsString('__SugarEscaper::html($greeting)', $code);
    }

    public function testDebugModeAddsSourceInfo(): void
    {
        $ast = $this->document()
            ->withChild($this->text('Hello', 1, 0))
            ->build();

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringContainsString('* Compiled Sugar template', $code);
        $this->assertStringContainsString('* @link https://github.com/josbeir/sugar', $code);
        $this->assertStringContainsString('* Source: test.sugar.php', $code);
        $this->assertStringContainsString('* Debug mode: enabled', $code);
        $this->assertStringContainsString('* Compiled: ', $code);
    }

    public function testDebugModeDisabledShowsDefaultHeader(): void
    {
        $ast = $this->document()
            ->withChild($this->text('Hello', 1, 0))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('* Compiled Sugar template', $code);
        $this->assertStringContainsString('* @link https://github.com/josbeir/sugar', $code);
        $this->assertStringContainsString('* DO NOT EDIT - auto-generated', $code);
        $this->assertStringNotContainsString('* Source:', $code);
        $this->assertStringNotContainsString('* Debug mode:', $code);
    }

    public function testGenerateRuntimeCallNode(): void
    {
        $ast = $this->document()
            ->withChild(new RuntimeCallNode('strlen', ["'test'"], 1, 1))
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString("echo strlen('test')", $code);

        $output = $this->executeTemplate($code);

        $this->assertSame('4', $output);
    }

    public function testNoDebugCommentsWhenDisabled(): void
    {
        $ast = $this->document()
            ->withChildren([
                $this->rawPhp('if ($user):', 2, 4),
                $this->outputNode('$user->name', true, OutputContext::HTML, 3, 8),
            ])
            ->build();

        $code = $this->generator->generate($ast);
        $debugCode = $this->debugGenerator->generate($ast);

        $this->assertStringNotContainsString('/* sugar:', $code);
        $this->assertStringNotContainsString('/* sugar:', $debugCode);
    }

    public function testDebugModeWithoutSourceFile(): void
    {
        $generator = new CodeGenerator($this->createEscaper(), $this->createContext(templatePath: ''));
        $ast = $this->document()
            ->withChild($this->text('Hello', 1, 0))
            ->build();

        $code = $generator->generate($ast);

        // Should not include source file info when sourceFile is null
        $this->assertStringContainsString('* Compiled Sugar template', $code);
        $this->assertStringContainsString('* @link https://github.com/josbeir/sugar', $code);
        $this->assertStringContainsString('* DO NOT EDIT - auto-generated', $code);
        $this->assertStringNotContainsString('* Source:', $code);
        $this->assertStringNotContainsString('* Debug mode:', $code);
    }

    public function testGenerateElementWithAttributes(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->attribute('id', 'main')
                    ->attribute('class', 'container')
                    ->withChild($this->text('Content', 1, 1))
                    ->at(1, 1)
                    ->build(),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<div id="main" class="container">', $code);
        $this->assertStringContainsString('Content', $code);
        $this->assertStringContainsString('</div>', $code);
    }

    public function testGenerateSelfClosingElement(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('img')
                    ->attribute('src', 'image.jpg')
                    ->selfClosing()
                    ->at(1, 1)
                    ->build(),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<img src="image.jpg" />', $code);
    }

    public function testGenerateElementWithDynamicAttribute(): void
    {
        $ast = $this->document()
            ->withChild(
                new ElementNode(
                    'div',
                    [
                        new AttributeNode(
                            'title',
                            AttributeValue::output(new OutputNode('$title', true, OutputContext::HTML_ATTRIBUTE, 1, 1)),
                            1,
                            1,
                        ),
                    ],
                    [],
                    false,
                    1,
                    1,
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<div title="', $code);
        $this->assertStringContainsString('__SugarEscaper::attr', $code);
    }

    public function testGenerateDynamicTag(): void
    {
        $ast = $this->document()
            ->withChild(
                new ElementNode(
                    tag: 'div',
                    attributes: [],
                    children: [$this->text('Content', 1, 1)],
                    selfClosing: false,
                    line: 1,
                    column: 1,
                    dynamicTag: '$tagName',
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<<?= $tagName ?>', $code);
        $this->assertStringContainsString('</<?= $tagName ?>', $code);
    }

    public function testGenerateSpreadAttributeWithPipesAndEscaping(): void
    {
        $spreadOutput = new OutputNode('$attrs', true, OutputContext::HTML_ATTRIBUTE, 1, 1, ['trim(...)']);
        $ast = $this->document()
            ->withChild(
                new ElementNode(
                    tag: 'div',
                    attributes: [new AttributeNode('', AttributeValue::output($spreadOutput), 1, 1)],
                    children: [],
                    selfClosing: false,
                    line: 1,
                    column: 1,
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('$__attr =', $code);
        $this->assertStringContainsString('trim($attrs)', $code);
        $this->assertStringContainsString('__SugarEscaper::attr', $code);
        $this->assertStringContainsString('echo \' \' . $__attr', $code);
    }

    public function testGenerateBooleanAttribute(): void
    {
        $ast = $this->document()
            ->withChild(
                new ElementNode(
                    'input',
                    [new AttributeNode('disabled', AttributeValue::boolean(), 1, 1)],
                    [],
                    true,
                    1,
                    1,
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('<input disabled />', $code);
    }

    public function testGenerateFragmentNode(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->fragment(
                    attributes: [],
                    children: [
                        $this->text('First', 1, 1),
                        $this->text('Second', 1, 1),
                    ],
                    line: 1,
                    column: 1,
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('First', $code);
        $this->assertStringContainsString('Second', $code);
        // Fragment itself should not be rendered
        $this->assertStringNotContainsString('<fragment', $code);
    }

    public function testGenerateDirectiveNode(): void
    {
        $ast = $this->document()
            ->withChild(
                new DirectiveNode(
                    'if',
                    '$condition',
                    [$this->text('Content', 1, 1)],
                    1,
                    1,
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        // DirectiveNode outputs as comment in current implementation
        $this->assertStringContainsString('<!-- Directive: if', $code);
        $this->assertStringContainsString('$condition', $code);
    }

    public function testGenerateDirectiveEscapesExpression(): void
    {
        $ast = $this->document()
            ->withChild(
                new DirectiveNode(
                    'if',
                    '<script>',
                    [],
                    1,
                    1,
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        $this->assertStringContainsString('&lt;script&gt;', $code);
    }

    public function testGenerateElementInDebugMode(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('div')
                    ->withChild($this->text('Content', 1, 1))
                    ->at(5, 10)
                    ->build(),
            )
            ->build();

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringNotContainsString('<!-- L5:C10 -->', $code);
    }

    public function testGenerateSelfClosingElementInDebugMode(): void
    {
        $ast = $this->document()
            ->withChild(
                $this->element('br')
                    ->selfClosing()
                    ->at(3, 8)
                    ->build(),
            )
            ->build();

        $code = $this->debugGenerator->generate($ast);

        $this->assertStringNotContainsString('<!-- L3:C8 -->', $code);
    }

    public function testGenerateSimplePipe(): void
    {
        $ast = $this->document()
            ->withChild(new OutputNode('$name', true, OutputContext::HTML, 1, 1, ['upper(...)']))
            ->build();

        $code = $this->generator->generate($ast);

        // Should compile to upper($name)
        $this->assertStringContainsString('upper($name)', $code);
        $this->assertStringContainsString('__SugarEscaper::html', $code);
    }

    public function testGenerateMultiplePipes(): void
    {
        $ast = $this->document()
            ->withChild(new OutputNode('$name', true, OutputContext::HTML, 1, 1, ['upper(...)', 'truncate(..., 20)']))
            ->build();

        $code = $this->generator->generate($ast);

        // Should compile to truncate(upper($name), 20)
        $this->assertStringContainsString('truncate(upper($name), 20)', $code);
        $this->assertStringContainsString('__SugarEscaper::html', $code);
    }

    public function testGeneratePipeWithMultipleArguments(): void
    {
        $ast = $this->document()
            ->withChild(new OutputNode('$price', true, OutputContext::HTML, 1, 1, ['money(..., "USD", 2)']))
            ->build();

        $code = $this->generator->generate($ast);

        // Should compile to money($price, "USD", 2)
        $this->assertStringContainsString('money($price, "USD", 2)', $code);
    }

    public function testGeneratePipeWithoutEscaping(): void
    {
        $ast = $this->document()
            ->withChild(new OutputNode('$html', false, OutputContext::RAW, 1, 1, ['upper(...)']))
            ->build();

        $code = $this->generator->generate($ast);

        // Should compile to upper($html) without escaping
        $this->assertStringContainsString('echo upper($html)', $code);
        $this->assertStringNotContainsString(Escaper::class . '::html', $code);
    }

    public function testGeneratePipeInJavascriptContext(): void
    {
        $ast = $this->document()
            ->withChild(new OutputNode('$data', true, OutputContext::JAVASCRIPT, 1, 1, ['upper(...)']))
            ->build();

        $code = $this->generator->generate($ast);

        // Should compile to __SugarEscaper::js(upper($data))
        $this->assertStringContainsString('__SugarEscaper::js(upper($data)', $code);
    }

    public function testGenerateThrowsForUnsupportedNodeType(): void
    {
        // Create a custom node type that isn't handled by CodeGenerator
        $unsupportedNode = new class (1, 1) extends Node
        {
        };

        $ast = $this->document()
            ->withChild($unsupportedNode)
            ->build();

        $this->expectException(UnsupportedNodeException::class);
        $this->expectExceptionMessage('Unsupported node type:');

        $this->generator->generate($ast);
    }

    public function testGenerateElementStripsTrailingSpaceFromEmptyBooleanAttribute(): void
    {
        // An AttributeNode with empty name and boolean value writes only a single space
        // to the buffer. The trailing-space cleanup in generateElement must remove it
        // so the resulting tag does not have a double-space before '/>'.
        $ast = $this->document()
            ->withChild(
                new ElementNode(
                    tag: 'input',
                    attributes: [new AttributeNode('', AttributeValue::boolean(), 1, 1)],
                    children: [],
                    selfClosing: true,
                    line: 1,
                    column: 1,
                ),
            )
            ->build();

        $code = $this->generator->generate($ast);

        // The trailing space from the empty attribute should be stripped before " />" is appended.
        // Result must be '<input />' not '<input  />' (double space).
        $this->assertStringContainsString('<input />', $code);
        $this->assertStringNotContainsString('<input  />', $code);
    }
}

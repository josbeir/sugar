<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
use Sugar\CodeGen\CodeGenerator;
use Sugar\Compiler\Pipeline\AstPipeline;
use Sugar\Config\SugarConfig;
use Sugar\Directive\BooleanAttributeCompiler;
use Sugar\Directive\ForeachCompiler;
use Sugar\Directive\IfCompiler;
use Sugar\Pass\Directive\DirectiveCompilationPass;
use Sugar\Pass\Directive\DirectiveExtractionPass;
use Sugar\Pass\Directive\DirectivePairingPass;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\TemplateTestHelperTrait;

/**
 * Integration test: Parser → DirectiveExtractionPass → DirectiveCompilationPass → CodeGenerator
 */
final class DirectiveIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use TemplateTestHelperTrait;

    private AstPipeline $pipeline;

    private CodeGenerator $generator;

    protected function setUp(): void
    {
        $this->setUpCompiler(withDefaultDirectives: false);

        $this->registry->register('if', new IfCompiler());
        $this->registry->register('foreach', new ForeachCompiler());

        $extractionPass = new DirectiveExtractionPass($this->registry, new SugarConfig());
        $pairingPass = new DirectivePairingPass($this->registry);
        $compilationPass = new DirectiveCompilationPass($this->registry);
        $this->pipeline = new AstPipeline([
            $extractionPass,
            $pairingPass,
            $compilationPass,
        ]);
        $this->generator = new CodeGenerator($this->escaper, $this->createContext());
    }

    public function testIfDirectiveFullPipeline(): void
    {
        $template = '<div s:if="$isAdmin">Admin Panel</div>';

        // Parse
        $ast = $this->parser->parse($template);
        $this->assertInstanceOf(DocumentNode::class, $ast);

        // Extract + pair + compile directives
        $transformed = $this->pipeline->execute($ast, $this->createContext());

        // Generate code
        $code = $this->generator->generate($transformed);

        // Should contain if/endif PHP tags (control structures work)
        $this->assertStringContainsString('<?php if ($isAdmin): ?>', $code);
        $this->assertStringContainsString('<?php endif; ?>', $code);
        $this->assertStringContainsString('<div>', $code);

        // Note: Content handling between opening/closing tags is a Parser limitation
        // HtmlParser only processes individual tags, not content between them
        // This would require full HTML tree parsing which is complex
    }

    public function testForeachDirectiveFullPipeline(): void
    {
        $template = '<li s:foreach="$items as $item"><?= $item ?></li>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain foreach/endforeach
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $code);
        $this->assertStringContainsString('<?php endforeach; ?>', $code);
        $this->assertStringContainsString('<li>', $code);
    }

    public function testNestedDirectivesFullPipeline(): void
    {
        $template = '<ul s:if="$show"><li s:foreach="$items as $item"><?= $item ?></li></ul>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain nested control structures
        $this->assertStringContainsString('<?php if ($show): ?>', $code);
        $this->assertStringContainsString('<?php foreach ($items as $item): ?>', $code);
        $this->assertStringContainsString('<?php endforeach; ?>', $code);
        $this->assertStringContainsString('<?php endif; ?>', $code);
        $this->assertStringContainsString('<ul>', $code);
        $this->assertStringContainsString('<li>', $code);
    }

    public function testRawOutputFullPipeline(): void
    {
        $template = '<div><?= raw($html) ?></div>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should unwrap raw() and output without escaping
        $this->assertStringContainsString('<?php echo $html; ?>', $code);
        $this->assertStringNotContainsString('Escaper::html', $code);
        $this->assertStringNotContainsString('raw(', $code);
    }

    public function testShortRawOutputFullPipeline(): void
    {
        $template = '<div><?= r($content) ?></div>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should unwrap r() and output without escaping
        $this->assertStringContainsString('<?php echo $content; ?>', $code);
        $this->assertStringNotContainsString('Escaper::html', $code);
        $this->assertStringNotContainsString('r(', $code);
    }

    public function testRegularOutputStillEscapedFullPipeline(): void
    {
        $template = '<div><?= $userInput ?></div>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Regular output should use Escaper::html
        $this->assertStringContainsString('Escaper::html', $code);
        $this->assertStringContainsString('$userInput', $code);
    }

    public function testBooleanAttributeCheckedFullPipeline(): void
    {
        $this->registry->register('checked', new BooleanAttributeCompiler());

        $template = '<input type="checkbox" s:checked="$isSubscribed">';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain call to booleanAttribute helper
        $this->assertStringContainsString('HtmlAttributeHelper::booleanAttribute', $code);
        $this->assertStringContainsString("'checked'", $code);
        $this->assertStringContainsString('$isSubscribed', $code);
    }

    public function testBooleanAttributeSelectedFullPipeline(): void
    {
        $this->registry->register('selected', new BooleanAttributeCompiler());

        $template = '<option s:selected="$value === \'premium\'">Premium</option>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain call to booleanAttribute helper
        $this->assertStringContainsString('HtmlAttributeHelper::booleanAttribute', $code);
        $this->assertStringContainsString("'selected'", $code);
        $this->assertStringContainsString('$value === \'premium\'', $code);
    }

    public function testBooleanAttributeDisabledFullPipeline(): void
    {
        $this->registry->register('disabled', new BooleanAttributeCompiler());

        $template = '<button s:disabled="$isProcessing">Submit</button>';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should contain call to booleanAttribute helper
        $this->assertStringContainsString('HtmlAttributeHelper::booleanAttribute', $code);
        $this->assertStringContainsString("'disabled'", $code);
        $this->assertStringContainsString('$isProcessing', $code);
    }

    public function testAttributeDirectivesNoTrailingSpaces(): void
    {
        $this->registry->register('checked', new BooleanAttributeCompiler());

        $template = '<input type="text" name="email" s:checked="$subscribed">';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Check that there's no trailing space before the closing tag
        // The CodeGenerator should trim any trailing spaces after attributes
        $this->assertStringContainsString('name="email"', $code);
        $this->assertStringContainsString('booleanAttribute', $code);

        // Should NOT have space before closing tag after PHP code
        $this->assertStringNotContainsString('?> >', $code);
        // Should end cleanly with no space between PHP close tag and angle bracket
        $this->assertStringContainsString('?>>', $code);
    }

    public function testMultipleAttributeDirectivesNoTrailingSpaces(): void
    {
        $this->registry->register('checked', new BooleanAttributeCompiler());

        $template = '<input type="checkbox" s:checked="$isActive" s:checked="$isEnabled">';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should NOT have trailing space before closing angle bracket
        $this->assertStringNotContainsString(' >', $code);
    }

    public function testSelfClosingElementsNoTrailingSpaces(): void
    {
        $this->registry->register('checked', new BooleanAttributeCompiler());

        $template = '<input type="checkbox" s:checked="$subscribed" />';

        // Parse → Extract → Compile → Generate
        $ast = $this->parser->parse($template);
        $transformed = $this->pipeline->execute($ast, $this->createContext());
        $code = $this->generator->generate($transformed);

        // Should NOT have trailing double space before self-closing tag
        $this->assertStringNotContainsString('  />', $code);
        // Should have correct spacing for self-closing tag
        $this->assertStringContainsString(' />', $code);
    }
}

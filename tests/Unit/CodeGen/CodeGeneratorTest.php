<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\CodeGen;

use PHPUnit\Framework\TestCase;
use Sugar\Ast\DocumentNode;
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

    protected function setUp(): void
    {
        $this->generator = new CodeGenerator(new Escaper());
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
}

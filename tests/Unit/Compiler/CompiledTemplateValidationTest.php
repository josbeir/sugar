<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Compiler;

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Sugar\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;

final class CompiledTemplateValidationTest extends TestCase
{
    use CompilerTestTrait;

    public function testValidationDisabledAllowsInvalidCompiledPhp(): void
    {
        $this->setUpCompiler();

        $result = $this->compiler->compile('<?php if ( ?>', debug: false);

        $this->assertStringContainsString('if (', $result);
    }

    public function testValidationThrowsOnInvalidCompiledPhpInDebugMode(): void
    {
        if (!class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser is required for validation tests.');
        }

        $templates = [
            'Pages/layout/default.sugar.php' => '<s-template s:block="content"></s-template>',
        ];

        $this->setUpCompilerWithStringLoader(templates: $templates);

        $this->expectException(SyntaxException::class);

        $this->compiler->compile('<?php if ( ?>', debug: true);
    }

    public function testValidationMapsOutputSyntaxErrorsToTemplateLine(): void
    {
        if (!class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser is required for validation tests.');
        }

        $templates = [
            'Pages/layout/default.sugar.php' => '<s-template s:block="content"></s-template>',
        ];

        $this->setUpCompilerWithStringLoader(templates: $templates);

        $source = "<div>\n    <?= 'broken; ?>\n</div>";

        try {
            $this->compiler->compile($source, 'Pages/home.sugar.php', true);
            $this->fail('Expected SyntaxException for invalid output expression.');
        } catch (SyntaxException $syntaxException) {
            $this->assertSame(2, $syntaxException->templateLine);
        }
    }

    public function testValidationMapsOutputAfterRawPhpBlock(): void
    {
        if (!class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser is required for validation tests.');
        }

        $templates = [
            'Pages/layout/default.sugar.php' => '<s-template s:block="content"></s-template>',
        ];

        $this->setUpCompilerWithStringLoader(templates: $templates);

        $source = "<s-template s:extends=\"layout/default\" />\n" .
            "<s-template s:block=\"content\">\n" .
            "    <h1>HOME1235</h1>\n" .
            "    <?php\n" .
            "        \$var = 'I AM A <xss>VARIABLE';\n" .
            "        \$array = ['hello', 'world'];\n" .
            "    ?>\n" .
            "    <div>\n" .
            "        <?=  'bla; ?>\n" .
            "    </div>\n" .
            '</s-template>';

        try {
            $this->compiler->compile($source, 'Pages/home.sugar.php', true);
            $this->fail('Expected SyntaxException for invalid output expression.');
        } catch (SyntaxException $syntaxException) {
            $this->assertSame(9, $syntaxException->templateLine);
        }
    }

    public function testValidationMapsInlineOutputSyntaxErrors(): void
    {
        if (!class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser is required for validation tests.');
        }

        $templates = [
            'Pages/layout/default.sugar.php' => '<s-template s:block="content"></s-template>',
        ];

        $this->setUpCompilerWithStringLoader(templates: $templates);

        $source = "<s-template s:extends=\"layout/default\" />\n" .
            "<s-template s:block=\"content\">\n" .
            "    <h1>HOME1235</h1>\n" .
            "    <?php\n" .
            "        \$var = 'I AM A <xss>VARIABLE';\n" .
            "        \$array = ['hello', 'world'];\n" .
            "    ?>\n" .
            "    <div><?= 'bla; ?>\n" .
            "    </div>\n" .
            '</s-template>';

        try {
            $this->compiler->compile($source, 'Pages/home.sugar.php', true);
            $this->fail('Expected SyntaxException for invalid output expression.');
        } catch (SyntaxException $syntaxException) {
            $this->assertSame(8, $syntaxException->templateLine);
        }
    }
}

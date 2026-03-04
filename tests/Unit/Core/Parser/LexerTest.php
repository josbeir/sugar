<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Parser;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Parser\Lexer;
use Sugar\Core\Parser\Token;
use Sugar\Core\Parser\TokenType;

final class LexerTest extends TestCase
{
    public function testTokenizeHandlesPhpBlocksCommentsAndSpecialTags(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = 'Before<!-- note --><![CDATA[data]]><?xml version="1.0"?><!DOCTYPE html><?php $a = 1; ?><?= $a ?><? $b = 2; ?>After';

        $tokens = $lexer->tokenize($source);
        $types = $this->tokenTypes($tokens);

        $this->assertContains(TokenType::Comment, $types);
        $this->assertContains(TokenType::SpecialTag, $types);
        $this->assertContains(TokenType::PhpBlockOpen, $types);
        $this->assertContains(TokenType::PhpCode, $types);
        $this->assertContains(TokenType::PhpClose, $types);
        $this->assertContains(TokenType::PhpOutputOpen, $types);
        $this->assertContains(TokenType::PhpExpression, $types);
    }

    public function testTokenizeHandlesQuotedUnquotedSpreadAndEmbeddedOutputAttributes(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = '<div disabled class="a<?= $b ?>c" data="x\\"y" raw=plain expr=<?= $spread ?> />';

        $tokens = $lexer->tokenize($source);
        $types = $this->tokenTypes($tokens);
        $values = $this->tokenValues($tokens);

        $this->assertContains(TokenType::AttributeName, $types);
        $this->assertContains(TokenType::QuoteOpen, $types);
        $this->assertContains(TokenType::AttributeText, $types);
        $this->assertContains(TokenType::AttributeValueUnquoted, $types);
        $this->assertContains(TokenType::PhpOutputOpen, $types);
        $this->assertContains(TokenType::PhpExpression, $types);
        $this->assertContains('/>', $values);
        $this->assertContains('disabled', $values);
        $this->assertContains('raw', $values);
        $this->assertContains('expr', $values);
    }

    public function testTokenizeEmitsRawBodyForConfiguredRawDirectivePrefix(): void
    {
        $lexer = new Lexer(SugarConfig::withPrefix('x'));
        $source = '<x-template x:raw><span><?= $value ?></span></x-template>tail';

        $tokens = $lexer->tokenize($source);
        $types = $this->tokenTypes($tokens);
        $values = $this->tokenValues($tokens);

        $this->assertContains(TokenType::RawBody, $types);
        $this->assertContains('<span><?= $value ?></span>', $values);
        $this->assertNotContains(TokenType::PhpOutputOpen, $types);
    }

    public function testTokenizeTreatsConfiguredVoidTagAsSelfClosing(): void
    {
        $lexer = new Lexer(new SugarConfig(selfClosingTags: ['br']));

        $tokens = $lexer->tokenize('<br>');

        $this->assertSame('/>', $tokens[2]->value);
        $this->assertSame(TokenType::TagClose, $tokens[2]->type);
    }

    public function testTokenizeHandlesUnclosedQuotedAttributeValue(): void
    {
        $lexer = new Lexer(new SugarConfig());

        $tokens = $lexer->tokenize('<div title="unterminated');
        $types = $this->tokenTypes($tokens);

        $this->assertContains(TokenType::QuoteOpen, $types);
        $this->assertContains(TokenType::AttributeText, $types);
        $this->assertContains(TokenType::Eof, $types);
    }

    public function testTokenizeHandlesTagEndingAfterWhitespaceWithoutCloseToken(): void
    {
        $lexer = new Lexer(new SugarConfig());

        $tokens = $lexer->tokenize('<div ');
        $types = $this->tokenTypes($tokens);

        $this->assertContains(TokenType::TagOpen, $types);
        $this->assertContains(TokenType::TagName, $types);
        $this->assertContains(TokenType::Eof, $types);
    }

    public function testTokenizeHandlesInvalidAttributeStartAndUnclosedRawRegion(): void
    {
        $lexer = new Lexer(new SugarConfig());

        $invalidAttrTokens = $lexer->tokenize('<div "broken">x</div>');
        $invalidValues = $this->tokenValues($invalidAttrTokens);
        $this->assertContains('div', $invalidValues);
        $this->assertContains('x', $invalidValues);

        $rawTokens = $lexer->tokenize('<div s:raw>inner');
        $rawTypes = $this->tokenTypes($rawTokens);
        $this->assertNotContains(TokenType::RawBody, $rawTypes);
    }

    public function testPrivateBranchCoverageForLexerHelpers(): void
    {
        $lexer = new Lexer(new SugarConfig());

        $this->setLexerState($lexer, 'abc', 0, null);
        $this->assertFalse($this->invokePrivate($lexer, 'isProcessingInstruction'));

        $this->setLexerState($lexer, '<?', 0, [0]);
        $this->assertFalse($this->invokePrivate($lexer, 'isProcessingInstruction'));

        $this->setLexerState($lexer, '<', 0, [0]);
        $this->assertFalse($this->invokePrivate($lexer, 'isTagStart'));

        $this->setLexerState($lexer, 'x', 0, [0]);
        $this->assertNull($this->invokePrivate($lexer, 'extractSimpleTag', [0]));

        $this->setLexerState($lexer, '</>', 0, [0]);
        $this->assertNull($this->invokePrivate($lexer, 'extractSimpleTag', [0]));

        $this->setLexerState($lexer, '</div', 0, [0]);
        $this->assertNull($this->invokePrivate($lexer, 'extractSimpleTag', [0]));

        $this->setLexerState($lexer, '<-div>', 0, [0]);
        $this->assertNull($this->invokePrivate($lexer, 'extractSimpleTag', [0]));

        $this->setLexerState($lexer, '<div', 0, [0]);
        $this->assertNull($this->invokePrivate($lexer, 'extractSimpleTag', [0]));
        $this->assertNull($this->invokePrivate($lexer, 'findSimpleTagEnd', [1]));

        $this->setLexerState($lexer, '<div><div></div></div>', 0, [0]);
        $this->assertSame(16, $this->invokePrivate($lexer, 'findMatchingCloseTag', ['div', 5]));

        $this->setLexerState($lexer, '<div><span>', 0, [0]);
        $this->assertNull($this->invokePrivate($lexer, 'findMatchingCloseTag', ['div', 5]));

        $this->assertTrue($this->invokePrivate($lexer, 'isTagNameChar', ['_']));
        $this->assertTrue($this->invokePrivate($lexer, 'isTagNameChar', [':']));
        $this->assertTrue($this->invokePrivate($lexer, 'isAttributeNameChar', ['.']));
        $this->assertSame([1, 1], $this->invokePrivate($lexer, 'lineColumnAt', [-1]));
    }

    public function testPrivateEmitAttributesFromStringSkipsRawAndParsesEscapedValues(): void
    {
        $lexer = new Lexer(new SugarConfig());

        $this->setLexerState($lexer, "\n\n", 0, [0, 1, 2]);
        $this->setPrivateProperty($lexer, 'tokens', []);

        $this->invokePrivate($lexer, 'emitAttributesFromString', ['   ', 's:raw', 0]);
        $this->invokePrivate(
            $lexer,
            'emitAttributesFromString',
            [' s:raw=   "x" s:raw=test id=   "a\\"b" data=ok', 's:raw', 0],
        );

        /** @var array<Token> $tokens */
        $tokens = $this->getPrivateProperty($lexer, 'tokens');
        $values = $this->tokenValues($tokens);

        $this->assertContains('id', $values);
        $this->assertContains('data', $values);
        $this->assertContains('a\\"b', $values);
        $this->assertContains('ok', $values);
        $this->assertNotContains('s:raw', $values);
    }

    public function testPhpBlockCommentContainingCloseTagIsKeptIntact(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = "<?php /*\n\$tags = (array)\$page->taxonomies['tags'];\n*/ ?>";

        $tokens = $lexer->tokenize($source);
        $codeTokens = array_values(array_filter(
            $tokens,
            static fn(Token $t): bool => $t->type === TokenType::PhpCode,
        ));

        $this->assertCount(1, $codeTokens, 'Block comment should produce exactly one PhpCode token');
        $this->assertStringContainsString('/*', $codeTokens[0]->value);
        $this->assertStringContainsString('*/', $codeTokens[0]->value);
    }

    public function testPhpBlockCommentWithNestedPhpTagsIsKeptIntact(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = "<?php /*\n<?php \$tags = []; ?>\n<div>html</div>\n*/ ?>";

        $tokens = $lexer->tokenize($source);
        $codeTokens = array_values(array_filter(
            $tokens,
            static fn(Token $t): bool => $t->type === TokenType::PhpCode,
        ));

        $this->assertCount(1, $codeTokens, 'Nested PHP tags inside block comment should not split the token');
        $this->assertStringContainsString('/*', $codeTokens[0]->value);
        $this->assertStringContainsString('*/', $codeTokens[0]->value);
    }

    public function testPhpSingleLineCommentCloseTagTerminatesComment(): void
    {
        $lexer = new Lexer(new SugarConfig());
        // In PHP, single-line comments ARE terminated by close tags
        $source = '<?php // comment ?><div>html</div>';

        $tokens = $lexer->tokenize($source);
        $types = $this->tokenTypes($tokens);
        $values = $this->tokenValues($tokens);

        $this->assertContains(TokenType::PhpClose, $types);
        $this->assertContains(TokenType::Text, $types);
        $this->assertContains('html', $values);
    }

    public function testPhpStringLiteralContainingCloseTagIsKeptIntact(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = '<?php $x = "some ?> text"; ?>';

        $tokens = $lexer->tokenize($source);
        $codeTokens = array_values(array_filter(
            $tokens,
            static fn(Token $t): bool => $t->type === TokenType::PhpCode,
        ));

        $this->assertCount(1, $codeTokens);
        $this->assertStringContainsString('"some ?> text"', $codeTokens[0]->value);
    }

    public function testPhpSingleQuotedStringContainingCloseTagIsKeptIntact(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = "<?php \$x = 'some ?> text'; ?>";

        $tokens = $lexer->tokenize($source);
        $codeTokens = array_values(array_filter(
            $tokens,
            static fn(Token $t): bool => $t->type === TokenType::PhpCode,
        ));

        $this->assertCount(1, $codeTokens);
        $this->assertStringContainsString("'some ?> text'", $codeTokens[0]->value);
    }

    public function testPhpStringWithEscapedQuotesAndCloseTag(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = '<?php $x = "val \\"?> still"; ?>';

        $tokens = $lexer->tokenize($source);
        $codeTokens = array_values(array_filter(
            $tokens,
            static fn(Token $t): bool => $t->type === TokenType::PhpCode,
        ));

        $this->assertCount(1, $codeTokens);
        $this->assertStringContainsString('still', $codeTokens[0]->value);
    }

    public function testPhpCloseTagOutsideCommentOrStringStillTerminates(): void
    {
        $lexer = new Lexer(new SugarConfig());
        $source = '<?php $a = 1; ?>HTML';

        $tokens = $lexer->tokenize($source);
        $types = $this->tokenTypes($tokens);

        $this->assertContains(TokenType::PhpClose, $types);
        $this->assertContains(TokenType::Text, $types);
    }

    /**
     * @param array<Token> $tokens
     * @return array<TokenType>
     */
    private function tokenTypes(array $tokens): array
    {
        return array_map(static fn(Token $token): TokenType => $token->type, $tokens);
    }

    /**
     * @param array<Token> $tokens
     * @return array<string>
     */
    private function tokenValues(array $tokens): array
    {
        return array_map(static fn(Token $token): string => $token->value, $tokens);
    }

    /**
     * @param array<mixed> $args
     */
    private function invokePrivate(object $instance, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($instance);
        $reflectionMethod = $reflection->getMethod($method);

        return $reflectionMethod->invokeArgs($instance, $args);
    }

    private function setPrivateProperty(object $instance, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($instance);
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setValue($instance, $value);
    }

    private function getPrivateProperty(object $instance, string $property): mixed
    {
        $reflection = new ReflectionClass($instance);
        $reflectionProperty = $reflection->getProperty($property);

        return $reflectionProperty->getValue($instance);
    }

    /**
     * @param array<int>|null $lineOffsets
     */
    private function setLexerState(Lexer $lexer, string $source, int $pos, ?array $lineOffsets): void
    {
        $this->setPrivateProperty($lexer, 'source', $source);
        $this->setPrivateProperty($lexer, 'length', strlen($source));
        $this->setPrivateProperty($lexer, 'pos', $pos);
        $this->setPrivateProperty($lexer, 'lineOffsets', $lineOffsets);
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Parser\Token;

final class TokenTest extends TestCase
{
    public function testTokenizeBasicTemplate(): void
    {
        $source = '<h1><?= $title ?></h1>';
        $tokens = Token::tokenize($source);

        $this->assertNotEmpty($tokens);
        $this->assertContainsOnlyInstancesOf(Token::class, $tokens);
    }

    public function testIsHtml(): void
    {
        $tokens = Token::tokenize('<div>text</div><?= $var ?>');

        $htmlTokens = array_filter($tokens, fn(Token $t): bool => $t->isHtml());
        $this->assertNotEmpty($htmlTokens);
    }

    public function testIsOutput(): void
    {
        $tokens = Token::tokenize('<?= $var ?>');

        $outputTokens = array_filter($tokens, fn(Token $t): bool => $t->isOutput());
        $this->assertCount(1, $outputTokens);
    }

    public function testContainsHtml(): void
    {
        $tokens = Token::tokenize('<div>text</div>');

        $token = $tokens[0];
        $this->assertTrue($token->containsHtml());
    }

    public function testTokenizeComplexTemplate(): void
    {
        $source = <<<'SUGAR'
<!DOCTYPE html>
<html>
<head>
    <title><?= $title ?></title>
</head>
<body>
    <h1><?= $heading ?></h1>
    <script>
        var data = <?= json_encode($data) ?>;
    </script>
</body>
</html>
SUGAR;

        $tokens = Token::tokenize($source);

        $this->assertGreaterThan(5, count($tokens));

        // Should have HTML tokens
        $htmlTokens = array_filter($tokens, fn(Token $t): bool => $t->isHtml());
        $this->assertNotEmpty($htmlTokens);

        // Should have output tokens
        $outputTokens = array_filter($tokens, fn(Token $t): bool => $t->isOutput());
        $this->assertGreaterThan(1, count($outputTokens));
    }
}

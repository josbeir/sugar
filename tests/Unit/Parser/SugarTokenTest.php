<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Parser\SugarToken;

final class SugarTokenTest extends TestCase
{
    public function testTokenizeBasicTemplate(): void
    {
        $source = '<h1><?= $title ?></h1>';
        $tokens = SugarToken::tokenize($source);

        $this->assertNotEmpty($tokens);
        $this->assertContainsOnlyInstancesOf(SugarToken::class, $tokens);
    }

    public function testIsHtml(): void
    {
        $tokens = SugarToken::tokenize('<div>text</div><?= $var ?>');

        $htmlTokens = array_filter($tokens, fn(SugarToken $t): bool => $t->isHtml());
        $this->assertNotEmpty($htmlTokens);
    }

    public function testIsOutput(): void
    {
        $tokens = SugarToken::tokenize('<?= $var ?>');

        $outputTokens = array_filter($tokens, fn(SugarToken $t): bool => $t->isOutput());
        $this->assertCount(1, $outputTokens);
    }

    public function testContainsHtml(): void
    {
        $tokens = SugarToken::tokenize('<div>text</div>');

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

        $tokens = SugarToken::tokenize($source);

        $this->assertGreaterThan(5, count($tokens));

        // Should have HTML tokens
        $htmlTokens = array_filter($tokens, fn(SugarToken $t): bool => $t->isHtml());
        $this->assertNotEmpty($htmlTokens);

        // Should have output tokens
        $outputTokens = array_filter($tokens, fn(SugarToken $t): bool => $t->isOutput());
        $this->assertGreaterThan(1, count($outputTokens));
    }
}

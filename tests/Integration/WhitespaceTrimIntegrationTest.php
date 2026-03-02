<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Integration tests for compile-time whitespace trimming via s:trim.
 */
final class WhitespaceTrimIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use ExecuteTemplateTrait;

    protected function setUp(): void
    {
        $this->setUpCompiler();
    }

    public function testTrimCompactsTitleOutputFromDirectiveAndOutputNodes(): void
    {
        $template = <<<'SUGAR'
<title s:trim>
    <s-template s:if="$hasPageTitle">Glaze Documentation | </s-template>
    <?= $siteTitle ?>
</title>
SUGAR;

        $compiled = $this->compiler->compile($template, 'title.sugar.php');

        $output = $this->executeTemplate($compiled, [
            'hasPageTitle' => true,
            'siteTitle' => 'Glaze',
        ]);

        $this->assertSame('<title>Glaze Documentation | Glaze</title>', trim($output));
    }

    public function testWithoutTrimPreservesTemplateWhitespaceAroundChildren(): void
    {
        $template = <<<'SUGAR'
<title>
    <s-template s:if="$hasPageTitle">Glaze Documentation | </s-template>
    <?= $siteTitle ?>
</title>
SUGAR;

        $compiled = $this->compiler->compile($template, 'title-no-trim.sugar.php');

        $output = $this->executeTemplate($compiled, [
            'hasPageTitle' => true,
            'siteTitle' => 'Glaze',
        ]);

        $this->assertStringContainsString("\n", $output);
        $this->assertStringContainsString('Glaze Documentation | ', $output);
        $this->assertStringContainsString('Glaze', $output);
        $this->assertStringContainsString('</title>', $output);
    }
}

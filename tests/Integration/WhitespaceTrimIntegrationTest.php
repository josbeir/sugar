<?php
declare(strict_types=1);

namespace Sugar\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\SyntaxException;
use Sugar\Tests\Helper\Trait\CompilerTestTrait;
use Sugar\Tests\Helper\Trait\EngineTestTrait;
use Sugar\Tests\Helper\Trait\ExecuteTemplateTrait;

/**
 * Integration tests for compile-time whitespace trimming via s:trim.
 */
final class WhitespaceTrimIntegrationTest extends TestCase
{
    use CompilerTestTrait;
    use EngineTestTrait;
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

    public function testTrimCompactsMultilineAndTabIndentedDirectiveContent(): void
    {
        $template = <<<'SUGAR'
<title s:trim>
		<s-template s:if="$hasPageTitle">
			Glaze Documentation |
		</s-template>
		<?= $siteTitle ?>
</title>
SUGAR;

        $compiled = $this->compiler->compile($template, 'title-tabs.sugar.php');

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

    public function testTrimAppliesRecursivelyToNestedDescendantChildren(): void
    {
        $template = <<<'SUGAR'
<title s:trim>
    <span>
		Glaze
		<strong>
			Docs
		</strong>
		Site
    </span>
</title>
SUGAR;

        $compiled = $this->compiler->compile($template, 'title-recursive-trim.sugar.php');
        $output = $this->executeTemplate($compiled);

        $this->assertSame('<title><span>Glaze <strong>Docs</strong> Site</span></title>', trim($output));
    }

    public function testTrimNormalizesIncludedPartialOutput(): void
    {
        $engine = $this->createStringEngine(templates: [
            'root.sugar.php' => '<title s:trim><s-template s:include="partial.sugar.php" /></title>',
            'partial.sugar.php' => "\n  Glaze\n",
        ]);

        $this->assertSame('<title>Glaze</title>', $engine->render('root.sugar.php'));
    }

    public function testTrimNormalizesIncludedPartialInsideElement(): void
    {
        $engine = $this->createStringEngine(templates: [
            'root.sugar.php' => '<title s:trim><div s:include="partial.sugar.php"></div></title>',
            'partial.sugar.php' => "\n  Glaze\n",
        ]);

        $this->assertSame('<title><div>Glaze</div></title>', $engine->render('root.sugar.php'));
    }

    public function testTrimNormalizesDefaultBlockContent(): void
    {
        $engine = $this->createStringEngine(templates: [
            'layout.sugar.php' => "<title s:trim><s-template s:block=\"content\">\n  Default\n</s-template></title>",
            'child.sugar.php' => '<s-template s:extends="layout.sugar.php" />',
        ]);

        $this->assertSame('<title>Default</title>', $engine->render('child.sugar.php'));
    }

    public function testTrimNormalizesChildBlockOverride(): void
    {
        $engine = $this->createStringEngine(templates: [
            'layout.sugar.php' => '<title s:trim><s-template s:block="content">Default</s-template></title>',
            'child.sugar.php' => "<s-template s:extends=\"layout.sugar.php\" />\n<s-template s:block=\"content\">\n  Glaze\n</s-template>",
        ]);

        $this->assertSame('<title>Glaze</title>', $engine->render('child.sugar.php'));
    }

    public function testTrimNormalizesIncludeWithMultilineWhitespace(): void
    {
        $engine = $this->createStringEngine(templates: [
            'root.sugar.php' => '<title s:trim><s-template s:include="partial.sugar.php" /></title>',
            'partial.sugar.php' => "\n\t\tGlaze Documentation |\n\t\t",
        ]);

        $this->assertSame('<title>Glaze Documentation |</title>', $engine->render('root.sugar.php'));
    }

    public function testTrimDoesNotAffectIncludeOutsideTrimScope(): void
    {
        $engine = $this->createStringEngine(templates: [
            'root.sugar.php' => '<title><s-template s:include="partial.sugar.php" /></title>',
            'partial.sugar.php' => "\n  Glaze\n",
        ]);

        $output = $engine->render('root.sugar.php');
        $this->assertStringContainsString("\n", $output);
        $this->assertStringContainsString('Glaze', $output);
    }

    public function testTrimOnTemplateFragmentFailsWithClearError(): void
    {
        $template = <<<'SUGAR'
<s-template s:trim>
    <p>Body</p>
</s-template>
SUGAR;

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:trim is only supported on HTML elements, not on <s-template>.');

        $this->compiler->compile($template, 'trim-fragment.sugar.php');
    }

    public function testTrimWithValueFailsWithClearError(): void
    {
        $template = <<<'SUGAR'
<title s:trim="yes">
    <?= $siteTitle ?>
</title>
SUGAR;

        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('s:trim does not accept a value; use it as a presence-only attribute.');

        $this->compiler->compile($template, 'trim-value.sugar.php');
    }
}

<?php
declare(strict_types=1);

namespace Sugar\Tests\Unit\Core\Runtime;

use PHPUnit\Framework\TestCase;
use Sugar\Core\Exception\TemplateRuntimeException;
use Sugar\Core\Runtime\BlockManager;

/**
 * Unit tests for BlockManager.
 *
 * Verifies block definition, rendering, parent fallback, multi-level
 * inheritance, append/prepend semantics, and error handling.
 */
final class BlockManagerTest extends TestCase
{
    private BlockManager $manager;

    protected function setUp(): void
    {
        $this->manager = new BlockManager();
    }

    public function testHasLevelsReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse($this->manager->hasLevels());
    }

    public function testHasLevelsReturnsTrueAfterPush(): void
    {
        $this->manager->pushLevel();

        $this->assertTrue($this->manager->hasLevels());
    }

    public function testPushAndPopLevels(): void
    {
        $this->manager->pushLevel();
        $this->manager->pushLevel();
        $this->assertTrue($this->manager->hasLevels());

        $this->manager->popLevel();
        $this->assertTrue($this->manager->hasLevels());

        $this->manager->popLevel();
        $this->assertFalse($this->manager->hasLevels());
    }

    public function testDefineBlockCreatesLevelImplicitly(): void
    {
        $this->manager->defineBlock('content', fn(array $data): string => 'hello');

        $this->assertTrue($this->manager->hasLevels());
    }

    public function testRenderBlockFallsBackToDefault(): void
    {
        $default = fn(array $data): string => 'default content';
        $result = $this->manager->renderBlock('content', $default, []);

        $this->assertSame('default content', $result);
    }

    public function testRenderBlockUsesChildOverride(): void
    {
        $this->manager->defineBlock('content', fn(array $data): string => 'child content');

        $default = fn(array $data): string => 'parent default';
        $result = $this->manager->renderBlock('content', $default, []);

        $this->assertSame('child content', $result);
    }

    public function testRenderBlockPassesDataToOverride(): void
    {
        $this->manager->defineBlock('title', function (array $data): string {
            $name = $data['name'] ?? '';

            return 'Title: ' . (is_string($name) ? $name : '');
        });

        $result = $this->manager->renderBlock('title', fn(array $data): string => '', ['name' => 'Test']);

        $this->assertSame('Title: Test', $result);
    }

    public function testRenderBlockPassesDataToDefault(): void
    {
        $default = function (array $data): string {
            $greeting = $data['greeting'] ?? '';

            return 'Default: ' . (is_string($greeting) ? $greeting : '');
        };
        $result = $this->manager->renderBlock('sidebar', $default, ['greeting' => 'Hello']);

        $this->assertSame('Default: Hello', $result);
    }

    public function testMultiLevelInheritanceMostDerivedWins(): void
    {
        // Grandparent level (pushed first, becomes index 0)
        $this->manager->pushLevel();
        $this->manager->defineBlock('content', fn(array $data): string => 'grandparent');

        // Parent level (index 1)
        $this->manager->pushLevel();
        $this->manager->defineBlock('content', fn(array $data): string => 'parent');

        // Child level (index 2) — NOT defined, so parent should win

        $default = fn(array $data): string => 'layout default';
        $result = $this->manager->renderBlock('content', $default, []);

        // Level 0 (grandparent) is searched first since it's the most-derived
        $this->assertSame('grandparent', $result);
    }

    public function testMultiLevelInheritanceChildOverridesAll(): void
    {
        // Layout has default content
        // Parent defines override → pushed as level 0
        $this->manager->defineBlock('content', fn(array $data): string => 'parent override');
        $this->manager->pushLevel();

        // Child defines override → pushed as level 1, but defineBlock at last level
        // Actually let's model it properly: child defines blocks, then pushes level, then parent renders
        $this->manager->reset();

        // Child defines blocks first (before extends call pushes)
        $this->manager->defineBlock('content', fn(array $data): string => 'child override');

        // renderExtends pushes a level for the parent
        $this->manager->pushLevel();

        // Parent defines its block (at the new level)
        $this->manager->defineBlock('content', fn(array $data): string => 'parent override');

        // Layout's renderBlock searches from level 0 (child) first
        $default = fn(array $data): string => 'layout default';
        $result = $this->manager->renderBlock('content', $default, []);

        $this->assertSame('child override', $result);
    }

    public function testRenderBlockOnlyMatchesNamedBlock(): void
    {
        $this->manager->defineBlock('sidebar', fn(array $data): string => 'sidebar content');

        // Asking for 'content' should fall back to default
        $result = $this->manager->renderBlock('content', fn(array $data): string => 'default', []);

        $this->assertSame('default', $result);
    }

    public function testHasDefinedBlockReturnsFalseWhenNotDefined(): void
    {
        $this->assertFalse($this->manager->hasDefinedBlock('sidebar'));
    }

    public function testHasDefinedBlockReturnsTrueWhenDefined(): void
    {
        $this->manager->defineBlock('sidebar', fn(array $data): string => 'sidebar content');

        $this->assertTrue($this->manager->hasDefinedBlock('sidebar'));
    }

    public function testRenderParentThrowsOutsideBlockRendering(): void
    {
        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Cannot call renderParent() for block "content" outside of block rendering');

        $this->manager->renderParent('content', fn(array $data): string => '', []);
    }

    public function testRenderParentFallsBackToDefault(): void
    {
        // Child defines a block that calls renderParent internally
        $manager = $this->manager;
        $parentDefault = fn(array $data): string => 'parent default content';

        $this->manager->defineBlock('content', function (array $data) use ($manager, $parentDefault): string {
            // Inside a renderBlock call, renderParent should work
            $parent = $manager->renderParent('content', $parentDefault, $data);

            return 'Child: ' . $parent;
        });

        $result = $this->manager->renderBlock('content', $parentDefault, []);

        $this->assertSame('Child: parent default content', $result);
    }

    public function testRenderParentGetsNextLevelUp(): void
    {
        $manager = $this->manager;

        // Child defines block that calls renderParent (level 0)
        $this->manager->defineBlock('content', function (array $data) use ($manager): string {
            $parent = $manager->renderParent('content', fn(array $d): string => 'never', $data);

            return 'CHILD + ' . $parent;
        });

        // Push level for parent
        $this->manager->pushLevel();

        // Parent defines block (level 1) — this is what renderParent should find
        $this->manager->defineBlock('content', fn(array $data): string => 'PARENT');

        $default = fn(array $data): string => 'LAYOUT DEFAULT';

        $result = $this->manager->renderBlock('content', $default, []);

        $this->assertSame('CHILD + PARENT', $result);
    }

    public function testThreeLevelInheritanceWithRenderParent(): void
    {
        $manager = $this->manager;

        // Child (level 0) — calls renderParent
        $this->manager->defineBlock('content', function (array $data) use ($manager): string {
            $parent = $manager->renderParent('content', fn(array $d): string => '', $data);

            return 'CHILD[' . $parent . ']';
        });

        // Push for parent
        $this->manager->pushLevel();

        // Parent (level 1) — also calls renderParent
        $this->manager->defineBlock('content', function (array $data) use ($manager): string {
            $parent = $manager->renderParent('content', fn(array $d): string => '', $data);

            return 'PARENT[' . $parent . ']';
        });

        // Push for grandparent (layout)
        $this->manager->pushLevel();

        // Layout default
        $layoutDefault = fn(array $data): string => 'LAYOUT';

        $result = $this->manager->renderBlock('content', $layoutDefault, []);

        $this->assertSame('CHILD[PARENT[LAYOUT]]', $result);
    }

    public function testMultipleBlocksIndependent(): void
    {
        $this->manager->defineBlock('header', fn(array $data): string => 'Custom Header');
        $this->manager->defineBlock('content', fn(array $data): string => 'Custom Content');

        $headerResult = $this->manager->renderBlock('header', fn(array $data): string => 'Default Header', []);
        $contentResult = $this->manager->renderBlock('content', fn(array $data): string => 'Default Content', []);
        $footerResult = $this->manager->renderBlock('footer', fn(array $data): string => 'Default Footer', []);

        $this->assertSame('Custom Header', $headerResult);
        $this->assertSame('Custom Content', $contentResult);
        $this->assertSame('Default Footer', $footerResult);
    }

    public function testResetClearsAllState(): void
    {
        $this->manager->pushLevel();
        $this->manager->defineBlock('test', fn(array $data): string => 'value');

        $this->manager->reset();

        $this->assertFalse($this->manager->hasLevels());

        // After reset, renderBlock should fall back to default
        $result = $this->manager->renderBlock('test', fn(array $data): string => 'default', []);
        $this->assertSame('default', $result);
    }

    public function testPopLevelRemovesBlockDefinitions(): void
    {
        $this->manager->pushLevel();
        $this->manager->defineBlock('content', fn(array $data): string => 'level 0');

        $this->manager->pushLevel();
        $this->manager->defineBlock('content', fn(array $data): string => 'level 1');

        // Pop level 1
        $this->manager->popLevel();

        // Should find level 0 block
        $result = $this->manager->renderBlock('content', fn(array $data): string => 'default', []);
        $this->assertSame('level 0', $result);
    }

    public function testAppendPattern(): void
    {
        // Append: parent content first, then child content
        $manager = $this->manager;

        $this->manager->defineBlock('sidebar', function (array $data) use ($manager): string {
            $parent = $manager->renderParent('sidebar', fn(array $d): string => '', $data);

            return $parent . ' + CHILD SIDEBAR';
        });

        $this->manager->pushLevel();

        $default = fn(array $data): string => 'PARENT SIDEBAR';
        $result = $this->manager->renderBlock('sidebar', $default, []);

        $this->assertSame('PARENT SIDEBAR + CHILD SIDEBAR', $result);
    }

    public function testPrependPattern(): void
    {
        // Prepend: child content first, then parent content
        $manager = $this->manager;

        $this->manager->defineBlock('sidebar', function (array $data) use ($manager): string {
            $parent = $manager->renderParent('sidebar', fn(array $d): string => '', $data);

            return 'CHILD SIDEBAR + ' . $parent;
        });

        $this->manager->pushLevel();

        $default = fn(array $data): string => 'PARENT SIDEBAR';
        $result = $this->manager->renderBlock('sidebar', $default, []);

        $this->assertSame('CHILD SIDEBAR + PARENT SIDEBAR', $result);
    }
}

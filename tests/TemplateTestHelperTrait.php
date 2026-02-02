<?php
declare(strict_types=1);

namespace Sugar\Tests;

use RuntimeException;

/**
 * Test helper trait for loading template fixtures
 *
 * Provides DRY methods for loading templates and expected outputs
 */
trait TemplateTestHelperTrait
{
    private string $fixturesPath = __DIR__ . '/fixtures/templates/';

    private string $expectedCompiledPath = __DIR__ . '/fixtures/expected/compiled/';

    private string $expectedRenderedPath = __DIR__ . '/fixtures/expected/rendered/';

    /**
     * Load a template fixture
     */
    protected function loadTemplate(string $name): string
    {
        $path = $this->fixturesPath . $name;

        if (!file_exists($path)) {
            throw new RuntimeException('Template fixture not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read template fixture: ' . $path);
        }

        return $content;
    }

    /**
     * Load expected compiled PHP code
     */
    protected function loadExpectedCompiled(string $name): string
    {
        $path = $this->expectedCompiledPath . $name;

        if (!file_exists($path)) {
            throw new RuntimeException('Expected compiled fixture not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read expected compiled fixture: ' . $path);
        }

        return $content;
    }

    /**
     * Load expected rendered output
     */
    protected function loadExpectedRendered(string $name): string
    {
        $path = $this->expectedRenderedPath . $name;

        if (!file_exists($path)) {
            throw new RuntimeException('Expected rendered fixture not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Failed to read expected rendered fixture: ' . $path);
        }

        return $content;
    }
}

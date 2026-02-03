<?php
declare(strict_types=1);

namespace Sugar\TemplateInheritance;

use Sugar\Exception\TemplateNotFoundException;

final readonly class FileTemplateLoader implements TemplateLoaderInterface
{
    /**
     * Constructor.
     *
     * @param string $basePath Base path for template files
     */
    public function __construct(
        private string $basePath,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function load(string $path): string
    {
        $resolvedPath = $this->resolve($path);
        $fullPath = $this->basePath . '/' . ltrim($resolvedPath, '/');

        // Try with the path as-is first
        if (!file_exists($fullPath)) {
            // If not found and doesn't end with .sugar.php, try adding the extension
            if (!str_ends_with($fullPath, '.sugar.php')) {
                $fullPathWithExtension = $fullPath . '.sugar.php';
                if (file_exists($fullPathWithExtension)) {
                    $fullPath = $fullPathWithExtension;
                } else {
                    throw new TemplateNotFoundException(
                        sprintf(
                            'Template "%s" not found at path "%s" or "%s"',
                            $path,
                            $fullPath,
                            $fullPathWithExtension,
                        ),
                    );
                }
            } else {
                throw new TemplateNotFoundException(
                    sprintf('Template "%s" not found at path "%s"', $path, $fullPath),
                );
            }
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new TemplateNotFoundException(
                sprintf('Failed to read template "%s" at path "%s"', $path, $fullPath),
            );
        }

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $path, string $currentTemplate = ''): string
    {
        // Absolute paths (starting with /)
        if (str_starts_with($path, '/')) {
            return $this->normalizePath($path);
        }

        // Relative paths
        if ($currentTemplate !== '') {
            $currentDir = dirname($currentTemplate);
            $combined = $currentDir . '/' . $path;

            return $this->normalizePath($combined);
        }

        return $this->normalizePath($path);
    }

    /**
     * Normalize path by resolving . and .. segments.
     *
     * @param string $path Path to normalize
     * @return string Normalized path
     */
    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($result !== []) {
                    array_pop($result);
                }

                continue;
            }

            $result[] = $part;
        }

        return implode('/', $result);
    }
}

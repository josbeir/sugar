<?php
declare(strict_types=1);

namespace Sugar\Compiler;

use PhpParser\Error as ParserError;
use PhpParser\ParserFactory;
use Sugar\Context\CompilationContext;
use Sugar\Exception\CompilationException;
use Sugar\Exception\SyntaxException;

/**
 * Validates compiled PHP output and maps syntax errors back to templates.
 */
final class CompiledTemplateValidator
{
    /**
     * Validate compiled PHP and throw a SyntaxException on parser errors.
     */
    public function validate(string $compiled, CompilationContext $context): void
    {
        if (!self::isAvailable()) {
            throw new CompilationException(
                'Compiled template validation requires nikic/php-parser. ' .
                'Install it or disable validation in SugarConfig.',
            );
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        try {
            $parser->parse($compiled);
        } catch (ParserError $parserError) {
            $this->throwSyntaxError($parserError, $compiled, $context);
        }
    }

    /**
     * Check if nikic/php-parser is available.
     */
    public static function isAvailable(): bool
    {
        return class_exists(ParserFactory::class);
    }

    /**
     * @return array{path: string, line: int, column: int}|null
     */
    private function mapCompiledLineToTemplate(string $compiled, int $compiledLine): ?array
    {
        $lines = explode("\n", $compiled);
        $previousLocation = null;
        $previousLine = null;
        $nextLocation = null;
        $nextLine = null;

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $location = $this->parseDebugLocation($line);
            if ($location === null) {
                continue;
            }

            if ($lineNumber <= $compiledLine) {
                $previousLocation = $location;
                $previousLine = $lineNumber;
            }

            if ($lineNumber >= $compiledLine && $nextLocation === null) {
                $nextLocation = $location;
                $nextLine = $lineNumber;
            }
        }

        $currentLine = $lines[$compiledLine - 1] ?? '';
        if ($nextLocation !== null && $nextLine === $compiledLine) {
            return $nextLocation;
        }

        if ($nextLocation !== null && str_contains($currentLine, 'echo ')) {
            return $nextLocation;
        }

        if ($nextLocation !== null && $previousLocation !== null && $nextLine !== null && $previousLine !== null) {
            $nextDistance = $nextLine - $compiledLine;
            $previousDistance = $compiledLine - $previousLine;

            return $nextDistance <= $previousDistance ? $nextLocation : $previousLocation;
        }

        if ($nextLocation !== null) {
            return $nextLocation;
        }

        return $previousLocation;
    }

    /**
     * @return array{path: string, line: int, column: int}|null
     */
    private function parseDebugLocation(string $line): ?array
    {
        if (!str_contains($line, '/* sugar: ')) {
            return null;
        }

        if (!preg_match('/\/\* sugar: (.+):(\d+):(\d+)(?: [^*]+)? \*\//', $line, $matches)) {
            return null;
        }

        return [
            'path' => $matches[1],
            'line' => (int)$matches[2],
            'column' => (int)$matches[3],
        ];
    }

    /**
     * Throw a mapped syntax error when compiled PHP is invalid.
     */
    private function throwSyntaxError(ParserError $error, string $compiled, CompilationContext $context): void
    {
        $compiledLine = $error->getStartLine();
        $message = sprintf('Compiler: %s', $error->getMessage());

        $location = $this->mapCompiledLineToTemplate($compiled, $compiledLine);
        if ($location !== null) {
            if ($location['path'] === $context->templatePath) {
                throw $context->createException(
                    SyntaxException::class,
                    $message,
                    $location['line'],
                    $location['column'],
                );
            }

            throw new SyntaxException(
                message: $message,
                templatePath: $location['path'],
                templateLine: $location['line'],
                templateColumn: $location['column'],
                snippet: null,
                previous: $error,
            );
        }

        throw new SyntaxException(
            message: $message . sprintf(' (compiled line:%d)', $compiledLine),
            templatePath: $context->templatePath,
            previous: $error,
        );
    }
}

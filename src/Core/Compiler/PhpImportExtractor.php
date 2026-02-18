<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

use PhpToken;
use Sugar\Core\Ast\PhpImportNode;
use Sugar\Core\Ast\RawPhpNode;

/**
 * Extracts leading namespace import statements from raw PHP snippets.
 *
 * Provides both a text-level split ({@see splitLeadingImports}) and a higher-level
 * AST-aware extraction ({@see extractImportNodes}) that produces typed
 * {@see PhpImportNode} instances ready for use in the compiler pipeline.
 */
final class PhpImportExtractor
{
    /**
     * Extract leading import statements from a {@see RawPhpNode} as typed AST nodes.
     *
     * Each raw import statement is canonicalized via {@see PhpImportRegistry::canonicalize()}
     * before being wrapped in a {@see PhpImportNode}. The second element of the returned
     * tuple is the remaining executable code after the imports have been stripped.
     *
     * @return array{0: array<\Sugar\Core\Ast\PhpImportNode>, 1: string}
     */
    public function extractImportNodes(RawPhpNode $node): array
    {
        [$rawImports, $remaining] = $this->splitLeadingImports($node->code);

        $importNodes = [];
        foreach ($rawImports as $raw) {
            foreach (PhpImportRegistry::canonicalize($raw) as $canonical) {
                $importNode = new PhpImportNode($canonical, $node->line, $node->column);
                $importNode->inheritTemplatePathFrom($node);
                $importNodes[] = $importNode;
            }
        }

        return [$importNodes, $remaining];
    }

    /**
     * Split leading import statements from executable code.
     *
     * @return array{0: array<string>, 1: string}
     */
    public function splitLeadingImports(string $code): array
    {
        $trimmedCode = trim($code);
        if ($trimmedCode === '' || !str_contains($trimmedCode, 'use')) {
            return [[], $code];
        }

        $tokens = PhpToken::tokenize('<?php ' . $trimmedCode);
        $cursor = 0;

        if (($tokens[$cursor] ?? null)?->id === T_OPEN_TAG) {
            $cursor++;
        }

        $cursor = $this->skipTrivia($tokens, $cursor);
        if (($tokens[$cursor] ?? null)?->id !== T_USE) {
            return [[], $code];
        }

        $imports = [];
        while (($tokens[$cursor] ?? null)?->id === T_USE) {
            [$statement, $nextCursor] = $this->parseUseStatement($tokens, $cursor);
            if ($statement === null) {
                return [[], $code];
            }

            $imports[] = $statement;
            $cursor = $this->skipTrivia($tokens, $nextCursor);
        }

        return [$imports, $this->rebuildCode($tokens, $cursor)];
    }

    /**
     * Extract only leading import statements from raw PHP code.
     *
     * @return array<string>
     */
    public function extractLeadingImports(string $code): array
    {
        [$imports, ] = $this->splitLeadingImports($code);

        return $imports;
    }

    /**
     * @param array<\PhpToken> $tokens
     */
    private function skipTrivia(array $tokens, int $cursor): int
    {
        $count = count($tokens);
        while ($cursor < $count) {
            $token = $tokens[$cursor];
            if (in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $cursor++;
                continue;
            }

            break;
        }

        return $cursor;
    }

    /**
     * @param array<\PhpToken> $tokens
     * @return array{0: string|null, 1: int}
     */
    private function parseUseStatement(array $tokens, int $cursor): array
    {
        if (($tokens[$cursor] ?? null)?->id !== T_USE) {
            return [null, $cursor];
        }

        $statement = '';
        $braceDepth = 0;
        $parenDepth = 0;
        $count = count($tokens);

        for ($index = $cursor; $index < $count; $index++) {
            $text = $tokens[$index]->text;
            $statement .= $text;

            if ($text === '{') {
                $braceDepth++;
                continue;
            }

            if ($text === '}') {
                $braceDepth = max(0, $braceDepth - 1);
                continue;
            }

            if ($text === '(') {
                $parenDepth++;
                continue;
            }

            if ($text === ')') {
                $parenDepth = max(0, $parenDepth - 1);
                continue;
            }

            if ($text === ';' && $braceDepth === 0 && $parenDepth === 0) {
                return [trim($statement), $index + 1];
            }
        }

        return [null, $cursor];
    }

    /**
     * Rebuild raw PHP code from the provided token offset.
     *
     * @param array<\PhpToken> $tokens
     */
    private function rebuildCode(array $tokens, int $start): string
    {
        $result = '';
        $count = count($tokens);

        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->id === T_CLOSE_TAG) {
                continue;
            }

            $result .= $token->text;
        }

        return $result;
    }
}

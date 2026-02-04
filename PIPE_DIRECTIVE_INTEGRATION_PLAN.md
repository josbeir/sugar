# Plan: Integrate Pipe Syntax with Directives

## Problem Analysis

**Current State:**
- Pipe syntax works in `<?= ?>` output tags ✅
- Pipe syntax does NOT work in directives like `s:text` and `s:html` ❌

**Root Cause:**
The pipe parsing happens in `Parser::parseTokens()` when creating `OutputNode` from `<?= ?>` tags. However, when directives like `s:text="$name |> upper(...)"` are compiled:

1. `DirectiveExtractionPass` extracts the expression string `"$name |> upper(...)"`
2. `TextCompiler::compile()` creates a new `OutputNode` directly from that string
3. The expression is never parsed for pipe syntax - it's treated as a raw PHP expression

## Solution: Centralize Pipe Parsing

### Design Principle: DRY (Single Source of Truth)
Create a **shared utility** for pipe parsing that both Parser and DirectiveCompilers can use.

### Architecture

```
┌─────────────────────────────────────────┐
│         PipeParser (NEW)                │
│  - parsePipes(string): array           │
│  - Static utility class                 │
└─────────────────────────────────────────┘
                    ▲
                    │
        ┌───────────┴────────────┐
        │                        │
┌───────┴────────┐      ┌────────┴────────┐
│   Parser       │      │ TextCompiler    │
│                │      │ HtmlCompiler    │
│ Uses when      │      │                 │
│ parsing <?= ?> │      │ Uses when       │
│                │      │ creating        │
│                │      │ OutputNode      │
└────────────────┘      └─────────────────┘
```

## Implementation Steps

### Step 1: Extract Pipe Parsing to Utility Class
**File:** `src/Parser/PipeParser.php` (NEW)

```php
<?php
declare(strict_types=1);

namespace Sugar\Parser;

/**
 * Utility for parsing pipe operator syntax
 *
 * Provides DRY parsing of PHP 8.5 pipe operators (|>) that can be used by:
 * - Parser (when parsing <?= ?> output tags)
 * - Directive compilers (when creating OutputNodes from directive expressions)
 */
final class PipeParser
{
    /**
     * Parse pipe syntax from expression
     *
     * @param string $expression Expression to parse
     * @return array{expression: string, pipes: array<string>|null}
     */
    public static function parse(string $expression): array
    {
        if (!str_contains($expression, '|>')) {
            return ['expression' => $expression, 'pipes' => null];
        }

        $parts = preg_split('/\s*\|\>\s*/', $expression);

        if ($parts === false || count($parts) < 2) {
            return ['expression' => $expression, 'pipes' => null];
        }

        $baseExpression = trim(array_shift($parts));
        $pipes = array_map('trim', $parts);

        return ['expression' => $baseExpression, 'pipes' => $pipes];
    }
}
```

**Why Static?**
- No state needed
- Pure function behavior
- Easy to use from any context
- Follows PHP convention for utilities (like `Str::`, `Arr::`)

### Step 2: Update Parser to Use PipeParser
**File:** `src/Parser/Parser.php`

```php
// Change parsePipes() method:
private function parsePipes(string $expression): array
{
    return PipeParser::parse($expression);
}
```

**Benefits:**
- Maintains backward compatibility
- Minimal code change
- Parser continues to work as before

### Step 3: Update TextCompiler to Parse Pipes
**File:** `src/Directive/TextCompiler.php`

```php
use Sugar\Parser\PipeParser;

public function compile(Node $node): array
{
    // Parse pipes from directive expression
    $parsed = PipeParser::parse($node->expression);

    $outputNode = new OutputNode(
        expression: $parsed['expression'],
        escape: true,
        context: OutputContext::HTML,
        line: $node->line,
        column: $node->column,
        pipes: $parsed['pipes'], // Add pipe chain
    );

    return [$outputNode, ...$node->children];
}
```

### Step 4: Update HtmlCompiler to Parse Pipes
**File:** `src/Directive/HtmlCompiler.php`

```php
use Sugar\Parser\PipeParser;

public function compile(Node $node): array
{
    // Parse pipes from directive expression
    $parsed = PipeParser::parse($node->expression);

    $outputNode = new OutputNode(
        expression: $parsed['expression'],
        escape: false, // s:html doesn't escape
        context: OutputContext::HTML,
        line: $node->line,
        column: $node->column,
        pipes: $parsed['pipes'], // Add pipe chain
    );

    return [$outputNode, ...$node->children];
}
```

### Step 5: Add Unit Tests
**File:** `tests/Unit/Parser/PipeParserTest.php` (NEW)

Test the utility in isolation:
- Simple pipe
- Multiple pipes
- No pipes
- Edge cases (empty, malformed)

### Step 6: Update Integration Tests
The existing tests in `PipeSyntaxIntegrationTest.php` should now pass when using the full Compiler.

## Alternative Approaches Considered

### ❌ Alternative 1: Parse Pipes in DirectiveExtractionPass
**Why Not:**
- DirectiveExtractionPass creates DirectiveNodes, not OutputNodes
- Would require changing DirectiveNode to store parsed pipes
- DirectiveCompilers would still need to transfer pipes to OutputNode
- More complex, breaks single responsibility

### ❌ Alternative 2: Parse Pipes in CodeGenerator
**Why Not:**
- CodeGenerator is for code emission, not parsing
- Would need to parse during generation (bad separation of concerns)
- Harder to test

### ❌ Alternative 3: Make Parser a Dependency of Compilers
**Why Not:**
- Directives don't need full Parser - just pipe parsing
- Creates circular dependency risk
- Over-engineering

### ✅ Chosen: Extracted Static Utility
**Why:**
- Single source of truth (DRY)
- No dependencies
- Easy to test
- Easy to use
- Follows PHP conventions
- Minimal changes to existing code

## Testing Strategy

### Unit Tests
1. `PipeParserTest` - Test utility in isolation
2. `TextCompilerTest` - Verify compiler uses pipe parser
3. `HtmlCompilerTest` - Verify compiler uses pipe parser

### Integration Tests
1. `PipeSyntaxIntegrationTest::testPipeWithSTextDirective`
2. `PipeSyntaxIntegrationTest::testPipeWithSHtmlDirective`
3. `PipeSyntaxIntegrationTest::testPipeWithSTextAndOtherDirectives`
4. `PipeSyntaxIntegrationTest::testPipeWithSTextInForeach`

All should pass with full Compiler pipeline.

## Rollout

1. ✅ Create `PipeParser` utility
2. ✅ Update `Parser::parsePipes()` to delegate
3. ✅ Update `TextCompiler` to use `PipeParser`
4. ✅ Update `HtmlCompiler` to use `PipeParser`
5. ✅ Add unit tests for `PipeParser`
6. ✅ Run integration tests
7. ✅ Run full test suite (should maintain 96%+ coverage)
8. ✅ Update documentation if needed

## Success Criteria

- ✅ All existing tests pass
- ✅ New integration tests pass
- ✅ Code coverage maintained (96%+)
- ✅ PHPStan level 8 passes
- ✅ PHPCS passes
- ✅ No code duplication (DRY principle)

## Files to Create/Modify

**Create:**
- `src/Parser/PipeParser.php`
- `tests/Unit/Parser/PipeParserTest.php`

**Modify:**
- `src/Parser/Parser.php` (use PipeParser)
- `src/Directive/TextCompiler.php` (parse pipes)
- `src/Directive/HtmlCompiler.php` (parse pipes)
- `tests/Integration/PipeSyntaxIntegrationTest.php` (already done)

**Lines of Code:**
- New: ~120 lines
- Modified: ~15 lines
- Deleted: ~20 lines (duplicate pipe parsing)
- Net: ~115 lines added

## Benefits

1. **DRY** - Single pipe parsing implementation
2. **Testable** - Pure function, easy to test
3. **Maintainable** - Clear responsibility
4. **Extensible** - Other directives can use it
5. **Consistent** - Same behavior everywhere
6. **Zero Breaking Changes** - All existing code continues to work

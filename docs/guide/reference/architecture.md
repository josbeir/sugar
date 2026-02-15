---
title: Architecture
description: Compilation pipeline and caching flow.
---

# Architecture

Sugar turns templates into a small, predictable PHP AST and then emits optimized PHP code. The pipeline is designed to keep parsing and compilation deterministic, while runtime stays fast and cache-friendly.

::: info
Compilation happens once per template change. Rendering is a cached PHP include.
:::

## Compilation Pipeline

| Priority Enum | Stage | Purpose | Notes |
| --- | --- | --- | --- |
| — | Parser | Converts template source into a Sugar AST. | Uses `PhpToken` for PHP-aware tokenization and preserves line/column for error reporting. |
| `TEMPLATE_INHERITANCE` | TemplateInheritancePass | Applies `s:extends` and merges blocks. | Optional, requires a template loader. |
| `DIRECTIVE_EXTRACTION` | DirectiveExtractionPass | Pulls out `s:*` directives and validates placement. | Produces directive nodes from attributes. |
| `DIRECTIVE_PAIRING` | DirectivePairingPass | Pairs directives like `if/elseif/else` and `forelse/empty`. | Ensures correct sibling relationships. |
| `DIRECTIVE_COMPILATION` | DirectiveCompilationPass | Rewrites directive nodes into executable AST nodes. | Produces control flow, attributes, and output nodes. |
| `POST_DIRECTIVE_COMPILATION` | ComponentExpansionPass | Resolves component tags into their AST. | Registered by `ComponentExtension` (optional, requires a template loader). |
| `CONTEXT_ANALYSIS` | ContextAnalysisPass | Determines output context for escaping decisions. | Tags output nodes with HTML/attribute/URL/JS/CSS contexts. |
| — | CodeGenerator | Emits pure PHP from the final AST. | Output is ready for opcache. |

::: tip
If a template compiles, it will render deterministically. All runtime behavior is inside the generated PHP.
:::

## AST Shape

Sugar keeps a small set of nodes so transformations remain predictable. Directives compile into nodes that the code generator can output without re-parsing.

```php
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\RawPhpNode;

$element = new ElementNode(
    tag: 'div',
    attributes: [],
    children: [new RawPhpNode('echo $name;', 12, 8)],
    selfClosing: false,
    line: 12,
    column: 1,
);
```

::: details Why AST nodes matter
Errors point to the source line/column, and transformations are composable because every pass consumes and returns nodes.
:::

## Directive Lifecycle

Directives are **extracted** first and **compiled** later. This keeps validation separate from code generation.

::: code-group
```text [Extraction]
HTML element + s:* attributes
	↓
Directive nodes (validated)
```

```text [Compilation]
Directive nodes
	↓
RawPhpNode / OutputNode / ElementNode
```
:::

::: warning
Directive compilation must be side-effect free. Any runtime work belongs in the generated PHP.
:::

## Context-Aware Escaping

The context pass marks output nodes so the escaper can pick the right strategy. This lets the generator emit fast, context-safe code without guessing at runtime.

::: details Example contexts
- HTML text uses `htmlspecialchars()`.
- Attribute values are escaped with attribute-aware rules.
- URL parts use `rawurlencode()`.
:::

## Component Expansion

Component tags are expanded into their template AST before code generation. That means components participate in the same directive and context logic as the parent template.

## File Caching Flow

![Caching flow diagram](/diagrams/cache-flow.png)

::: info
Cache entries store compiled PHP and dependency metadata, so debug mode can recompile only when inputs change.
:::

Sugar caches by a template path hash and writes a compiled PHP file plus a metadata file. In production, the cache is treated as immutable; in debug mode, dependency timestamps are checked to decide whether a recompilation is needed.

::: details What gets cached
- The generated PHP code ready for opcache.
- A dependency list for templates, layouts, and components.
- The original template path hash for quick lookup.
:::

## Debug and Errors

Sugar keeps line/column data through all passes so errors can point back to the original template, even after component expansion.

When you enable debug mode, the engine can render compilation errors as HTML with highlighted source using the `HtmlTemplateExceptionRenderer`.

::: details Typical error sources
- Unknown directives or invalid placement during extraction.
- Invalid component names or missing templates during expansion.
- Unsafe output when context detection finds ambiguous output.
:::


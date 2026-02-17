---
title: What Is Sugar
description: Newcomer-friendly overview of what Sugar is, how it works, and what it adds to PHP templates.
---

# What Is Sugar

Sugar is a PHP (8.2+) template engine that lets you keep writing PHP templates while adding cleaner template syntax and safer defaults.

If you're new: Sugar is **not** a separate language like Twig or Blade. Your templates are still PHP templates, and Sugar compiles them to pure PHP.

## In One Sentence

Sugar is syntactic PHP for templates: same PHP foundation, plus `s:` directives, context-aware escaping, and component/layout helpers.

## What Sugar Adds

Sugar enhances existing PHP templates with:

- Clean `s:directive` syntax for control structures.
- Context-aware XSS escaping for HTML, JavaScript, CSS, and URLs.
- Loop metadata, conditional classes, and empty state fallbacks.
- Full compatibility with existing `<?= $var ?>` output.

## How It Works

1. You write templates using normal HTML + PHP with optional `s:` directives.
2. Sugar compiles the template to optimized pure PHP.
3. Compiled templates are cached.
4. PHP/opcache executes the compiled code with no template-engine runtime layer.

This is why Sugar feels ergonomic like a template engine, but runs like plain PHP.

::: tip
Sugar compiles once and runs as pure PHP, so you get clean authoring with production-grade performance.
:::

<!-- @include: _partials/comparison.md -->

## Why Use Sugar

| Benefit | Why it matters |
| --- | --- |
| `s:` attributes keep templates readable | Control flow and output stay close to the HTML. |
| Context-aware escaping by default | XSS protection across HTML, attribute, URL, JS, and CSS contexts. |
| Pure PHP output | Fast execution and debuggable stack traces. |
| Scope isolation | Prevents accidental variable leakage between templates. |
| Still PHP, so IDEs play nice | Native syntax highlighting, navigation, and refactors work out of the box (for PHP code in templates). |
| Zero core dependencies | Easy to embed and audit. |

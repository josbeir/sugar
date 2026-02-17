---
title: Helper Reference
description: Handy helpers for writing custom extensions.
---

# Helper Reference

Sugar ships with a small set of helpers that make custom extensions easier to implement. This page highlights the most useful ones and the patterns they support.

## AST Helpers

### AttributeHelper

Utilities for finding, filtering, and reading attributes on `ElementNode` and `FragmentNode`.

```php
use Sugar\Core\Ast\Helper\AttributeHelper;

$attr = AttributeHelper::findAttribute($element->attributes, 'class');
$hasDirective = AttributeHelper::hasAttributeWithPrefix($element, 's:');
$value = AttributeHelper::getStringAttributeValue($element, 'id');
```

Useful additional APIs:

- `findAttributeWithIndex()` when you need both the node and its position.
- `collectNamedAttributeNames()` when checking for duplicate/conflicting attributes.
- `attributeValueToPhpExpression()` to turn `AttributeValue` into PHP expression code.
- `normalizeCompiledPhpExpression()` to normalize short echo / echo snippets.

### NodeTraverser

Tree walking helpers for transforms and inspections.

```php
use Sugar\Core\Ast\Helper\NodeTraverser;
use Sugar\Core\Ast\TextNode;

$nodes = NodeTraverser::walk($nodes, function ($node, $recurse) {
    if ($node instanceof TextNode) {
        return new TextNode(strtoupper($node->content), $node->line, $node->column);
    }

    return $recurse($node);
});
```

Use `walkRecursive()` when you only need to collect information without modifying the tree.

For targeted queries, `findFirst()` and `findAll()` help search subtrees without writing custom recursion.

### NodeCloner

Create modified copies of `ElementNode` or `FragmentNode` without mutating the original instance.

```php
use Sugar\Core\Ast\Helper\NodeCloner;

$newElement = NodeCloner::withAttributesAndChildren($element, $attrs, $children);
```

Use `withChildren()`, `fragmentWithChildren()`, and `fragmentWithAttributes()` for immutable updates in transform passes.

### ExpressionValidator

Validate expressions that must be array-like, such as `s:bind` or `s:spread` values.

```php
use Sugar\Core\Ast\Helper\ExpressionValidator;

ExpressionValidator::validateArrayExpression($expression, 's:bind attribute', $context, $line, $column);
```

## Config Helpers

### DirectivePrefixHelper

Parse and build directive names with a configurable prefix.

```php
use Sugar\Core\Config\Helper\DirectivePrefixHelper;

$prefix = new DirectivePrefixHelper('s');
$name = $prefix->stripPrefix('s:if'); // "if"
$full = $prefix->buildName('foreach'); // "s:foreach"
```

Useful additional APIs:

- `isDirective()` for quick attribute filtering.
- `isInheritanceAttribute()` and `inheritanceDirectiveNames()` when your extension must skip composition attributes.
- `getDirectiveSeparator()` for dynamically building prefixed names.

## Directive Helpers

### DirectiveClassifier

Classifies directive attribute names based on the registry and prefix.

```php
use Sugar\Core\Directive\Helper\DirectiveClassifier;

$isControlFlow = $classifier->isControlFlowDirectiveAttribute('s:if');
```

Useful additional APIs:

- `directiveName()` to resolve and normalize a directive attribute name.
- `compilerForAttribute()` to resolve the registered compiler instance.
- `validateUnknownDirectivesInNodes()` to enforce strict directive validation in extension pipelines.

## Tips

- Prefer `NodeTraverser::walk()` for transforms that may expand or replace nodes.
- Use `NodeCloner` when you need to preserve original nodes for error reporting.
- Use `DirectiveClassifier::validateUnknownDirectivesInNodes()` when your extension introduces strict validation boundaries.
- Validate user expressions early with `ExpressionValidator` to surface better error messages.

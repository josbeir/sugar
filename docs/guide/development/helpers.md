---
title: Helper Reference
description: Handy helpers for writing custom compiler passes.
---

# Helper Reference

Sugar ships with a small set of helpers that make custom passes easier to implement. This page highlights the most useful ones and the patterns they support.

## AST Helpers

### AttributeHelper

Utilities for finding, filtering, and reading attributes on `ElementNode` and `FragmentNode`.

```php
use Sugar\Core\Ast\Helper\AttributeHelper;

$attr = AttributeHelper::findAttribute($element->attributes, 'class');
$hasDirective = AttributeHelper::hasAttributeWithPrefix($element, 's:');
$value = AttributeHelper::getStringAttributeValue($element, 'id');
```

### NodeTraverser

Tree walking helpers for transforms and inspections.

```php
use Sugar\Core\Ast\Helper\NodeTraverser;

$nodes = NodeTraverser::walk($nodes, function ($node, $recurse) {
    if ($node instanceof ComponentNode) {
        return $this->expandComponent($node);
    }

    return $recurse($node);
});
```

Use `walkRecursive()` when you only need to collect information without modifying the tree.

### NodeCloner

Create modified copies of `ElementNode` or `FragmentNode` without mutating the original instance.

```php
use Sugar\Core\Ast\Helper\NodeCloner;

$newElement = NodeCloner::withAttributesAndChildren($element, $attrs, $children);
```

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

### SlotResolver

Extracts named and default slots and builds runtime slot expressions. Also exposes `disableEscaping()` for slot variables.

```php
use Sugar\Extension\Component\Helper\SlotResolver;

$slots = $slotResolver->extract($component->children);
$slotVars = $slotResolver->buildSlotVars($slots);
```

### ComponentSlots

Value object for default and named slot buckets. Useful when passing slot data between helpers.

## Directive Helpers

### DirectiveClassifier

Classifies directive attribute names based on the registry and prefix.

```php
use Sugar\Core\Directive\Helper\DirectiveClassifier;

$isControlFlow = $classifier->isControlFlowDirectiveAttribute('s:if');
```

## Tips

- Prefer `NodeTraverser::walk()` for transforms that may expand or replace nodes.
- Use `NodeCloner` when you need to preserve original nodes for error reporting.
- Validate user expressions early with `ExpressionValidator` to surface better error messages.

---
title: Custom Directives
description: Create and register custom Sugar directives.
---

# Custom Directives

Custom directives let you extend Sugar with project-specific syntax while keeping the compilation pipeline fast and predictable.

::: tip
Start with a small directive and register it locally before sharing it across templates.
:::

## Registering a Directive

Register a directive by name and pass its class or an already constructed instance to the registry. The registry instance is then injected into the engine builder.

```php
use Sugar\Extension\DirectiveRegistry;
use Sugar\Engine;

$registry = new DirectiveRegistry();
$registry->register('badge', BadgeDirective::class);
$registry->register('badge-runtime', new BadgeDirective());

$engine = Engine::builder()
    ->withDirectiveRegistry($registry)
    ->build();
```

## Registering via Extensions

If you plan to reuse a directive across projects, package it as an extension. Extensions register directives (and optional compiler passes) through a `RegistrationContext` and are added to the engine builder.

```php
use Sugar\Extension\ExtensionInterface;
use Sugar\Extension\RegistrationContext;
use Sugar\Engine;

final class UiExtension implements ExtensionInterface
{
    public function register(RegistrationContext $context): void
    {
        $context->directive('badge', BadgeDirective::class);
    }
}

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new UiExtension())
    ->build();
```

## Interfaces

### DirectiveInterface

All directives implement `DirectiveInterface` and return AST nodes. Use `RawPhpNode` to emit PHP control structures or HTML attributes.

**Methods:**
- `compile()` transforms a `DirectiveNode` into AST nodes.
- `getType()` tells the extraction pass how this directive should be treated.

Here are two common shapes for `DirectiveInterface` directives.

::: code-group
```php [Content directive]
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;

final class BadgeDirective implements DirectiveInterface
{
    public function compile(Node $node, CompilationContext $context): array
    {
        $label = $node->expression;

        return [
            new RawPhpNode('echo "<span class=\"badge\">";', $node->line, $node->column),
            new RawPhpNode('echo htmlspecialchars(' . $label . ', ENT_QUOTES | ENT_HTML5, "UTF-8");', $node->line, $node->column),
            new RawPhpNode('echo "</span>";', $node->line, $node->column),
        ];
    }

    public function getType(): DirectiveType
    {
        return DirectiveType::CONTENT;
    }
}
```

```php [Attribute directive]
use Sugar\Ast\Node;
use Sugar\Ast\RawPhpNode;
use Sugar\Compiler\CompilationContext;
use Sugar\Directive\Interface\DirectiveInterface;
use Sugar\Enum\DirectiveType;

final class DataTestDirective implements DirectiveInterface
{
    public function compile(Node $node, CompilationContext $context): array
    {
        return [
            new RawPhpNode('data-test="<?= ' . $node->expression . ' ?>"', $node->line, $node->column),
        ];
    }

    public function getType(): DirectiveType
    {
        return DirectiveType::ATTRIBUTE;
    }
}
```
:::

Usage:

```html
<div s:datatest="$id"></div>
```

### PairedDirectiveInterface

Use `PairedDirectiveInterface` when a directive requires a paired sibling, such as an `else`-style fallback.

**Methods:**
- `getPairingDirective()` returns the directive name to pair with (without the prefix).

```php
use Sugar\Directive\Interface\PairedDirectiveInterface;

final class TryDirective implements PairedDirectiveInterface
{
    public function getPairingDirective(): string
    {
        return 'fallback';
    }

    public function compile(Node $node, CompilationContext $context): array
    {
        $parts = [new RawPhpNode('try {', $node->line, $node->column)];
        array_push($parts, ...$node->children);

        $paired = $node->getPairedSibling();
        if ($paired !== null) {
            $parts[] = new RawPhpNode('} catch (\Throwable $e) {', $node->line, $node->column);
            array_push($parts, ...$paired->children);
        }

        $parts[] = new RawPhpNode('}', $node->line, $node->column);

        return $parts;
    }

    public function getType(): DirectiveType
    {
        return DirectiveType::CONTROL_FLOW;
    }
}
```

### ElementAwareDirectiveInterface

Implement `ElementAwareDirectiveInterface` when you need to modify the element or emit extra nodes during extraction. A common example is `s:tag`, which validates tag names and injects a runtime helper before the element renders.

**Methods:**
- `extractFromElement()` lets you replace the element, emit prefix nodes, or wrap it in a fragment.

```php
use Sugar\Ast\ElementNode;
use Sugar\Ast\FragmentNode;
use Sugar\Ast\RawPhpNode;
use Sugar\Directive\Interface\ElementAwareDirectiveInterface;
use Sugar\Runtime\HtmlTagHelper;
use Sugar\Util\Hash;

final class TagDirective implements ElementAwareDirectiveInterface
{
    public function extractFromElement(
        ElementNode $element,
        string $expression,
        array $transformedChildren,
        array $remainingAttrs,
    ): FragmentNode {
        // Create a unique variable name for this directive instance.
        $varName = '$__tag_' . Hash::short($expression . $element->line . $element->column);

        // Emit a prefix node that validates the tag name before rendering.
        $validation = new RawPhpNode(
            sprintf('%s = %s::validateTagName(%s);', $varName, HtmlTagHelper::class, $expression),
            $element->line,
            $element->column,
        );

        // Return the original element with a dynamic tag reference attached.
        $modifiedElement = new ElementNode(
            tag: $element->tag,
            attributes: $remainingAttrs,
            children: $transformedChildren,
            selfClosing: $element->selfClosing,
            line: $element->line,
            column: $element->column,
            dynamicTag: $varName,
        );

        // Fragment preserves both the prefix validation and the updated element.
        return new FragmentNode(
            attributes: [],
            children: [$validation, $modifiedElement],
            line: $element->line,
            column: $element->column,
        );
    }

    public function compile(Node $node, CompilationContext $context): array
    {
        return [];
    }

    public function getType(): DirectiveType
    {
        return DirectiveType::ATTRIBUTE;
    }
}
```

### ContentWrappingDirectiveInterface

Use `ContentWrappingDirectiveInterface` for modifiers like `s:nowrap` that change whether a content directive keeps its wrapper element.

**Methods:**
- `shouldWrapContentElement()` returns `false` to drop the wrapper or `true` to keep it.

```php
use Sugar\Directive\Interface\ContentWrappingDirectiveInterface;

final class NoWrapDirective implements ContentWrappingDirectiveInterface
{
    public function shouldWrapContentElement(): bool
    {
        return false;
    }

    public function compile(Node $node, CompilationContext $context): array
    {
        throw new LogicException('Handled during extraction.');
    }

    public function getType(): DirectiveType
    {
        return DirectiveType::PASS_THROUGH;
    }
}
```

::: warning
Only use `ContentWrappingDirectiveInterface` with content directives like `s:text` and `s:html`.
:::

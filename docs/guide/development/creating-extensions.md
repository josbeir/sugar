---
title: Creating Extensions
description: Package custom directives and compiler passes as reusable extensions.
---

# Creating Extensions

Extensions bundle custom directives and compiler passes into a reusable package. Each extension implements `ExtensionInterface` and registers features via a `RegistrationContext`.

## Basic Extension

```php
use Sugar\Extension\ExtensionInterface;
use Sugar\Extension\RegistrationContext;

final class AuditExtension implements ExtensionInterface
{
    public function register(RegistrationContext $context): void
    {
        $context->directive('audit', AuditDirective::class);
        $context->compilerPass(new AuditPass(), 35);
    }
}
```

Register the extension with the engine builder:

```php
use Sugar\Engine;

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new AuditExtension())
    ->build();
```

## Registering Directives

Directives can be registered as instances or class strings:

```php
$context->directive('tooltip', TooltipDirective::class);
$context->directive('badge', new BadgeDirective());
```

For directive design details, see the [Custom Directives](/guide/development/custom-directives) guide.

## Registering Compiler Passes

Compiler passes run during AST compilation and can rewrite or validate nodes. Use them for cross-cutting transformations that are not tied to a single directive.

Each `before()`/`after()` hook returns a `NodeAction`. Most passes return `NodeAction::none()` to keep the node unchanged, but you can also replace nodes or skip child traversal when needed:

::: code-group
```php [No changes]
use Sugar\Compiler\Pipeline\NodeAction;

return NodeAction::none();
```

```php [Skip children]
use Sugar\Compiler\Pipeline\NodeAction;

return NodeAction::skipChildren();
```

```php [Replace node]
use Sugar\Ast\TextNode;
use Sugar\Compiler\Pipeline\NodeAction;

$replacement = new TextNode('replacement', $node->line, $node->column);

return NodeAction::replace([$replacement]);
```
:::

### Simple Example

::: code-group
```php [Transform text]
use Sugar\Ast\Node;
use Sugar\Ast\TextNode;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;

final class UppercaseTextPass implements AstPassInterface
{
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof TextNode) {
            $node->content = strtoupper($node->content);
        }

        return NodeAction::none();
    }

    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }
}
```

```php [Reject inline styles]
use Sugar\Ast\ElementNode;
use Sugar\Ast\Node;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;
use Sugar\Exception\CompilationException;

final class NoInlineStylesPass implements AstPassInterface
{
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof ElementNode && array_key_exists('style', $node->attributes)) {
            throw new CompilationException('Inline styles are not allowed.');
        }

        return NodeAction::none();
    }

    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }
}
```

```php [Normalize whitespace]
use Sugar\Ast\Node;
use Sugar\Ast\TextNode;
use Sugar\Compiler\Pipeline\AstPassInterface;
use Sugar\Compiler\Pipeline\NodeAction;
use Sugar\Compiler\Pipeline\PipelineContext;

final class NormalizeWhitespacePass implements AstPassInterface
{
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if ($node instanceof TextNode) {
            $node->content = preg_replace('/\s+/', ' ', $node->content) ?? $node->content;
        }

        return NodeAction::none();
    }

    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }
}
```
:::

Register it with a priority:

```php
$context->compilerPass(new UppercaseTextPass(), 35);
```

### When to Use a Compiler Pass

- You need to transform many nodes across the tree (normalization, instrumentation, linting).
- You want to enforce a policy (for example, disallow inline styles or rewrite specific attributes).
- You need a compile-time optimization (folding constants, removing empty nodes).

### When to Prefer a Directive

- The behavior is scoped to a single directive or attribute.
- You need direct access to the directive expression and local node context.
- The feature should be opt-in on a per-element basis.

### Priorities

Each pass can include a numeric priority:

- Lower numbers run earlier.
- Higher numbers run later.
- Equal priorities keep the registration order.

```php
$context->compilerPass(new NormalizePass(), -10); // early
$context->compilerPass(new OptimizePass(), 35);   // mid-pipeline
$context->compilerPass(new FinalizePass(), 60);   // late
```

### Built-In Pass Order (Reference)

Sugar assigns numeric priorities to its built-in passes. Use numbers around these values to place your pass:

- 0: Template inheritance
- 10: Directive extraction
- 20: Directive pairing
- 30: Directive compilation
- 40: Component expansion
- 45: Component variant adjustments
- 50: Context analysis

If you need a pass to run between two built-ins, choose a value between their priorities (for example, `25` between pairing and compilation).

## Multiple Extensions

Extensions are applied in the order you register them. For passes with the same priority, that registration order is preserved.

```php
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new AnalyticsExtension())
    ->withExtension(new SeoExtension())
    ->build();
```

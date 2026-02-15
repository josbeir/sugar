---
title: Creating Extensions
description: Package custom directives and compiler passes as reusable extensions.
---

# Creating Extensions

Extensions bundle custom directives, compiler passes, and runtime services into a reusable package. Extension internals now live under `Sugar\Core\...`, while optional extension packages live under `Sugar\Extension\...`.

## Basic Extension

```php
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;

final class AuditExtension implements ExtensionInterface
{
    public function register(RegistrationContext $context): void
    {
        $context->directive('audit', AuditDirective::class);
        $context->compilerPass(
            new AuditPass(),
            \Sugar\Core\Enum\PassPriority::POST_DIRECTIVE_COMPILATION,
        );
    }
}
```

Register the extension with the engine builder:

```php
use Sugar\Core\Engine;

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new AuditExtension())
    ->build();
```

## Registering Runtime Services

Extensions can provide runtime services that directives and generated runtime calls consume:

```php
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;

final class MetricsExtension implements ExtensionInterface
{
    public function __construct(private MetricsClient $metrics)
    {
    }

    public function register(RegistrationContext $context): void
    {
        $context->runtimeService('metrics', $this->metrics);
        $context->directive('track', TrackDirective::class);
    }
}
```

Runtime services are available through `RuntimeEnvironment` during template execution.

`runtimeService()` also supports factories (closures) that receive a `RegistrationContext` at render time, so services can be built from runtime dependencies:

```php
use Sugar\Core\Extension\RegistrationContext;

$context->runtimeService('metrics', function (RegistrationContext $runtimeContext): MetricsClient {
        $cache = $runtimeContext->getTemplateCache();

        return new MetricsClient($cache);
});
```

The renderer service id (`RuntimeEnvironment::RENDERER_SERVICE_ID`) is reserved by the engine. If multiple extensions register that id, the first registration wins.

## RegistrationContext API

`RegistrationContext` now exposes engine dependencies to extensions through typed getters. Availability depends on when the context is used:

- **During extension registration** (`ExtensionInterface::register()`):
    - `getConfig()`
    - `getTemplateLoader()`
    - `getTemplateCache()`
    - `getTemplateContext()`
    - `isDebug()`
    - `getParser()`
    - `getDirectiveRegistry()`
- **During runtime service materialization** (closure passed to `runtimeService()`):
    - `getConfig()`
    - `getTemplateLoader()`
    - `getTemplateCache()`
    - `getTemplateContext()`
    - `isDebug()`
    - `getCompiler()`
    - `getTracker()`

Depending on phase, some getters can return `null`, so extensions should guard dependency assumptions when needed.

For example, fragment caching is now registered as an optional extension:

```php
use Sugar\Core\Engine;
use Sugar\Extension\FragmentCache\FragmentCacheExtension;

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new FragmentCacheExtension($cache, defaultTtl: 300))
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
use Sugar\Core\Compiler\Pipeline\NodeAction;

return NodeAction::none();
```

```php [Skip children]
use Sugar\Core\Compiler\Pipeline\NodeAction;

return NodeAction::skipChildren();
```

```php [Replace node]
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\NodeAction;

$replacement = new TextNode('replacement', $node->line, $node->column);

return NodeAction::replace([$replacement]);
```
:::

### Simple Example

::: code-group
```php [Transform text]
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;

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
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Exception\CompilationException;

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
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;

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

Register it with a semantic priority:

```php
use Sugar\Core\Enum\PassPriority;

$context->compilerPass(new UppercaseTextPass(), PassPriority::POST_DIRECTIVE_COMPILATION);
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

Compiler passes now use enum priorities (`Sugar\Core\Enum\PassPriority`) instead of numeric values:

- `TEMPLATE_INHERITANCE`
- `PRE_DIRECTIVE_EXTRACTION`
- `DIRECTIVE_EXTRACTION`
- `DIRECTIVE_PAIRING`
- `DIRECTIVE_COMPILATION`
- `POST_DIRECTIVE_COMPILATION`
- `CONTEXT_ANALYSIS`

```php
use Sugar\Core\Enum\PassPriority;

$context->compilerPass(new NormalizePass(), PassPriority::PRE_DIRECTIVE_EXTRACTION);
$context->compilerPass(new OptimizePass(), PassPriority::POST_DIRECTIVE_COMPILATION);
$context->compilerPass(new FinalizePass(), PassPriority::CONTEXT_ANALYSIS);
```

## Multiple Extensions

Extensions are applied in the order you register them. For passes with the same enum priority, that registration order is preserved.

```php
$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new AnalyticsExtension())
    ->withExtension(new SeoExtension())
    ->build();
```

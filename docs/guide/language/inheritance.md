---
title: Template Inheritance
description: Layouts, blocks, extends, includes, and s:with.
---

# Template Inheritance and Composition

Layouts, blocks, and includes let you build pages from reusable pieces. Use `s:extends` for layouts, `s:block` for named regions, and `s:include` for partials.

::: tip
Keep layout files under a `layouts/` folder and partials under `partials/` to make intent obvious.
:::

## Quick Start

Here's the simplest example of template inheritance:

::: code-group
```sugar [layouts/base.sugar.php]
<!DOCTYPE html>
<html>
<head>
    <title s:block="title">Default Title</title>
</head>
<body>
    <main s:block="content">Default content</main>
</body>
</html>
```

```sugar [pages/home.sugar.php]
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<title s:block="title">Home Page</title>

<div s:block="content">
    <h2>Welcome!</h2>
</div>
```

```html [Rendered output]
<!DOCTYPE html>
<html>
<head>
    <title>Home Page</title>
</head>
<body>
    <main>
        <h2>Welcome!</h2>
    </main>
</body>
</html>
```
:::

::: tip
Notice how the parent's `<main>` wrapper is preserved and the child's `<div>` wrapper is dropped. If you want the child wrapper preserved, define the parent block as a fragment (`<s-template s:block="...">`).
:::

## Folder Layout and File Organization

This suggested structure keeps layout inheritance, includes, and components easy to discover:

```text
templates/
├── pages/
│   ├── home.sugar.php
│   └── profile.sugar.php
├── layouts/
│   └── base.sugar.php
├── partials/
│   ├── header.sugar.php
│   └── footer.sugar.php
└── components/
    ├── s-button.sugar.php
    ├── s-card.sugar.php
    └── s-alert.sugar.php
```

### Path Resolution

By default, inheritance and include paths resolve relative to the current template. To enforce root-style paths, enable the `absolutePathsOnly` loader option documented in [Engine Configuration](../development/index.md#template-loaders).

::: code-group
```sugar [Relative paths]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<s-template s:include="partials/header"></s-template>
```

```sugar [Absolute paths only]
<s-template s:extends="layouts/base.sugar.php"></s-template>
<s-template s:include="partials/header"></s-template>
```
:::

## Layout Inheritance with `s:extends`

Use `s:extends` to inherit from a parent layout and replace its `s:block` regions. The `s:extends` element can be empty; it just declares the parent template.

::: code-group
```sugar [Parent layout]
<!-- layouts/base.sugar.php -->
<!DOCTYPE html>
<html>
<head>
    <title s:block="title">Default Title</title>
</head>
<body>
    <header s:block="header">
        <h1>Default Header</h1>
    </header>
    <main s:block="content">Default content</main>
    <footer s:block="footer">
        <p>Default Footer</p>
    </footer>
</body>
</html>
```

```sugar [Child template]
<!-- pages/dashboard.sugar.php -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<title s:block="title">Dashboard</title>

<header s:block="header">
    <h1>Dashboard</h1>
    <nav>...</nav>
</header>

<div s:block="content">
    <h2>Welcome to your dashboard</h2>
    <p>Your stats here</p>
</div>
```
:::

::: info
When a template uses `s:extends`, only inheritance content (`s:block`, `s:append`, `s:prepend`) is kept. Top-level markup or raw PHP outside those blocks is discarded, except leading import statements (`use`, `use function`, `use const`) which are preserved for compile-time hoisting.
:::

::: details Implementation note
Inheritance directives are compiled by `InheritanceCompilationPass` into runtime calls handled by `TemplateRenderer` and `BlockManager`. This is why block ordering and `s:parent` placement are deterministic across cached renders.
:::

## Working with Blocks

Blocks are named regions in your layout that child templates can replace or extend. Think of them as placeholders that child templates fill in.

### Basic Block Replacement

The simplest case: a child template completely replaces a parent block.

::: code-group
```sugar [Parent]
<main s:block="content">
    <p>Default content</p>
</main>
```

```sugar [Child]
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<div s:block="content">
    <p>New content</p>
</div>
```

```html [Output]
<main>
    <p>New content</p>
</main>
```
:::

### Including Parent Content with `s:parent`

`s:parent` is the key to flexible inheritance. It inserts parent block content exactly where you place it inside a child `s:block`.

For newcomers, this is the easiest pattern to understand:

1. A parent template declares a named region with `s:block`
2. A child template provides **one** `s:block` with the same name
3. Inside that block, use `s:parent` to decide where parent content should appear

::: code-group
```sugar [Append to parent]
<s-template s:block="content">
    <s-template s:parent />
    <p>Appended content</p>
</s-template>
```

```sugar [Prepend to parent]
<s-template s:block="content">
    <p>Prepended content</p>
    <s-template s:parent />
</s-template>
```

```sugar [Insert in the middle]
<s-template s:block="content">
    <p>Before</p>
    <s-template s:parent />
    <p>After</p>
</s-template>
```
:::

::: warning
`s:parent` must be used on a `<s-template>` element and is only valid inside an `s:block`.
:::

### Append and Prepend Shortcuts

`s:append` and `s:prepend` are convenience helpers when you just need to add content before or after parent content. They're shortcuts for simple cases where you don't need the full placement control of `s:parent`.

::: code-group
```sugar [s:append example]
<!-- layout: layouts/base.sugar.php -->
<main s:block="content">
    <p>Base content</p>
</main>

<!-- child: pages/home.sugar.php -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<s-template s:append="content">
    <p>Appended content</p>
</s-template>
```

```html [Output]
<main>
    <p>Base content</p>
    <p>Appended content</p>
</main>
```
:::

### Conditional Layout Regions with `s:ifblock`

Use `s:ifblock` in a parent layout when a region should only render if a child defines that block.

`s:ifblock` checks for a child-defined block override at runtime. Parent default content does **not** count as a defined child block.

::: code-group
```sugar [Parent layout]
<main s:block="content">Default content</main>

<aside s:ifblock="'sidebar'">
    <section s:block="sidebar"></section>
</aside>
```

```sugar [Child with sidebar]
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<s-template s:block="content">Page body</s-template>
<s-template s:block="sidebar">Sidebar widgets</s-template>
```

```html [Rendered]
<main>Page body</main>
<aside>
    <section>Sidebar widgets</section>
</aside>
```
:::

You can also use custom-element syntax:

```sugar
<s-ifblock name="sidebar">
    <aside><section s:block="sidebar"></section></aside>
</s-ifblock>
```

::: tip
Use `s:ifblock` for optional regions like sidebars, extra toolbars, or secondary footers that should disappear entirely when a child template does not provide that block.
:::

::: code-group
```sugar [s:prepend example]
<!-- layout: layouts/base.sugar.php -->
<main s:block="content">
    <p>Base content</p>
</main>

<!-- child: pages/home.sugar.php -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<s-template s:prepend="content">
    <p>Prepended content</p>
</s-template>
```

```html [Output]
<main>
    <p>Prepended content</p>
    <p>Base content</p>
</main>
```
:::

### Block Wrapper Behavior

The wrapper element that survives depends on whether the parent block is an **element** or a **fragment**:

**Element parent block**: Parent wrapper is preserved, child wrapper is discarded.

::: code-group
```sugar [Element parent]
<!-- Parent layout -->
<main s:block="content">
    <p>Base content</p>
</main>

<!-- Child replaces -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<div s:block="content">
    <p>New content</p>
</div>
```

```html [Output - parent wrapper kept]
<main>
    <p>New content</p>
</main>
```
:::

**Fragment parent block**: Child wrapper is preserved.

::: code-group
```sugar [Fragment parent]
<!-- Parent layout -->
<s-template s:block="content">
    <p>Base content</p>
</s-template>

<!-- Child replaces -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<div s:block="content">
    <p>New content</p>
</div>
```

```html [Output - child wrapper kept]
<div>
    <p>New content</p>
</div>
```
:::

This same behavior applies to `s:append` and `s:prepend`: when appending/prepending to an element parent block, the wrapper element on the directive is stripped but nested elements inside are preserved.

::: code-group
```sugar [Nested elements preserved]
<!-- layout: layouts/base.sugar.php -->
<main s:block="content">
    <p>Base content</p>
</main>

<!-- child: pages/home.sugar.php -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<s-template s:append="content">
    <section class="alert">
        <p>Wrapped content</p>
    </section>
</s-template>
```

```html [Output]
<main>
    <p>Base content</p>
    <section class="alert">
        <p>Wrapped content</p>
    </section>
</main>
```
:::

## Including Partials with `s:include`

Includes are great for shared fragments like headers, footers, or cards that you reuse across pages.

### Basic Includes

::: code-group
```sugar [Simple include]
<s-template s:include="partials/header"></s-template>
```

```sugar [With element wrapper]
<div class="card" s:include="partials/alert"></div>
```

```sugar [Multiple includes]
<s-template s:include="partials/header"></s-template>
<section>
    <s-template s:include="partials/hero"></s-template>
</section>
<s-template s:include="partials/footer"></s-template>
```
:::

### Passing Variables with `s:with`

Use `s:with` to pass scoped variables to an included template. Variables are only available inside the included template and don't leak to the parent scope.

::: code-group
```sugar [Parent template]
<s-template s:include="partials/user-card" s:with="['user' => $user, 'showBio' => true]"></s-template>
```

```sugar [partials/user-card.sugar.php]
<div class="user-card">
    <h3><?= $user->name ?></h3>
    <?php if ($showBio): ?>
        <p><?= $user->bio ?></p>
    <?php endif; ?>
</div>
```
:::

::: warning
`s:with` only works in combination with `s:include` on the same element. Variables passed via `s:with` are scoped to the included template only.
:::

### Combining Layouts and Includes

::: code-group
```sugar [Full page example]
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<title s:block="title">Dashboard</title>

<div s:block="content">
    <s-template s:include="partials/header"></s-template>

    <section class="stats">
        <s-template s:include="partials/stats-widget" s:with="['stats' => $stats]"></s-template>
    </section>

    <s-template s:include="partials/footer"></s-template>
</div>
```
:::

## Block Content from Partials

Sugar gives you two ways to supply block content from a partial file rather than writing it inline in the child template. Both approaches work at the **top level** of a child extends-template (i.e. outside any `s:block`).

### Partials That Define Blocks

A partial can contain `s:block` directives. When that partial is included at the top level of a child extends-template, its blocks are automatically registered as child overrides — exactly as if you had written them inline.

::: code-group
```sugar [layouts/base.sugar.php]
<nav s:block="nav">Default navigation</nav>
<main s:block="content">Default content</main>
```

```sugar [partials/nav.sugar.php]
<nav>
    <a href="/">Home</a>
    <a href="/about">About</a>
</nav>
```

```sugar [pages/home.sugar.php]
<s-template s:extends="layouts/base.sugar.php" />

<!-- include at top level — nav block is registered from the partial -->
<s-template s:include="partials/nav" />

<s-template s:block="content">
    <h1>Home</h1>
</s-template>
```
:::

::: tip
This works with conditional blocks too. A partial with `s:notempty="$items" s:block="sidebar"` only registers the block override when `$items` is non-empty — the layout default is used otherwise.
:::

::: warning
This only applies to **top-level** includes (outside any `s:block`) in a child extends-template. Includes inside `s:block` or inside layout templates still behave as normal layout placeholders.
:::

### Inline Include + Block Combo

For a more concise syntax you can combine `s:include` with `s:block`, `s:append`, or `s:prepend` **on the same element**. The partial's rendered output becomes the block content directly — no need to write a separate partial with its own `s:block`.

#### Replace a block with a partial

::: code-group
```sugar [Child template]
<s-template s:extends="layouts/base.sugar.php" />

<!-- The output of partials/hero becomes the "content" block -->
<s-template s:include="partials/hero" s:block="content" />
```

```sugar [Equivalent long form]
<s-template s:extends="layouts/base.sugar.php" />

<s-template s:block="content">
    <s-template s:include="partials/hero" />
</s-template>
```
:::

#### Append or prepend a partial to a block

::: code-group
```sugar [Append — partial output after parent block]
<s-template s:extends="layouts/base.sugar.php" />

<!-- Appends partials/scripts after whatever "scripts" yields in the layout -->
<s-template s:include="partials/scripts" s:append="scripts" />
```

```sugar [Prepend — partial output before parent block]
<s-template s:extends="layouts/base.sugar.php" />

<!-- Prepends partials/breadcrumbs before the parent "content" block -->
<s-template s:include="partials/breadcrumbs" s:prepend="content" />
```
:::

#### Passing variables with `s:with`

`s:with` works the same way when combined with `s:block`, `s:append`, or `s:prepend`:

```sugar
<s-template s:extends="layouts/base.sugar.php" />

<s-template
    s:include="partials/user-card"
    s:block="sidebar"
    s:with="['user' => $currentUser]"
/>
```

#### Explicit `s:block` takes precedence

When the same block name appears as both an explicit child `s:block` and an inline include combo, the **explicit `s:block` always wins**:

```sugar
<s-template s:extends="layouts/base.sugar.php" />

<!-- This wins — declared first, takes precedence -->
<s-template s:block="content">Explicit override</s-template>

<!-- This is silently ignored for the "content" block -->
<s-template s:include="partials/content" s:block="content" />
```

## Advanced Usage

### Render Only Specific Blocks

You can render one or more blocks directly by passing block names as the third argument to `Engine::render()`. This skips layout inheritance and outputs matching blocks in template order.

This is especially useful for AJAX responses or incremental updates:

```php
echo $engine->render(
    template: 'pages/home',
    data: ['user' => $user],
    blocks: ['sidebar', 'content'],
);
```

::: code-group
```sugar [Template]
<!-- pages/home.sugar.php -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<aside s:block="sidebar">
    <nav>...</nav>
</aside>
<section s:block="content">
    <h1>Content</h1>
</section>
```

```text [Output]
<aside><nav>...</nav></aside>
<section><h1>Content</h1></section>
```
:::

### Multi-Namespace Templates

### Multi-Namespace Templates

When working with plugins or multiple template packages, you can load templates from different registered namespaces using the `@namespace/` prefix.

By default, templates without an explicit namespace prefix resolve from the `@app` namespace.

#### Default Namespace (App)

```sugar
<!-- Explicitly in @app namespace -->
<s-template s:extends="@app/layouts/base.sugar.php" />

<!-- Same as above (implicit @app) -->
<s-template s:extends="layouts/base.sugar.php" />
```

#### Explicit Namespace Reference

Use `@namespace/` prefix to load from a different registered namespace:

```sugar
<!-- Load from 'plugin-auth' namespace -->
<s-template s:extends="@plugin-auth/layouts/dashboard.sugar.php" />

<!-- Load from 'shared-ui' namespace -->
<s-template s:include="@shared-ui/components/modal.sugar.php" />

<!-- Load from 'reports' namespace -->
<s-template s:include="@reports/partials/chart-legend" />
```

#### Setup Example

```php
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Core\Loader\TemplateNamespaceDefinition;

$loader = new FileTemplateLoader([
    __DIR__ . '/app/templates',
]);

// Register additional namespaces (e.g., plugins)
$loader->registerNamespace(
    'plugin-auth',
    new TemplateNamespaceDefinition([
        __DIR__ . '/plugins/auth/templates'
    ])
);

$loader->registerNamespace(
    'shared-ui',
    new TemplateNamespaceDefinition([
        __DIR__ . '/packages/shared-ui/templates'
    ])
);

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->build();
```

::: tip
The `@app` namespace is the default fallback. Explicit namespace prefixes always take priority.
:::

### Dependency Safety

Sugar tracks template dependencies during inheritance and includes. Circular dependencies are detected and rejected so a template cannot extend or include itself indirectly.

::: details What is prevented
- Circular inheritance (A extends B, B extends A)
- Circular includes (A includes B, B includes A)
- Diamond-shaped chains that would re-enter a template already on the stack
:::

::: tip
Keep layouts and partials layered in one direction (base → page → partials) to avoid accidental loops.
:::

## Rules and Limitations

Understanding these rules will help you avoid common pitfalls:

### Inheritance Rules

- **Only one `s:extends` per template** - A template can only extend one parent layout
- **Top-level content is discarded** - When using `s:extends`, only `s:block`, `s:append`, and `s:prepend` content is kept. Any markup or PHP outside these blocks is ignored
- **One directive per element** - Use only one of `s:block`, `s:append`, or `s:prepend` on the same element
- **One block definition per name** - Define each block name only once in a child template
- **`s:parent` location** - Only valid inside `s:block` and must be used on `<s-template>`

### Include Rules

- **`s:with` scope** - Variables passed via `s:with` are scoped to the included template only and don't leak to parent scope
- **Requires `s:include`** - `s:with` only works in combination with `s:include` on the same element
- **Top-level block registration** - A partial with `s:block` directives registers those blocks when included at the top level of a child extends-template; the same partial used inside a layout acts as a normal layout placeholder
- **Inline combo precedence** - When `s:include` is combined with `s:block`/`s:append`/`s:prepend`, an explicit child `s:block` declaration for the same name always takes precedence

### Path Resolution

- **Relative by default** - Paths resolve relative to the current template unless `absolutePathsOnly` is enabled in the loader

### Common Patterns

::: code-group
```sugar [✓ Do: Variables inside blocks]
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<s-template s:block="content">
    <?php $var = 'I AM A VARIABLE'; ?>
    <h1>Home</h1>
    <s-template s:include="partials/card" s:with="['var' => $var]" />
</s-template>
```

```sugar [✗ Don't: Variables outside blocks]
<?php $var = 'I AM A VARIABLE'; ?>
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<s-template s:block="content">
    <h1>Home</h1>
</s-template>
```
:::

::: code-group
```sugar [✓ Do: One directive per element]
<section s:block="content">
    <p>Block content</p>
</section>
```

```sugar [✗ Don't: Multiple directives]
<section s:block="content" s:append="content">
    <p>Not allowed</p>
</section>
```
:::

::: code-group
```sugar [✓ Do: Scoped variables with s:with]
<s-template s:include="partials/card" s:with="['title' => 'Card']" />
```

```sugar [✗ Don't: Expecting s:with to leak]
<s-template s:include="partials/card" s:with="['title' => 'Card']" />
<!-- $title is NOT available here -->
<h2><?= $title ?></h2>
```
:::

## Directive Reference

Quick reference for all template inheritance directives:

| Directive | Purpose | Used With |
|-----------|---------|-----------|
| `s:extends` | Declare the parent layout to inherit from | Layouts |
| `s:block` | Replace a named block in the parent | Layouts, or combined with `s:include` |
| `s:append` | Add content after a parent block | Layouts, or combined with `s:include` |
| `s:prepend` | Add content before a parent block | Layouts, or combined with `s:include` |
| `s:parent` | Insert parent block content at current position | Inside `s:block` only |
| `s:include` | Insert another template at this location | Partials |
| `s:with` | Pass scoped variables to an include | With `s:include` |

## Complete Example

Here's a full example combining layouts, blocks, and includes:

::: code-group
```sugar [layouts/base.sugar.php]
<!DOCTYPE html>
<html>
<head>
    <title s:block="title">My Site</title>
    <meta charset="utf-8">
    <s-template s:block="meta"></s-template>
</head>
<body>
    <s-template s:include="partials/nav"></s-template>

    <main s:block="content">
        <p>Default content</p>
    </main>

    <s-template s:include="partials/footer"></s-template>
</body>
</html>
```

```sugar [pages/dashboard.sugar.php]
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<title s:block="title">Dashboard - My Site</title>

<s-template s:block="meta">
    <meta name="description" content="User dashboard">
</s-template>

<div s:block="content">
    <h1>Dashboard</h1>

    <s-template s:include="partials/stats" s:with="['stats' => $stats]"></s-template>

    <section class="recent">
        <h2>Recent Activity</h2>
        <s-template s:include="partials/activity-list" s:with="['items' => $recentItems]"></s-template>
    </section>
</div>
```

```sugar [partials/stats.sugar.php]
<div class="stats-grid">
    <?php foreach ($stats as $stat): ?>
        <div class="stat-card">
            <h3><?= $stat['label'] ?></h3>
            <p><?= $stat['value'] ?></p>
        </div>
    <?php endforeach; ?>
</div>
```
:::

## Best Practices

- **Keep layouts simple** - Layouts should define structure, not business logic
- **Use descriptive block names** - `content`, `sidebar`, `meta` are clearer than `block1`, `block2`
- **One concern per partial** - Each include should have a single, clear purpose
- **Pass data explicitly** - Use `s:with` to make data dependencies obvious
- **Prefer `s:parent` over shortcuts** - `s:block` + `s:parent` is more flexible than `s:append`/`s:prepend`
- **Organize by purpose** - Keep layouts in `layouts/`, partials in `partials/`, pages in `pages/`
- **Avoid deep nesting** - Keep inheritance chains shallow (2-3 levels max)

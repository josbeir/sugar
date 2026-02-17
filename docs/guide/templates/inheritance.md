---
title: Template Inheritance
description: Layouts, blocks, extends, includes, and s:with.
---

# Template Inheritance and Composition

Layouts, blocks, and includes let you build pages from reusable pieces. Use `s:extends` for layouts, `s:block` for named regions, and `s:include` for partials.

::: tip
Keep layout files under a `layouts/` folder and partials under `partials/` to make intent obvious.
:::

## Folder Layout and Paths

This suggested structure keeps layout inheritance, includes, and components easy to discover.

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

By default, inheritance and include paths resolve relative to the current template. To enforce root-style paths, enable the loader option documented in [Engine Configuration](../development/index.md#template-loaders).

::: code-group
```sugar [Relative]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<s-template s:include="partials/header"></s-template>
```

```sugar [Absolute-only]
<s-template s:extends="layouts/base.sugar.php"></s-template>
<s-template s:include="partials/header"></s-template>
```
:::

## Multi-Namespace Includes and Extends

By default, templates without an explicit namespace prefix resolve from the `@app` namespace. You can load templates from other registered namespaces by prefixing the path with `@namespace/`.

This is useful when your application uses plugins or shared packages that have their own template namespaces.

### Default Namespace (App)

When no namespace prefix is used, templates resolve from `@app`:

```sugar
<!-- Explicitly in @app namespace -->
<s-template s:extends="@app/layouts/base.sugar.php" />

<!-- Same as above (implicit @app) -->
<s-template s:extends="layouts/base.sugar.php" />
```

### Explicit Namespace Reference

Use `@namespace/` prefix to load from a different registered namespace:

```sugar
<!-- Load from 'plugin-auth' namespace -->
<s-template s:extends="@plugin-auth/layouts/dashboard.sugar.php" />

<!-- Load from 'shared-ui' namespace -->
<s-template s:include="@shared-ui/components/modal.sugar.php" />

<!-- Load from 'reports' namespace -->
<s-template s:include="@reports/partials/chart-legend" />
```

### Multi-Namespace Setup

In your engine builder, register multiple template namespaces:

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

### Priority and Resolution

When multiple namespaces are registered, the `@app` namespace is the default fallback. Explicit namespace prefixes always take priority:

```sugar
<!-- Uses @app namespace -->
<s-template s:include="layouts/base" />

<!-- Uses @shared-ui namespace, not @app -->
<s-template s:include="@shared-ui/layouts/base" />
```

## Dependency Safety

Sugar tracks template dependencies during inheritance and includes. Circular dependencies are detected and rejected so a template cannot extend or include itself indirectly.

::: tip
Keep layouts and partials layered in one direction (base -> page -> partials) to avoid accidental loops.
:::

::: details
What is prevented

- Circular inheritance (A extends B, B extends A)
- Circular includes (A includes B, B includes A)
- Diamond-shaped chains that would re-enter a template already on the stack
:::

## Layout Inheritance

Use `s:extends` to inherit a layout and replace its `s:block` regions. The `s:extends` element can be empty; it just declares the parent template.

When a parent block is an element, that wrapper is preserved and the child's wrapper is discarded (only the child's children replace the parent's children). If the parent block is a fragment, the child's wrapper is preserved.

::: code-group
```sugar [Parent layout]
<!-- layouts/base.sugar.php -->
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

```sugar [Child template]
<!-- pages/home.sugar.php -->
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
Notice how the parent's `<main>` wrapper is preserved and the child's `<div>` wrapper is dropped. If you want the child wrapper preserved on replace, define the parent block as a fragment (`<s-template s:block="...">`).
:::

## Limitations and Rules

- Only one `s:extends` directive is allowed per template.
- When a template uses `s:extends`, only inheritance content (`s:block`, `s:append`, `s:prepend`) is kept. Any top-level markup or raw PHP outside those blocks is discarded, so variable assignments must live inside a block (or be passed via `s:with`) unless you render specific blocks via `Engine::render()` with the blocks option (see [Render Only Specific Blocks](#render-only-specific-blocks)).
- `s:block`, `s:append`, and `s:prepend` are mutually exclusive on the same element.
- Define each block name only once in a child template. To include parent block content, use `s:parent` inside that `s:block`.
- `s:parent` is only valid inside an `s:block` and must be used on `<s-template>`.
- `s:with` only scopes values to the immediate `s:include` and does not leak to parent scope.
- `s:include` and `s:extends` paths resolve relative to the current template unless `absolutePathsOnly` is enabled.

The example below focuses on the `s:extends` rule about top-level content being discarded.

::: code-group
```sugar [Do]
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<s-template s:block="content">
    <?php $var = 'I AM A VARIABLE'; ?>
    <h1>Home</h1>
    <s-template s:include="partials/card" s:with="['var' => $var]" />
</s-template>
```

```sugar [Don't]
<?php $var = 'I AM A VARIABLE'; ?>
<s-template s:extends="../layouts/base.sugar.php"></s-template>

<s-template s:block="content">
    <h1>Home</h1>
</s-template>
```
:::

The example below shows the mutual exclusivity of block directives on a single element.

::: code-group
```sugar [Do]
<section s:block="content">
    <p>Block content</p>
</section>
```

```sugar [Don't]
<section s:block="content" s:append="content">
    <p>Not allowed</p>
</section>
```
:::

The example below shows that `s:with` only scopes variables inside the included template, not the parent template.

::: code-group
```sugar [Do]
<s-template s:include="partials/card" s:with="['title' => 'Card']" />
```

```html [Don't]
<!-- $title is not available outside the include -->
<h2><?= $title ?></h2>
```
:::

The example below shows relative vs absolute-only paths.

::: code-group
```sugar [Relative]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
```

```sugar [Absolute-only]
<s-template s:extends="layouts/base.sugar.php"></s-template>
```
:::

## Template Inheritance Directives

- `s:extends` - Declare the parent layout this template inherits from.
- `s:block` - Replace a named block in the parent template.
- `s:append` - Add content after a parent block without replacing it.
- `s:prepend` - Add content before a parent block without replacing it.
- `s:parent` - Insert parent block content at the current position inside `s:block`.
- `s:include` - Insert another template at this location.
- `s:with` - Pass scoped variables to an `s:include`.

## Blocks

For newcomers, this is the easiest way to think about inheritance:

1. A parent template declares a named region with `s:block`.
2. A child template provides **one** `s:block` with the same name.
3. Inside that child block, use `s:parent` to decide where parent content should appear.

This keeps all block logic in one place and is the preferred pattern.

### Rules at a glance

- Use only one of `s:block`, `s:append`, or `s:prepend` on a single element.
- Define each block name only once per child template.
- `s:parent` is only valid inside `s:block`.
- `s:parent` must be used on `<s-template>`.

::: code-group
```sugar [Valid]
<s-template s:block="content">
    <s-template s:parent />
    <p>Extra content</p>
</s-template>
```

```sugar [Invalid: multiple block directives]
<section s:block="content" s:append="content">
    <p>Not allowed</p>
</section>
```

```sugar [Invalid: duplicate definitions]
<section s:block="content"><p>Child</p></section>
<section s:append="content"><p>Extra</p></section>
```

```sugar [Invalid: s:parent outside s:block]
<s-template s:parent />
```
:::

### Parent Placeholder (`s:parent`)

`s:parent` is the main inheritance tool. It inserts parent block content exactly where you place it inside a child `s:block`.

::: code-group
```sugar [Append parent content]
<s-template s:block="content">
    <s-template s:parent />
    <p>Appended content</p>
</s-template>
```

```sugar [Prepend parent content]
<s-template s:block="content">
    <p>Prepended content</p>
    <s-template s:parent />
</s-template>
```

```sugar [Insert parent in the middle]
<s-template s:block="content">
    <p>Before</p>
    <s-template s:parent />
    <p>After</p>
</s-template>
```
:::

### Append and Prepend Blocks

`s:append` and `s:prepend` are convenience helpers. They are shortcuts for simple "add before/after" cases where you do not need the full placement control of `s:parent`.

- Use `s:append` to add content after parent block children.
- Use `s:prepend` to add content before parent block children.
- For complex placement, prefer `s:block` + `s:parent`.

When the parent block is an element, the parent element wrapper remains authoritative and the wrapper of the node carrying `s:append`/`s:prepend` is stripped (only its children are inserted). Nested elements inside that node are kept. When the parent block is a fragment, the wrapper on the node carrying `s:append`/`s:prepend` is preserved.

::: code-group
```sugar [Append helper: before]
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

```html [Append helper: after]
<main>
    <p>Base content</p>
    <p>Appended content</p>
</main>
```
:::

::: code-group
```sugar [Prepend helper: before]
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

```html [Prepend helper: after]
<main>
    <p>Prepended content</p>
    <p>Base content</p>
</main>
```
:::

::: code-group
```sugar [Element parent wrapper behavior: before]
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

```html [Element parent wrapper behavior: after]
<main>
    <p>Base content</p>
    <section class="alert">
        <p>Wrapped content</p>
    </section>
</main>
```
:::

::: code-group
```sugar [Fragment parent wrapper behavior: before]
<!-- layout: layouts/base.sugar.php -->
<s-template s:block="content">
    <p>Base content</p>
</s-template>

<!-- child: pages/home.sugar.php -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<section class="alert" s:append="content">
    <p>Wrapped content</p>
</section>
```

```html [Fragment parent wrapper behavior: after]
<p>Base content</p>
<section class="alert">
    <p>Wrapped content</p>
</section>
```
:::

### Block Wrappers

Blocks keep their wrapper element by default. The wrapper that survives depends on the parent block type:

::: code-group
```sugar [Parent element]
<!-- Parent layout -->
<main s:block="content">
    <p>Base content</p>
</main>

<!-- Child replaces -->
<div s:block="content">
    <p>New content</p>
</div>

<!-- Output: parent wrapper preserved -->
<main>
    <p>New content</p>
</main>
```

```sugar [Parent fragment]
<!-- Parent layout -->
<s-template s:block="content">
    <p>Base content</p>
</s-template>

<!-- Child replaces -->
<div s:block="content">
    <p>New content</p>
</div>

<!-- Output: child wrapper preserved -->
<div>
    <p>New content</p>
</div>
```
:::

### Render Only Specific Blocks

You can render one or more blocks directly by passing a list of block names as the third argument to `Engine::render()`.
This skips layout inheritance and outputs the matching blocks in template order, preserving their wrapper elements. Includes still run before block extraction. It is especially handy when you need to return partials for AJAX responses or other incremental updates.

```php
echo $engine->render(
    template: 'pages/home',
    data: ['user' => $user],
    blocks: ['sidebar', 'content'],
);
```

```sugar
<!-- pages/home.sugar.php -->
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<aside s:block="sidebar">...</aside>
<section s:block="content">...</section>
```

```text
<!-- Output -->
...sidebar contents...content contents...
```

## Include

Includes are great for shared fragments like headers, footers, or cards.

::: code-group
```sugar [Basic]
<s-template s:include="partials/header"></s-template>
```

```sugar [Element wrapper]
<div class="card" s:include="partials/alert"></div>
```

```sugar [Nested]
<s-template s:include="partials/header"></s-template>
<section>
    <s-template s:include="partials/hero"></s-template>
</section>
```

```sugar [Scoped variables]
<s-template s:include="partials/user-card" s:with="['user' => $user]"></s-template>
```
:::

### Include Scope and `s:with`

Use `s:with` only in combination with `s:include` on the same element. It does not create a standalone scoped block and does not apply to child includes.

```sugar
<s-template s:include="partials/user-card" s:with="['user' => $user]"></s-template>
```

## Example

Combine layouts and partials for full pages:

::: code-group
```sugar [Layout + header]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Dashboard</title>
<div s:block="content">
    <s-template s:include="partials/header"></s-template>
    <s-template s:include="partials/stats"></s-template>
</div>
```

```sugar [Layout + sidebar]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Settings</title>
<div s:block="content">
    <aside>
        <s-template s:include="partials/sidebar"></s-template>
    </aside>
    <section>
        <s-template s:include="partials/settings"></s-template>
    </section>
</div>
```

```sugar [Layout + scoped include]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Profile</title>
<div s:block="content">
    <s-template s:include="partials/user-card" s:with="['user' => $user]"></s-template>
</div>
```

```sugar [Nested blocks]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Reports</title>
<div s:block="content">
    <section>
        <s-template s:include="partials/report-summary"></s-template>
        <s-template s:include="partials/report-table"></s-template>
    </section>
</div>
```
:::

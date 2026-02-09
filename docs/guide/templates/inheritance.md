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

This structure keeps layout inheritance, includes, and components easy to discover.

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
```html [Relative]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<s-template s:include="partials/header"></s-template>
```

```html [Absolute-only]
<s-template s:extends="layouts/base.sugar.php"></s-template>
<s-template s:include="partials/header"></s-template>
```
:::

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
```html [Parent layout]
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

```html [Child template]
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

::: warning
Only one `s:extends` directive is allowed per template.
:::

## Template Inheritance Directives

- `s:extends` - Declare the parent layout this template inherits from.
- `s:block` - Replace a named block in the parent template.
- `s:append` - Add content after a parent block without replacing it.
- `s:prepend` - Add content before a parent block without replacing it.
- `s:include` - Insert another template at this location.
- `s:with` - Pass scoped variables to an `s:include`.

## Blocks

Use `s:block` to replace a parent block. Use `s:append` or `s:prepend` to extend it. Only one of `s:block`, `s:append`, or `s:prepend` is allowed on the same element.

```html
<!-- Invalid: multiple block directives on one element -->
<section s:block="content" s:append="content">
    <p>Not allowed</p>
</section>
```

```html
<!-- Valid: multiple append elements targeting the same block -->
<section s:append="content"><p>First</p></section>
<section s:append="content"><p>Second</p></section>
```

### Append and Prepend Blocks

Use `s:append` or `s:prepend` in a child template to add content to a parent block instead of replacing it. When the parent block is an element, the appended/prepended element wrapper is stripped and its children are inserted into the parent block. When the parent block is a fragment, the wrapper is preserved.

::: code-group
```html [Append: before]
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

```html [Append: after]
<main>
    <p>Base content</p>
    <p>Appended content</p>
</main>
```
:::

::: code-group
```html [Prepend: before]
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

```html [Prepend: after]
<main>
    <p>Prepended content</p>
    <p>Base content</p>
</main>
```
:::

::: code-group
```html [With wrapper: before]
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

```html [With wrapper: after]
<main>
    <p>Base content</p>
    <section class="alert">
        <p>Wrapped content</p>
    </section>
</main>
```
:::

::: code-group
```html [Parent fragment: before]
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

```html [Parent fragment: after]
<p>Base content</p>
<section class="alert">
    <p>Wrapped content</p>
</section>
```
:::

### Block Wrappers

Blocks keep their wrapper element by default. The wrapper that survives depends on the parent block type:

::: code-group
```html [Parent element]
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

```html [Parent fragment]
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
echo $engine->render('pages/home', ['user' => $user], ['sidebar', 'content']);
```

```html
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
```html [Basic]
<s-template s:include="partials/header"></s-template>
```

```html [Element wrapper]
<div class="card" s:include="partials/alert"></div>
```

```html [Nested]
<s-template s:include="partials/header"></s-template>
<section>
    <s-template s:include="partials/hero"></s-template>
</section>
```

```html [Scoped variables]
<s-template s:include="partials/user-card" s:with="['user' => $user]"></s-template>
```
:::

### Include Scope and `s:with`

Use `s:with` only in combination with `s:include` on the same element. It does not create a standalone scoped block and does not apply to child includes.

```html
<s-template s:include="partials/user-card" s:with="['user' => $user]"></s-template>
```

## Example

Combine layouts and partials for full pages:

::: code-group
```html [Layout + header]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Dashboard</title>
<div s:block="content">
    <s-template s:include="partials/header"></s-template>
    <s-template s:include="partials/stats"></s-template>
</div>
```

```html [Layout + sidebar]
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

```html [Layout + scoped include]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Profile</title>
<div s:block="content">
    <s-template s:include="partials/user-card" s:with="['user' => $user]"></s-template>
</div>
```

```html [Nested blocks]
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

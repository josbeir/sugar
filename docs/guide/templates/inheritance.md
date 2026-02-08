---
title: Template Inheritance
description: Layouts, blocks, extends, includes, and s:with.
---

# Template Inheritance and Composition

Layouts, blocks, and includes let you build pages from reusable pieces. Use `s:extends` for layouts, `s:block` for named regions, and `s:include` for partials.

::: tip
Keep layout files under a `layouts/` folder and partials under `partials/` to make intent obvious.
:::

## Typical Folder Layout

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

## Layout Inheritance

**Base Layout** (`layouts/base.sugar.php`):
```html
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

**Child Template** (`pages/home.sugar.php`):
```html
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Home Page</title>
<div s:block="content">
    <h2>Welcome!</h2>
</div>
```

::: code-group
```html [Override one block]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Home Page</title>
```

```html [Override multiple]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Home Page</title>
<div s:block="content">
    <h2>Welcome!</h2>
    <p>Latest updates below.</p>
</div>
```
:::

::: warning
Only one `s:extends` directive is allowed per template.
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

## Extends

Use `s:extends` to inherit a layout and replace its `s:block` regions. The `s:extends` element can be empty; it just declares the parent template.

```html
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Account</title>
<div s:block="content">
    <s-template s:include="partials/account-header"></s-template>
    <s-template s:include="partials/account-body"></s-template>
</div>
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

### Unwrap Includes

`s:include` can be used on normal HTML elements. By default, the included content is inserted inside the element.
Add `s:nowrap` to disable the wrapper element and insert the included content directly.

```html
<div s:include="partials/alert" s:nowrap></div>
```

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

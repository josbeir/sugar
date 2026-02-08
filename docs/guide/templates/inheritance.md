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
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Home Page</title>
<div s:block="content">
    <h2>Welcome!</h2>
</div>
```

::: code-group
```html [Override one block]
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Home Page</title>
```

```html [Override multiple]
<div s:extends="../layouts/base.sugar.php"></div>
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
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Account</title>
<div s:block="content">
    <div s:include="partials/account-header"></div>
    <div s:include="partials/account-body"></div>
</div>
```

## Include

Includes are great for shared fragments like headers, footers, or cards.

::: code-group
```html [Basic]
<div s:include="partials/header"></div>
```

```html [Nested]
<div s:include="partials/header"></div>
<section>
    <div s:include="partials/hero"></div>
</section>
```

```html [Scoped variables]
<div s:include="partials/user-card" s:with="['user' => $user]"></div>
```
:::

### Include Scope and `s:with`

Use `s:with` only in combination with `s:include` on the same element. It does not create a standalone scoped block and does not apply to child includes.

```html
<div s:include="partials/user-card" s:with="['user' => $user]"></div>
```

## Example

Combine layouts and partials for full pages:

::: code-group
```html [Layout + header]
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Dashboard</title>
<div s:block="content">
    <div s:include="partials/header"></div>
    <div s:include="partials/stats"></div>
</div>
```

```html [Layout + sidebar]
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Settings</title>
<div s:block="content">
    <aside s:include="partials/sidebar"></aside>
    <section s:include="partials/settings"></section>
</div>
```

```html [Layout + scoped include]
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Profile</title>
<div s:block="content">
    <div s:include="partials/user-card" s:with="['user' => $user]"></div>
</div>
```

```html [Nested blocks]
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Reports</title>
<div s:block="content">
    <section>
        <div s:include="partials/report-summary"></div>
        <div s:include="partials/report-table"></div>
    </section>
</div>
```
:::

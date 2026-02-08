---
title: Components
description: Reusable components with props, slots, and attribute merging.
---

# Components

Components let you package markup into reusable building blocks with clear inputs and clean output. Think of them as small, composable templates that keep your views tidy without hiding the PHP you still need.

::: info
Components are template files resolved from a configurable components path and rendered with props and slots.
:::

## Basic Component Usage

**Component** (`components/s-button.sugar.php`):
```html
<button class="btn" type="button">
    <?= $slot ?>
</button>
```

**Usage**:
```html
<s-button>Click Me</s-button>
```

::: tip
Component tags use the `s-` prefix, and component filenames should include the same prefix (for example, `components/s-button.sugar.php`).
:::

::: info
If you change the prefix to `x-`, the filename becomes `components/x-button.sugar.php` and the tag becomes `<x-button>`.
:::

## Component Filename Pattern

Component files must use the element prefix and end with `.sugar.php`:

```text
{prefix}-{name}.sugar.php
```

Examples:

- `s-button.sugar.php` -> `<s-button>`
- `s-user-card.sugar.php` -> `<s-user-card>`
- `x-alert.sugar.php` -> `<x-alert>` when the prefix is `x-`

::: info
The fragment element filename (`s-template.sugar.php` or `x-template.sugar.php`) is reserved and not treated as a component.
:::

## Components in the Templates Tree

This is the typical place components live. The highlighted lines show the component directory and files.

```js
templates/
├── pages/
│   ├── home.sugar.php
│   └── profile.sugar.php
├── layouts/
│   └── base.sugar.php
├── partials/
│   ├── header.sugar.php
│   └── footer.sugar.php
└── components/ // [!code focus]
    ├── s-button.sugar.php // [!code focus]
    ├── s-card.sugar.php // [!code focus]
    └── s-alert.sugar.php // [!code focus]
```

## Props and Defaults

Components receive props as variables. Define defaults at the top of the component:

::: code-group
```php [components/s-card.sugar.php]
<?php
$title ??= 'Untitled';
$elevated ??= false;
?>

<article class="card" s:class="['card--elevated' => $elevated]">
    <h3><?= $title ?></h3>
    <?= $slot ?>
</article>
```

```html [Usage]
<s-card s:bind="['title' => 'Profile', 'elevated' => true]">
    <p>Profile content here</p>
</s-card>
```
:::

## Dynamic Component Invocation (`s:component`)

::: code-group
```html [Static]
<div s:component="button">Click Me</div>
```

```html [Dynamic]
<div s:component="$componentName">Click Me</div>
```

```html [Template wrapper]
<s-template s:component="alert" s:bind="['type' => 'info']">Hello</s-template>
```
:::

## Component Props with `s:bind`

::: code-group
```html [Literal]
<s-alert s:bind="['class' => 'alert alert-success', 'title' => 'Well done!']">
    Your changes have been saved.
</s-alert>
```

```html [Variable]
<s-alert s:bind="$alertProps">Your changes have been saved.</s-alert>
```
:::

::: warning
Only props passed through `s:bind` become component variables. Regular attributes are merged onto the root element.
:::

## Attribute Merging

Attributes not consumed as props are merged onto the component root element:

```html
<s-card class="shadow-lg" id="profile-card" @click="handleClick" x-data="{ open: false }">
    Profile content here
</s-card>
```

## Named Slots

::: code-group
```html [Named]
<s-card>
    <h3 s:slot="header">User Profile</h3>
    <s-template s:slot="footer">
        <button>Cancel</button>
        <button>Save</button>
    </s-template>
    <p>Main content here</p>
</s-card>
```

```html [Component template]
<article class="card">
    <header><?= $header ?? '' ?></header>
    <section><?= $slot ?></section>
    <footer><?= $footer ?? '' ?></footer>
</article>
```
:::

::: tip
Named slots are available as `$header`, `$footer`, or any `s:slot` name you provide.
:::

## Slot Fallbacks

```html
<article class="card">
    <header><?= $header ?? '<h3>Default header</h3>' ?></header>
    <section><?= $slot ?></section>
</article>
```

## Best Practices

- Use `s:bind` for component props, not HTML attributes.
- Keep a single root element in component templates.
- Output slot variables directly: `<?= $slot ?>`.
- Provide fallbacks for optional slots.

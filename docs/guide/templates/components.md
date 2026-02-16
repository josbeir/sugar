---
title: Components
description: Reusable components with props, slots, and attribute merging.
---

# Components

Components let you package markup into reusable building blocks with clear inputs and clean output. Think of them as small, composable templates that keep your views tidy without hiding the PHP you still need.

::: info
Components are opt-in. Enable the component extension first, then Sugar resolves component templates from configured component paths and renders them with props and slots.
:::

## Enable Components

Register the component extension on the engine builder:

```php
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        config: new SugarConfig(),
        templatePaths: __DIR__ . '/templates',
    ))
    ->withExtension(new ComponentExtension()) // [!code focus]
    ->build();
```

You can customize component directories when needed:

```php
->withExtension(new ComponentExtension(['components', 'ui/components']))
```

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

**Rendered output**:
```html
<button class="btn" type="button">Click Me</button>
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
The fragment element filename (for example, `s-template.sugar.php`, `x-template.sugar.php`, or your configured fragment name) is reserved and not treated as a component.
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

## Named Slots

::: code-group
```html [Usage]
<s-user-panel>
    <h3 s:slot="header">User Profile</h3>
    <p>Main content here</p>
    <p s:slot="footer">Last updated just now</p>
</s-user-panel>
```

```html [components/s-user-panel.sugar.php]
<article class="card">
    <header><?= $header ?? '' ?></header>
    <section><?= $slot ?></section>
    <footer><?= $footer ?? '' ?></footer>
</article>
```

```html [Rendered output]
<article class="card">
    <header><h3>User Profile</h3></header>
    <section><p>Main content here</p></section>
    <footer><p>Last updated just now</p></footer>
</article>
```
:::

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

Use `s:component` when the component name must be decided at runtime.

### Normal vs Dynamic

| Mode | Example | Component name known | Typical use |
| --- | --- | --- | --- |
| Normal component tag | `<s-button>...</s-button>` | Compile time | Most cases (preferred when name is fixed) |
| Dynamic component directive | `<div s:component="$componentName">...</div>` | Runtime | Feature flags, role-based UI, configurable widgets |

How they differ:

- Normal tags are resolved directly from the tag name (for example `s-button` -> `components/s-button.sugar.php`).
- Dynamic components resolve the name from an expression at render time.
- Both support slots and `s:bind` props.
- For dynamic rendering, the expression must evaluate to a valid component name.

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

::: tip
If the component name is known up front, use the normal component tag (`<s-button>`) for clearer templates. Use `s:component` only when the name must be dynamic.
:::

## Component Props with `s:bind`

::: code-group
```html [Literal]
<s-card s:bind="['title' => 'Well done!', 'elevated' => true]">
    Your changes have been saved.
</s-card>
```

```html [Variable]
<s-card s:bind="$cardProps">Your changes have been saved.</s-card>
```
:::

### What `s:bind` Does

`s:bind` maps array keys to variables inside the component template.

Example:

```html
<s-card s:bind="['title' => 'Well done!', 'elevated' => true]">
    Saved.
</s-card>
```

Inside `components/s-card.sugar.php`, those become:

- `$title` = `'Well done!'`
- `$elevated` = `true`
- `$slot` = `'Saved.'`

::: warning
Only props passed through `s:bind` become component variables. Regular attributes are merged onto the root element.
:::

For example, pass classes and IDs as regular attributes:

```html
<s-card s:bind="['title' => 'Well done!']" class="shadow-lg" id="notice-card">
    Saved.
</s-card>
```

## Attribute Merging

Attributes not consumed as props are merged onto the component root element:

```html
<s-card class="shadow-lg" id="profile-card" @click="handleClick" x-data="{ open: false }">
    Profile content here
</s-card>
```

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

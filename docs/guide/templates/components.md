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

**Component** (`components/button.sugar.php`):
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
Component tags use the `s-` prefix, so `components/button.sugar.php` becomes `<s-button>`.
:::

## Props and Defaults

Components receive props as variables. Define defaults at the top of the component:

::: code-group
```php [components/card.sugar.php]
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

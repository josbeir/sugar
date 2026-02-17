---
title: Components
description: Reusable components with props, slots, and attribute merging.
---

# Components

Components let you package markup into reusable building blocks with clear inputs and clean output. They provide a way to create self-contained, reusable UI elements with well-defined interfaces through props, slots, and attribute merging.

::: info
Components are opt-in. Enable the component extension first, then Sugar resolves component templates from configured component paths and renders them with props and slots.
:::

## Setup

Before using components, you need to enable the component extension on your engine builder.

### Basic Setup

Register the component extension during engine configuration:

```php
use Sugar\Core\Config\SugarConfig;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;

$engine = Engine::builder()
    ->withTemplateLoader(new FileTemplateLoader(
        templatePaths: __DIR__ . '/templates',
    ))
    ->withExtension(new ComponentExtension()) // [!code focus]
    ->build();
```

The component extension automatically discovers components in the configured `components/` directory within all registered template namespaces.

### Customizing Component Directories

By default, components are loaded from a `components/` directory within each namespace. You can customize this:

```php
->withExtension(new ComponentExtension(['components', 'ui/components', 'shared']))
```

This searches for components in multiple subdirectories within each template namespace, in priority order. The first match is used.

## Quick Start

A component is just a template file that you call with a custom tag. Here's the simplest possible component:

::: code-group
```html [components/s-button.sugar.php]
<button class="btn" type="button">
    <?= $slot ?>
</button>
```

```sugar [Usage]
<s-button>Click Me</s-button>
```

```html [Rendered output]
<button class="btn" type="button">Click Me</button>
```
:::

In this example, `<s-button>` maps to `components/s-button.sugar.php`, and the inner content (`Click Me`) is injected into `<?= $slot ?>`.

## Component Basics

### Filename Patterns

Component files must use the element prefix and end with `.sugar.php`:

```text
{prefix}-{name}.sugar.php
```

Examples:

- `s-button.sugar.php` → `<s-button>`
- `s-user-card.sugar.php` → `<s-user-card>`
- `x-alert.sugar.php` → `<x-alert>` when the prefix is `x-`

::: info
The fragment element filename (for example, `s-template.sugar.php`, `x-template.sugar.php`, or your configured fragment name) is reserved and not treated as a component.
:::

### File Organization

Components live in a `components/` directory within your template paths:

```js
templates/
├── pages/
│   ├── home.sugar.php
│   └── profile.sugar.php
├── layouts/
│   └── base.sugar.php
└── components/ // [!code focus]
    ├── s-button.sugar.php // [!code focus]
    ├── s-card.sugar.php // [!code focus]
    └── s-alert.sugar.php // [!code focus]
```

## Working with Slots

Slots are placeholders in your component template where content from the usage site is inserted. Think of a slot as "content that flows from the calling template into the component template".

### Default Slots

When you place content inside a component tag without any `s:slot` attribute, that content becomes the default slot:

::: code-group
```html [components/s-button.sugar.php]
<button class="btn" type="button">
    <?= $slot ?>
</button>
```

```sugar [Usage]
<s-button>Click Me</s-button>
```

```html [Rendered output]
<button class="btn" type="button">Click Me</button>
```
:::

### Named Slots

Use `s:slot="name"` in your usage markup to send content to specific named slots:

::: code-group
```sugar [Usage]
<s-user-panel>
    <h3 s:slot="header">User Profile</h3>
    <p>Main content here</p>
    <p s:slot="footer">Last updated just now</p>
</s-user-panel>
```

```html [components/s-user-panel.sugar.php]
<article class="card">
    <header s:slot="header">
        <h3>Default header</h3>
    </header>
    <section s:slot>
        <p>Empty state</p>
    </section>
    <footer s:slot="footer"></footer>
</article>
```

```html [Rendered output]
<article class="card">
    <header>
        <h3>User Profile</h3>
    </header>
    <section>
        <p>Main content here</p>
    </section>
    <footer>
        <p>Last updated just now</p>
    </footer>
</article>
```
:::

### Slot Outlets

In your component template, use `s:slot` attributes to define where slot content should be rendered:

- `s:slot` (no value) defines the outlet for the default slot
- `s:slot="name"` defines the outlet for a named slot
- Child content inside outlet elements serves as fallback when no content is provided

::: code-group
```html [Component with fallbacks]
<div class="modal">
    <header s:slot="header">
        <h2>Default Title</h2>
    </header>
    <main s:slot>
        <p>Default body content</p>
    </main>
    <footer s:slot="footer"></footer>
</div>
```

```sugar [Usage with some slots empty]
<s-modal>
    <p>Custom body content</p>
</s-modal>
```

```html [Rendered - uses fallback for header]
<div class="modal">
    <header>
        <h2>Default Title</h2>
    </header>
    <main>
        <p>Custom body content</p>
    </main>
    <footer></footer>
</div>
```
:::

::: tip
Using `s:slot` without a value targets the same default slot content as `<?= $slot ?>`. The difference is that an outlet element can wrap that content and provide fallback children.
:::

## Props and Data

Components receive props as variables using the `s:bind` directive. This lets you pass dynamic data into your components.

### Passing Props with `s:bind`

Use `s:bind` with an array to map keys to component variables:

::: code-group
```sugar [Literal array]
<s-card s:bind="['title' => 'Well done!', 'elevated' => true]">
    Your changes have been saved.
</s-card>
```

```sugar [Variable]
<s-card s:bind="$cardProps">Your changes have been saved.</s-card>
```
:::

Inside `components/s-card.sugar.php`, array keys become:

- `$title` = `'Well done!'`
- `$elevated` = `true`
- `$slot` = `'Your changes have been saved.'`

### Default Values

Define default values at the top of your component template using the null coalescing operator:

::: code-group
```sugar [components/s-card.sugar.php]
<?php
$title ??= 'Untitled';
$elevated ??= false;
?>

<article class="card" s:class="['card--elevated' => $elevated]">
    <h3><?= $title ?></h3>
    <?= $slot ?>
</article>
```

```sugar [Usage]
<s-card s:bind="['title' => 'Profile', 'elevated' => true]">
    <p>Profile content here</p>
</s-card>
```
:::

::: warning
Only props passed through `s:bind` become component variables. Regular HTML attributes (like `class`, `id`, `data-*`) are merged onto the root element instead.
:::

## Attribute Merging

Attributes that are not consumed as props through `s:bind` are automatically merged onto the component's root element:

::: code-group
```sugar [components/s-card.sugar.php]
<?php
$title ??= 'Untitled';
?>

<article class="card">
    <h3><?= $title ?></h3>
    <?= $slot ?>
</article>
```

```sugar [Usage]
<s-card
    s:bind="['title' => 'Profile']"
    class="shadow-lg"
    id="profile-card"
    @click="handleClick"
    x-data="{ open: false }"
>
    Profile content here
</s-card>
```

```html [Rendered output]
<article class="card shadow-lg" id="profile-card" @click="handleClick" x-data="{ open: false }">
    <h3>Profile</h3>
    Profile content here
</article>
```
:::

The attributes (`class`, `id`, `@click`, `x-data`) are applied to the root `<article>` element in the component template. This makes components work seamlessly with CSS frameworks, Alpine.js, and other attribute-based libraries.

::: tip
Pass data values as props via `s:bind`, and styling/behavior attributes as regular HTML attributes.
:::

## Advanced Usage

### Dynamic Component Invocation

Use the `s:component` directive when the component name must be determined at runtime:

::: code-group
```sugar [Static component name]
<div s:component="button">Click Me</div>
```

```sugar [Dynamic from variable]
<div s:component="$componentName">Click Me</div>
```

```sugar [With props]
<s-template s:component="alert" s:bind="['type' => 'info']">Hello</s-template>
```
:::

**When to use what:**

| Mode | Example | Component name known | Typical use |
| --- | --- | --- | --- |
| Normal component tag | `<s-button>...</s-button>` | Compile time | Most cases (preferred when name is fixed) |
| Dynamic component directive | `<div s:component="$componentName">...</div>` | Runtime | Feature flags, role-based UI, configurable widgets |

Both support slots and `s:bind` props, but normal tags provide clearer templates when the component name is known up front.

::: tip
Prefer normal component tags (`<s-button>`) for clearer templates. Use `s:component` only when the component name must be dynamic at runtime.
:::

### Multi-Namespace Component Loading

When working with plugins or multiple template namespaces, components are resolved across all registered namespaces in registration order.

For example, with this setup:

```php
$loader = new FileTemplateLoader([__DIR__ . '/app/templates']);
$loader->registerNamespace(
    'plugin-auth',
    new TemplateNamespaceDefinition([__DIR__ . '/plugins/auth/templates'])
);

$engine = Engine::builder()
    ->withTemplateLoader($loader)
    ->withExtension(new ComponentExtension(['components']))
    ->build();
```

The `<s-button>` component is resolved in this order:

1. `app/templates/components/s-button.sugar.php`
2. `plugins/auth/templates/components/s-button.sugar.php`

The first match found is used. This allows plugins and namespaces to share the same component hierarchy while letting app components take priority.

### Multi-Namespace File Structure

When your application uses plugins or shared packages, each namespace can have its own component directory:

```js
app/
└── templates/
    ├── pages/
    ├── layouts/
    └── components/
        ├── s-button.sugar.php (priority: #1)
        ├── s-card.sugar.php
        └── s-dashboard.sugar.php

plugins/
└── auth/
    └── templates/
        └── components/
            ├── s-button.sugar.php (overridden by @app)
            ├── s-login-form.sugar.php (priority: #2)
            └── s-auth-modal.sugar.php

packages/
└── shared-ui/
    └── templates/
        └── components/
            ├── s-alert.sugar.php (priority: #3)
            └── s-tooltip.sugar.php
```

With this structure:
- `<s-button>` loads from `app/templates/components/` (first registered)
- `<s-login-form>` loads from `plugins/auth/templates/components/` (second registered)
- `<s-alert>` loads from `packages/shared-ui/templates/components/` (third registered)

::: tip
By default, `@app` is registered first and therefore has priority over plugin namespaces during component lookup. Additional namespaces are searched after `@app` in registration order.
:::

## Best Practices

- **Use `s:bind` for component props**, not HTML attributes
- **Keep a single root element** in component templates for proper attribute merging
- **Use `s:slot` outlets** to properly position default and named slot content
- **Provide fallback content** for optional slots to handle cases where callers don't provide content
- **Prefer normal component tags** (`<s-button>`) over dynamic `s:component` when the component name is known
- **Define prop defaults** at the top of your component using `??=` for better readability
- **Name components clearly** with descriptive, hyphenated names (e.g., `s-user-card`, not `s-uc`)

---
# https://vitepress.dev/reference/default-theme-home-page
layout: home

hero:
  name: "Sugar <span style=\"font-size: .65em; opacity: 0.4\">(template engine)</span>"
  text: "Write PHP templates that compile into... PHP"
  tagline: "Context-aware escaping, without the template noise."
  image:
    src: /hero/sugar-cube.svg
    alt: Sugar cube
  actions:
    - theme: brand
      text: Get Started
      link: /guide/introduction/getting-started
    - theme: alt
      text: What Is Sugar
      link: /guide/introduction/what-is-sugar

features:
  - title: Directive Attributes
    icon: "🧩"
    details: Use `s:` attributes for control flow without PHP noise.
  - title: Context-Aware Escaping
    icon: "🛡️"
    details: Auto-escapes HTML, attributes, URLs, JS, and CSS with no extra syntax.
  - title: Component Composition
    icon: "🧱"
    details: Props, slots, and attribute merging with zero runtime cost.
---

## A Taste of the Syntax

Here is a small, beginner-friendly example. Click each tab to see how layout inheritance and components fit together:

::: code-group

```sugar [Child Template]
<!-- pages/home.sugar.php -->
<s-template s:extends="layouts/app.sugar.php"></s-template>

<title s:block="title">Home</title>

<s-template s:block="content">
  <h1 class="title">Welcome, <?= $user->name ?></h1>

  <s-button class="btn-dark" s:class="['btn-active' => $isActive]">
    Click me
  </s-button>
  <p s:if="$showHint">You can hide this hint with s:if.</p>
</s-template>
```

```sugar [Parent Layout]
<!-- layouts/app.sugar.php -->
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title s:block="title">Sugar App</title>
</head>
<body>
  <main s:block="content">Default content</main>
</body>
</html>
```

```sugar [Component: Button]
<!-- components/s-button.sugar.php -->
<button class="btn">
  <?= $slot ?>
</button>
```

```sugar [Rendered output]
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Home</title>
</head>
<body>
  <main>
    <h1>Welcome, Jasper</h1>
    <button class="btn btn-dark btn-active">Click me</button>
    <small class="hint">Press to continue</small>
    <p>You can hide this hint with s:if.</p>
  </main>
</body>
</html>
```

:::

::: tip
New to Sugar? Start with [Getting Started](/guide/introduction/getting-started), then move to [Template Inheritance](/guide/templates/inheritance) and [Components](/guide/templates/components).
:::

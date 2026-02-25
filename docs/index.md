---
# https://vitepress.dev/reference/default-theme-home-page
layout: home

hero:
  name: "Sugar"
  text: "A PHP template engine for cleaner templates"
  tagline: "Keep writing PHP templates—Sugar adds directive attributes and context-aware escaping."
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
  - title: Cleaner Control Flow
    icon:
      src: /icons/control-flow.svg
    details: "Use s: attributes like s:if and s:foreach directly in HTML."
    link: /guide/language/directives
  - title: Template Inheritance
    icon:
      src: /icons/inheritance.svg
    details: "Compose layouts with s:extends, s:block, and s:include."
    link: /guide/templates/inheritance
  - title: Built-In Safe Output
    icon:
      src: /icons/safe-output.svg
    details: Auto-escapes HTML, attributes, URLs, JavaScript, and CSS by context.
    link: /guide/language/escaping
  - title: Reusable Components
    icon:
      src: /icons/components.svg
    details: Build UI with props, slots, and merged attributes in plain PHP templates.
    link: /guide/templates/components

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

  <ul>
    <li s:foreach="$items as $item"><?= $item ?></li>
  </ul>

  <s-unless condition="$isPremium">
    <p>Upgrade to unlock more features.</p>
  </s-unless>
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
    <h1>Welcome, Alice</h1>
    <button class="btn btn-dark btn-active">Click me</button>
    <small class="hint">Press to continue</small>
    <p>You can hide this hint with s:if.</p>
    <ul>
      <li>Docs</li>
      <li>API</li>
    </ul>
    <p>Upgrade to unlock more features.</p>
  </main>
</body>
</html>
```

:::

::: tip
New to Sugar? Start with [Getting Started](/guide/introduction/getting-started), then move to [Template Inheritance](/guide/templates/inheritance) and [Components](/guide/templates/components).
:::

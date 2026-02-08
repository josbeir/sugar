---
# https://vitepress.dev/reference/default-theme-home-page
layout: home

hero:
  name: "Sugar <span style=\"font-size: .65em; opacity: 0.4\">(template engine)</span>"
  text: "Write PHP templates that compile into... PHP"
  tagline: Context-aware escaping, without the template noise.
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

::: code-group
```php [Control Flow]
<ul s:forelse="$items as $item">
    <li><?= $item ?></li>
</ul>
<div s:empty>No items found</div>
```

```html [Components]
<!-- components/card.sugar.php -->
<article class="card">
  <header><?= $header ?? '' ?></header>
  <section><?= $slot ?></section>
</article>

<!-- usage -->
<s-card s:bind="$cardProps">
    <div s:slot="header">Welcome back</div>
    <p>Hello, <?= $user->name ?></p>
</s-card>
```

```php [Pipes]
<!-- template -->
<h1><?= $title |> strtoupper(...) |> substr(..., 0, 50) ?></h1>

<!-- compiled -->
<h1><?= \Sugar\Escape\Escaper::html(substr(strtoupper($title), 0, 50)) ?></h1>
```

```php [Mixed PHP]
<?php if ($user->isAdmin()): ?>
  <strong>Admin</strong>
<?php endif; ?>
<span s:text="$user->name"></span>
```
:::

## Built For Real Templates

::: code-group
```html [Layout]
<div s:extends="../layouts/base.sugar.php"></div>
<title s:block="title">Dashboard</title>
<div s:block="content">
    <div s:include="partials/header"></div>
    <div s:include="partials/stats"></div>
</div>
```

```html [Safe Output]
<!-- template -->
<a href="/search?q=<?= $query ?>">Search</a>
<div data-user="<?= $userName ?>"></div>
<style>.badge::before { content: '<?= $label ?>'; }</style>
<p><?= $summary ?></p>

<!-- compiled -->
<a href="/search?q=<?= \Sugar\Escape\Escaper::url($query) ?>">Search</a>
<div data-user="<?= \Sugar\Escape\Escaper::attr($userName) ?>"></div>
<style>.badge::before { content: '<?= \Sugar\Escape\Escaper::css($label) ?>'; }</style>
<p><?= \Sugar\Escape\Escaper::html($summary) ?></p>
```
:::


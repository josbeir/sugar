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

::: warning
Don't worry: This **Sugar** is **safe** for diabetics. :drum:
:::

## A Taste of the Syntax

```html [Sugar Template]
<ul s:forelse="$orders as $order">
  <li s:class="['paid' => $order->isPaid, 'unpaid' => !$order->isPaid]">
    #<?= $order->number ?>
    <span><?= $order->customerName ?></span>
    <a href="/orders/<?= $order->id ?>">View</a>
  </li>
</ul>
<p s:empty>No orders yet</p>
```

::: code-group
```php [Vanilla PHP]
<?php if (!empty($orders)): ?>
  <ul>
    <?php foreach ($orders as $order): ?>
      <li class="<?= $order->isPaid ? 'paid' : 'unpaid' ?>">
        #<?= htmlspecialchars($order->number, ENT_QUOTES, 'UTF-8') ?>
        <span><?= htmlspecialchars($order->customerName, ENT_QUOTES, 'UTF-8') ?></span>
        <a href="/orders/<?= urlencode($order->id) ?>">View</a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php else: ?>
  <p>No orders yet</p>
<?php endif; ?>
```

```php [Compiled Template]
<?php if (!\Sugar\Runtime\EmptyHelper::isEmpty($orders)): ?>
  <ul>
    <?php foreach ($orders as $order): ?>
      <li class="<?= \Sugar\Runtime\HtmlAttributeHelper::classNames(['paid' => $order->isPaid, 'unpaid' => !$order->isPaid]) ?>">
        #<?= \Sugar\Escape\Escaper::html($order->number) ?>
        <span><?= \Sugar\Escape\Escaper::html($order->customerName) ?></span>
        <a href="/orders/<?= \Sugar\Escape\Escaper::url($order->id) ?>">View</a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php else: ?>
  <p>No orders yet</p>
<?php endif; ?>
```
:::

## Built For Real Templates

::: code-group
```html [Layout]
<s-template s:extends="../layouts/base.sugar.php"></s-template>
<title s:block="title">Dashboard</title>
<div s:block="content">
  <s-template s:include="partials/header"></s-template>
  <s-template s:include="partials/stats"></s-template>
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

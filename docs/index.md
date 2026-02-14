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

Here's what a real template looks like with Sugar. Click each tab to see the full picture:

::: code-group

```html [Child Template]
<!-- pages/orders.sugar.php -->
<s-template s:extends="layouts/app.sugar.php"></s-template>

<title s:block="title">Orders</title>

<div s:block="content">
  <h1>Your Orders</h1>

  <!-- Include reusable partials -->
  <s-template s:include="partials/filter-bar"></s-template>

  <!-- Use a component with props and slots -->
  <s-orders-table s:bind="['orders' => $orders, 'pagination' => $pagination]">
    <h2>Order Summary</h2>
    <p>View and manage all your orders below.</p>

    <div s:slot="empty">
      <p>No orders found. <a href="/orders/new">Create one</a>.</p>
    </div>
  </s-orders-table>

  <!-- Another include -->
  <s-template s:include="partials/pagination" s:with="['pagination' => $pagination]"></s-template>
</div>
```

```html [Parent Layout]
<!-- layouts/app.sugar.php -->
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title s:block="title">My App</title>
</head>
<body>
  <header>
    <nav>
      <a href="/">Home</a>
      <a href="/orders">Orders</a>
      <a href="/settings">Settings</a>
    </nav>
  </header>

  <main>
    <div s:block="content">
      <!-- Child pages replace this block -->
    </div>
  </main>

  <footer>
    <p>&copy; 2024. All rights reserved.</p>
  </footer>
</body>
</html>
```

```html [Component: OrdersTable]
<!-- components/s-orders-table.sugar.php -->
<div class="orders-table">
  <!-- Main slot content (if needed) -->
  <div class="header">
    <?= $slot ?>
  </div>

  <s-template s:if="!empty($orders)">
    <table>
      <thead>
        <tr>
          <th>Order #</th>
          <th>Customer</th>
          <th>Status</th>
          <th>Total</th>
        </tr>
      </thead>
      <tbody>
        <tr s:foreach="$orders as $order"
          s:class="['paid' => $order->isPaid, 'pending' => !$order->isPaid]"
        >
          <td>#<?= $order->id ?></td>
          <td><?= $order->customerName ?></td>
          <td>
            <span s:class="['badge-success' => $order->isPaid, 'badge-warning' => !$order->isPaid]">
              <?= $order->isPaid ? 'Paid' : 'Pending' ?>
            </span>
          </td>
          <td>$<?= number_format($order->total, 2) ?></td>
        </tr>
      </tbody>
    </table>
  </s-template>

  <!-- Empty state named slot -->
  <s-template s:if="empty($orders)">
    <?= $empty ?? '' ?>
  </s-template>
</div>
```

:::



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

```php [Child Template]
<!-- pages/orders.sugar.php -->
<s-template s:extends="layouts/app.sugar.php"></s-template>

<title s:block="title">Orders</title>

<div s:block="content">
  <h1>Your Orders</h1>
  <p class="rating" aria-label="Priority">
    <span s:times="3">★</span>
  </p>

  <p s:if="$showFilters" class="muted">Refine results using the filters below.</p>
  <p s:else class="muted">Showing all orders.</p>

  <!-- Include reusable partials -->
  <s-template s:include="partials/filter-bar"></s-template>

  <div s:cache="['key' => 'orders:summary:' . $user->id, 'ttl' => 300]"
    s:class="['summary', 'summary--empty' => empty($orders)]">
    <strong><?= count($orders) ?></strong> orders found
    <span class="muted">(<?= $user->name |> trim(...) |> strtoupper(...) ?>)</span>
  </div>

  <?php
    // PHP is still available, just like you expect
    $visibleOrders = array_values(array_filter(
      $orders,
      static fn($order) => $order->isVisible,
    ));
    $topOrder = $visibleOrders[0] ?? null;
  ?>
  <p s:if="$topOrder" class="muted">Top visible order: <?= $topOrder->id ?></p>

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

```php [Parent Layout]
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

```php [Component: OrdersTable]
<!-- components/s-orders-table.sugar.php -->
<div class="orders-table">
  <!-- Main slot content (if needed) -->
  <div class="header">
    <?= $slot ?>
  </div>

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
      <tr s:forelse="$orders as $order"
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

      <!-- Empty state named slot -->
      <tr s:empty>
        <td colspan="4"><?= $empty ?? 'No orders found' ?></td>
      </tr>
    </tbody>
  </table>
</div>
```

:::



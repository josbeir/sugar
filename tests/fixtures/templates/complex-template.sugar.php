<!DOCTYPE html>
<html>
<head>
    <title><?= $title ?></title>
    <style>
        body { background: <?= $bgColor ?>; }
    </style>
</head>
<body>
    <h1><?= $heading ?></h1>

    <!-- Conditional banner -->
    <div s:if="$showBanner" class="banner">
        <p><?= $bannerMessage ?></p>
    </div>

    <!-- Trusted HTML content (pre-rendered) -->
    <article><?= $articleBody |> raw() ?></article>

    <!-- Short form for trusted content -->
    <aside><?= $sidebarHtml |> raw() ?></aside>

    <script>
        var config = <?= $config ?>;
    </script>

    <!-- List with foreach and loop metadata -->
    <ul s:foreach="$items as $item">
        <li><?= $item->name ?> - Item <?= $loop->iteration ?> of <?= $loop->count ?></li>
    </ul>

    <!-- List with forelse for empty state -->
    <ul s:forelse="$products as $product">
        <li><?= $product->title ?> - $<?= $product->price ?></li>
    </ul>
    <div s:empty class="empty-state">
        <p>No products available</p>
    </div>

    <a href="<?= $link ?>">Click here</a>

	<div s:class="['highlight' => true, 'disabled' => !false]">
	    This div's classes depend on conditions.
	</div>

    <!-- Footer with both escaped and raw content -->
    <footer>
        <p>Copyright <?= $year ?></p>
        <?= $footerContent |> raw() ?>
    </footer>
</body>
</html>

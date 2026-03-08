<?php
/**
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Template\SiteContext $this
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title s:trim>
		<s-ifblock name="title">
			<s-template s:block="title" /> |
		</s-ifblock>
		<?= $site->title ?? 'Sugar Templates' ?>
	</title>
	<s-template s:include="../partials/meta" />
	<link rel="icon" href="/favicon.ico" sizes="any" />
	<link rel="icon" href="/favicon.svg" type="image/svg+xml" />
	<link rel="apple-touch-icon" href="/apple-touch-icon.png" />
	<s-vite src="[
		'assets/css/docs.css',
		'assets/js/docs.js',
	]" />
</head>
<body class="bg-base-200 min-h-screen" hx-boost="true" hx-indicator="#page-loader">
<div id="page-loader" aria-hidden="true"></div>
<div class="bg-base-100 drawer mx-auto max-w-[100rem] lg:drawer-open">
	<input id="docs-drawer" type="checkbox" class="drawer-toggle" />

	<div class="drawer-content min-h-screen flex flex-col">
		<s-template s:include="../partials/header" />

		<main class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 lg:py-8">
			<div class="flex items-start gap-8" x-data="tocPage">
				<div class="min-w-0 flex-1" data-prose s:block="content">
					Default page content
				</div>
				<s-ifblock name="toc">
					<aside
						class="hidden xl:block w-56 shrink-0 sticky top-24 overflow-y-auto max-h-[calc(100vh-7rem)]"
						aria-label="On this page"
					>
						<s-template s:block="toc" />
					</aside>
				</s-ifblock>
			</div>
		</main>

		<footer class="border-t border-base-300 mt-auto">
			<div class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 text-sm text-base-content/70 text-center">
				<p>
					Released under the
					<a
						class="link link-hover"
						href="<?=  $site->meta('licenseLink', '#') ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?= $site->meta('license') ?>
					</a>.
				</p>
				<p><?= $site->meta('footer') ?></p>
			</div>
		</footer>
	</div>

	<aside class="drawer-side" aria-label="Documentation navigation" s:block="sidebar">
		<label for="docs-drawer" class="drawer-overlay"></label>
		<s-template s:include="../partials/sidebar" />
	</aside>
</div>
</body>
</html>

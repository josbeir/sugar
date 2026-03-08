<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */
?>
<title s:prepend="title"><?= $title ?></title>

<s-template s:extends="layout/base" />

<s-template
	s:include="partials/toc"
	s:if="$page->toc > 0"
	s:block="toc"
/>
<s-template s:block="content">
	<div class="breadcrumbs text-sm mb-4 text-base-content/70">
		<ul>
			<li><a href="<?= $this->url('/') ?>">Docs</a></li>
			<li><?= $page->meta['navigationtitle'] ?? $page->title ?></li>
		</ul>
	</div>

	<article class="card bg-base-100 border border-base-300 shadow-sm">
		<div class="card-body p-6 sm:p-8 lg:p-10">
			<div class="prose prose-invert max-w-none">
				<?= $content |> raw() ?>
			</div>
		</div>
	</article>

	<div class="mt-8">
		<s-template s:include="partials/pagenav" />
	</div>
</s-template>

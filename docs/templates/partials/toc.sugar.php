<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */
?>
<nav aria-label="On this page">
	<p class="text-xs font-semibold uppercase tracking-wider text-base-content/50 mb-3 px-1">On this page</p>
	<ul class="space-y-px text-sm" s:foreach="$page->toc as $entry">
		<li s:if="$entry->level <= 3" style="padding-left: <?= max(0, ($entry->level - 2) * 12) ?>px">
			<a
				href="#<?= $entry->id ?>"
				title="<?= $entry->text ?>"
				:class="{ 'text-primary bg-base-200': $store.toc.active === '<?= $entry->id ?>' }"
				class="block py-1 px-2 rounded-md text-base-content/60 hover:text-base-content hover:bg-base-200 transition-colors duration-150 leading-snug truncate"
			>
				<?= $entry->text ?>
			</a>
		</li>
	</ul>
</nav>

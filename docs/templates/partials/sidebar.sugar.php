<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */
?>
<div class="w-72 h-full min-h-screen border-r border-base-300 bg-base-200 text-base-content">
	<nav class="h-full overflow-y-auto p-4 lg:p-5">
		<a class="btn btn-ghost justify-start normal-case text-base sm:text-lg w-full mb-3" href="<?= $this->url('/') ?>">
			<s-site-brand s:bind="['site' => $site]" />
		</a>
		<ul class="menu rounded-box w-56">
			<?php $rootPages = $this->rootPages()->filter(
				static fn(Glaze\Content\ContentPage $p): bool => (bool)($p->meta('navigation') ?? true),
			); ?>
			<li s:foreach="$rootPages as $menuPage">
				<a
					s:trim
					href="<?= $this->url($menuPage->urlPath) ?>"
					s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
				>
					<?= $menuPage->meta('navigationTitle') ?? $menuPage->title ?>
				</a>
			</li>
			<li s:foreach="$this->sections() as $section">
				<?php $sectionPages = $section->pages()->filter(
					static fn(Glaze\Content\ContentPage $p): bool => (bool)($p->meta('navigation') ?? true),
				); ?>
				<?php $sectionCollapsed = (bool)$section->index()->meta('collapsed', false); ?>
				<details s:if="$sectionCollapsed">
					<summary><?= $section->label() ?></summary>
					<ul>
						<?php /** @var Glaze\Content\ContentPage $menuPage */ ?>
						<li s:foreach="$sectionPages as $menuPage">
							<a
								s:trim
								href="<?= $this->url($menuPage->urlPath) ?>"
								s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
							>
								<?= $menuPage->meta('navigationTitle') ?? $menuPage->title ?>
							</a>
						</li>
					</ul>
				</details>
				<h2 class="menu-title" s:if="!$sectionCollapsed"><?= $section->label() ?></h2>
				<ul s:if="!$sectionCollapsed">
					<?php /** @var Glaze\Content\ContentPage $menuPage */ ?>
					<li s:foreach="$sectionPages as $menuPage">
						<a
							s:trim
							href="<?= $this->url($menuPage->urlPath) ?>"
							s:class="['menu-active' => $this->isCurrent($menuPage->urlPath)]"
						>
							<?= $menuPage->meta('navigationTitle') ?? $menuPage->title ?>
						</a>
					</li>
				</ul>
			</li>
		</ul>
	</nav>
</div>

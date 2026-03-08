<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */
?>
<s-template s:extends="layout/base">

<title s:prepend="title"><?= $title ?></title>

<s-template s:block="sidebar">
	<label for="docs-drawer" class="drawer-overlay lg:hidden"></label>
	<div class="lg:hidden">
		<s-template s:include="partials/sidebar" />
	</div>
</s-template>

<s-template s:block="content">
	<section class="mb-8 sm:mb-10 lg:mb-12">
		<div class="relative py-2 sm:py-4">
			<div class="relative z-10 grid gap-8 lg:gap-12 xl:gap-14 lg:grid-cols-[minmax(0,1fr)_24rem] xl:grid-cols-[minmax(0,1fr)_28rem] lg:items-center">
				<div class="max-w-3xl">
					<div class="badge badge-primary badge-outline mb-4" s:if="$page->hasMeta('hero.category')">
						<?= $page->meta('hero.category') ?>
					</div>
					<h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold tracking-tight leading-tight mb-5">
						<?= $page->meta('hero.title', $title) ?>
					</h1>
					<p class="text-base-content/75 text-lg lg:text-xl max-w-2xl" s:if="$page->hasMeta('hero.subtitle')">
						<?= $page->meta('hero.subtitle') ?>
					</p>

					<div class="flex flex-wrap gap-3 sm:gap-4 mt-8">
						<a
							class="btn btn-primary btn-md"
							s:if="$page->hasMeta('hero.primaryAction.label')"
							href="<?= $this->url($page->meta('hero.primaryAction.href', '/')) ?>"
						>
							<?= $page->meta('hero.primaryAction.label') ?>
						</a>

						<a
							class="btn btn-ghost btn-md"
							s:if="$page->hasMeta('hero.secondaryAction.label')"
							href="<?= $this->url($page->meta('hero.secondaryAction.href', '/')) ?>"
						>
							<?= $page->meta('hero.secondaryAction.label') ?>
						</a>
					</div>
				</div>

				<div class="hidden lg:flex lg:justify-end">
					<img
						class="w-64 xl:w-76 2xl:w-84 h-auto"
						src="<?= $this->url('/hero/sugar-cube.svg') ?>"
						alt="<?= $site->title ?>"
					/>
				</div>
			</div>
		</div>

		<div class="grid gap-4 mt-10 lg:mt-12 md:grid-cols-4" s:if="$page->hasMeta('hero.highlights')">
			<s-template s:foreach="$page->meta('hero.highlights', []) as $heroHighlight">
				<s-hero-card s:bind="['icon' => $heroHighlight['icon'] ?? '']">
					<h2 s:slot="header"><?= $heroHighlight['title'] ?></h2>
					<?= $heroHighlight['description'] ?>
				</s-hero-card>
			</s-template>
		</div>

	</section>

	<s-template s:notempty="$content">
		<article class="card bg-base-100 border border-base-300 shadow-sm">
			<div class="card-body p-6 sm:p-8 lg:p-10">
				<div class="prose prose-invert max-w-none">
					<?= $content |> raw() ?>
				</div>
			</div>
		</article>
	</s-template>
</s-template>

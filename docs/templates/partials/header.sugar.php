<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */

$isHomeTemplate = (($page->meta['template'] ?? null) === 'home');
?>
<header class="sticky top-0 z-30 border-b border-base-300 bg-base-100/90 backdrop-blur">
	<div class="navbar w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 min-h-16">
		<div class="navbar-start gap-2">
			<label for="docs-drawer" class="btn btn-ghost btn-square lg:hidden" aria-label="Open navigation">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="inline-block h-5 w-5 stroke-current" aria-hidden="true">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
				</svg>
			</label>

			<a class="btn btn-ghost px-2 normal-case text-base sm:text-lg lg:hidden" s:if="!$isHomeTemplate" href="<?= $this->url('/') ?>" aria-label="Go to homepage">
				<s-site-brand s:bind="['site' => $site]" />
			</a>

			<a class="btn btn-ghost px-2 normal-case text-base sm:text-lg" s:if="$isHomeTemplate" href="<?= $this->url('/') ?>">
				<s-site-brand s:bind="['site' => $site]" />
			</a>

			<span class="font-semibold text-sm sm:text-base text-base-content/80 hidden md:inline" s:if="!$isHomeTemplate">Documentation</span>
		</div>

		<div class="navbar-end">
			<s-doc-search />

			<label x-data="themeToggle" class="btn btn-ghost btn-sm btn-circle swap swap-rotate" aria-label="Toggle color theme" title="Toggle color theme">
				<input type="checkbox" x-model="isLight" />
				<svg class="swap-on h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true">
					<path d="M12 4.5v1.5M12 18v1.5M6.75 6.75l1.06 1.06M16.19 16.19l1.06 1.06M4.5 12H6M18 12h1.5M6.75 17.25l1.06-1.06M16.19 7.81l1.06-1.06M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
				</svg>
				<svg class="swap-off h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" aria-hidden="true">
					<path d="M21 12.79A9 9 0 1 1 11.21 3c-.15.5-.23 1.02-.23 1.55a7.5 7.5 0 0 0 8.47 7.42c.52 0 1.03-.08 1.55-.18Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>
				</svg>
			</label>

			<a
				class="btn btn-ghost btn-sm"
				href="<?= $site->meta('repository', '#') ?>"
				target="_blank"
				rel="noopener noreferrer"
				aria-label="Sugar on GitHub"
				title="View on GitHub"
			>
				<svg viewBox="0 0 24 24" class="h-5 w-5" aria-hidden="true">
					<path fill="currentColor" d="M12 .296c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.385-1.333-1.754-1.333-1.754-1.09-.744.083-.729.083-.729 1.205.084 1.84 1.237 1.84 1.237 1.07 1.834 2.807 1.304 3.492.997.108-.776.418-1.305.762-1.605-2.665-.303-5.467-1.334-5.467-5.93 0-1.31.468-2.38 1.236-3.22-.123-.303-.536-1.522.117-3.176 0 0 1.008-.323 3.3 1.23a11.49 11.49 0 0 1 3.005-.404c1.02.005 2.047.137 3.005.404 2.29-1.553 3.296-1.23 3.296-1.23.655 1.654.242 2.873.12 3.176.77.84 1.234 1.91 1.234 3.22 0 4.61-2.807 5.624-5.48 5.922.43.37.815 1.102.815 2.222 0 1.606-.014 2.898-.014 3.293 0 .321.216.694.825.576C20.565 22.092 24 17.592 24 12.296c0-6.627-5.373-12-12-12"/>
				</svg>
				<span class="hidden sm:inline">GitHub</span>
			</a>
		</div>
	</div>
</header>

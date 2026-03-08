<?php
/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */
?>
<div class="mt-6 pt-4 border-t border-base-300/80">
	<p class="text-xs font-semibold uppercase tracking-wider text-base-content/50 mb-2">Other Projects</p>
	<div class="space-y-2">
		<a
			class="flex items-center gap-2 rounded-box border border-base-300 bg-base-100 px-2.5 py-2 hover:border-primary/40 hover:bg-base-100/80 transition-colors"
			href="https://josbeir.github.io/glaze/?utm_source=sugar_docs&utm_medium=referral&utm_campaign=other_projects&utm_content=glaze"
			target="_blank"
			rel="noopener noreferrer"
			aria-label="Open Glaze website"
		>
			<img class="h-6 w-6 shrink-0" src="<?= $this->url('/glaze-logo.svg') ?>" alt="Glaze logo" />
			<span class="min-w-0">
				<span class="block text-sm font-medium leading-tight">Glaze</span>
				<span class="block text-xs text-base-content/65 truncate">Static site generator</span>
			</span>
		</a>

		<a
			class="flex items-center gap-2 rounded-box border border-base-300 bg-base-100 px-2.5 py-2 hover:border-primary/40 hover:bg-base-100/80 transition-colors"
			href="https://www.jaspersmet.be/?utm_source=sugar_docs&utm_medium=referral&utm_campaign=other_projects&utm_content=creator_site"
			target="_blank"
			rel="noopener noreferrer"
			aria-label="Open personal website"
		>
			<svg class="h-6 w-6 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" aria-hidden="true">
				<path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18M4.5 7.5h15M4.5 16.5h15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<span class="min-w-0">
				<span class="block text-sm font-medium leading-tight">Jasper Smet</span>
				<span class="block text-xs text-base-content/65 truncate">Creator of Sugar</span>
			</span>
		</a>
	</div>
</div>

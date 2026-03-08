<?php
use function Sugar\Core\Runtime\raw;

/**
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Template\SiteContext $this
 */

$icon = match ($icon ?? '') {
	'djot' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-7 text-primary"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h10l4 4v12H6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16 4v4h4M9 13h6M9 17h4"/></svg>',
	'templating' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-7 text-secondary"><rect x="3" y="4" width="18" height="16" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="m9 9-2.5 3L9 15m6-6 2.5 3L15 15"/></svg>',
	'vite' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-7 text-accent"><path stroke-linecap="round" stroke-linejoin="round" d="M13 3 4 14h6l-1 7 11-13h-7z"/></svg>',
	'control-flow' => sprintf('<img class="size-7" src="%s" alt="Control flow icon" />', $this->url('/icons/control-flow.svg')),
	'inheritance' => sprintf('<img class="size-7" src="%s" alt="Inheritance icon" />', $this->url('/icons/inheritance.svg')),
	'safe-output' => sprintf('<img class="size-7" src="%s" alt="Safe output icon" />', $this->url('/icons/safe-output.svg')),
	'components' => sprintf('<img class="size-7" src="%s" alt="Components icon" />', $this->url('/icons/components.svg')),
	default => '',
};
?>
<div class="card group bg-base-200 border border-base-300 transform-gpu transition-all duration-500 ease-out hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-lg active:-translate-y-0.5 active:border-primary/35 active:shadow-lg">
	<div class="card-body p-5">
		<div class="mb-2 opacity-95 transition-all duration-500 ease-out group-hover:translate-x-0.5 group-hover:opacity-100 group-active:translate-x-0.5 group-active:opacity-100" s:notempty="$icon">
			<?= $icon |> raw() ?>
		</div>
		<h2 class="card-title text-base" s:slot="header">My card header</h2>
		<p class="text-sm text-base-content/75">
			<?= $slot ?>
		</p>
	</div>
</div>

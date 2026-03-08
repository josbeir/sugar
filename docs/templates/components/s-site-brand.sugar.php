<?php
/**
 * @var Glaze\Template\SiteContext $this
 * @var Glaze\Config\SiteConfig $site
 */
?>
<img
	class="h-8 w-auto"
	src="<?= $this->url($site->meta('logo', '/sugar-cube-static.svg')) ?>"
	alt="<?= $site->title ?>"
/>
<span class="font-semibold truncate max-w-[16rem]"><?= $site->title ?></span>

<?php
/**
 * @var Glaze\Config\SiteConfig $site
 * @var Glaze\Content\ContentPage $page
 * @var Glaze\Template\SiteContext $this
 */

$siteTitle = $site->title ?? 'Sugar Templates';
$siteDescription = $site->description ?? '';
$pageTitle = $page->title ?? $siteTitle;
$description = $page->meta('description', $siteDescription);

$canonicalUrl = $this->canonicalUrl();

$imagePath = $page->meta('image', '/hero/sugar-cube-static.svg');
$imageUrl = str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')
	? $imagePath
	: $this->url($imagePath, true);
?>
<meta name="description" content="<?= $description ?>" />
<meta property="og:type" content="website" />
<meta property="og:site_name" content="<?= $siteTitle ?>" />
<meta property="og:title" content="<?= $pageTitle ?>" />
<meta property="og:description" content="<?= $description ?>" />
<meta property="og:url" content="<?= $canonicalUrl ?>" />
<meta property="og:image" content="<?= $imageUrl ?>" />
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:title" content="<?= $pageTitle ?>" />
<meta name="twitter:description" content="<?= $description ?>" />
<meta name="twitter:image" content="<?= $imageUrl ?>" />

<?php
/**
 * @var \Glaze\Content\ContentPage $page
 * @var \Glaze\Config\SiteConfig $site
 * @var \Glaze\Template\SiteContext $this
 */

$isNavigable = static fn(\Glaze\Content\ContentPage $candidate): bool => (bool)($candidate->meta('navigation') ?? true);
$prevPage = $this->previous($isNavigable);
$nextPage = $this->next($isNavigable);
?>
<nav
    class="grid gap-3 grid-cols-2"
    s:class="['only-next' => !$prevPage && $nextPage, 'only-prev' => $prevPage && !$nextPage]"
    s:if="$prevPage || $nextPage"
    aria-label="Page navigation"
>
    <s-doc-nav-card s:if="$prevPage" href="<?= $this->url($prevPage->urlPath) ?>" s:bind="['direction' => 'prev']">
        <?= $prevPage->title ?>
    </s-doc-nav-card>

    <s-doc-nav-card s:if="$nextPage" href="<?= $this->url($nextPage->urlPath) ?>" s:bind="['direction' => 'next']">
        <?= $nextPage->title ?>
    </s-doc-nav-card>
</nav>

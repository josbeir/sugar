<div s:extends="s-base-layout">
    <header s:block="header"><h1><?= $title ?? 'Title' ?></h1></header>
    <main s:block="main"><?= $slot ?></main>
</div>

<div s:extends="s-page">
    <main s:block="main"><?= $slot ?></main>
    <footer s:block="footer">
        <small><?= $copyright ?? '© 2026' ?></small>
    </footer>
</div>

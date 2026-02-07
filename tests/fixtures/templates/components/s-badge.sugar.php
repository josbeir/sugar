<span
    s:class="[
        'badge' => true,
        'badge-primary' => ($variant ?? '') === 'primary',
        'badge-secondary' => ($variant ?? '') === 'secondary',
        'badge-danger' => ($variant ?? '') === 'danger'
    ]">
    <?= $slot ?>
</span>

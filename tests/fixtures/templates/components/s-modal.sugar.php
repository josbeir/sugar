<div class="modal" s:if="$open ?? true">
    <div class="modal-backdrop" @click="<?= $onClose ?? 'close()' ?>"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h2><?= $title ?? 'Modal' ?></h2>
            <button class="modal-close" @click="<?= $onClose ?? 'close()' ?>">&times;</button>
        </div>
        <div class="modal-body">
            <?= $slot ?>
        </div>
        <div class="modal-footer" s:if="isset($footer)">
            <?= $footer ?>
        </div>
    </div>
</div>

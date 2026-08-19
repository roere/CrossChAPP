<?php

declare(strict_types=1);

$picker = $chapterPicker ?? [];
$id = static fn (string $key): string => htmlspecialchars((string) ($picker[$key] ?? ''), ENT_QUOTES, 'UTF-8');
$showAllByDefault = ($picker['showAllByDefault'] ?? false) === true;
?>
<fieldset class="chapter-picker" data-chapter-picker>
    <legend><?= $id('legend') ?><?php if (($picker['optional'] ?? false) === true): ?> <span>optional</span><?php endif; ?></legend>
    <input id="<?= $id('inputId') ?>" name="<?= $id('inputName') ?>" type="hidden"<?= ($picker['required'] ?? false) ? ' required' : '' ?>>
    <div class="chapter-picker-filters">
        <label>Land<select id="<?= $id('countryId') ?>"><option value="">Alle</option><option value="DE">Deutschland</option><option value="AT">Österreich</option></select></label>
        <label>Suche<input id="<?= $id('searchId') ?>" type="search" placeholder="Name, Ort, PLZ oder Region"></label>
        <label>Ort / PLZ<input id="<?= $id('locationId') ?>" type="search" placeholder="Ort oder PLZ"></label>
    </div>
    <button id="<?= $id('clearId') ?>" type="button" class="secondary"><?= $id('clearLabel') ?></button>
    <p id="<?= $id('selectedId') ?>" class="chapter-picker-selected"><?= $id('emptyLabel') ?></p>
    <p class="chapter-picker-guidance" data-chapter-picker-guidance<?= $showAllByDefault ? ' hidden' : '' ?>>Nutze Suche, Land oder Ort / PLZ, um Chapter anzuzeigen.</p>
    <div id="<?= $id('resultsId') ?>" class="chapter-picker-list" role="radiogroup" aria-label="<?= $id('ariaLabel') ?>"<?= $showAllByDefault ? '' : ' hidden' ?>></div>
</fieldset>
<?php unset($chapterPicker, $picker, $id, $showAllByDefault); ?>

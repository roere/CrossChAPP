<?php
$prefix = $datePickerPrefix ?? 'representation';
$inputId = $prefix . '-date';
$errorId = $prefix . '-date-error';
$calendarId = $prefix . '-calendar';
$monthId = $prefix . '-calendar-month';
?>
<div id="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') ?>-picker" class="cross-date-picker" data-date-picker>
    <label for="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>">Datum auswählen</label>
    <div class="cross-date-picker-input">
        <input id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>" data-date-picker-input type="text" inputmode="numeric" autocomplete="off" placeholder="TT.MM.JJJJ" aria-invalid="false" aria-describedby="<?= htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="secondary cross-date-picker-trigger" data-date-picker-trigger aria-label="Kalender öffnen" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?= htmlspecialchars($calendarId, ENT_QUOTES, 'UTF-8') ?>">▦</button>
    </div>
    <p id="<?= htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') ?>" class="field-error" data-date-picker-error role="alert" hidden></p>
    <div id="<?= htmlspecialchars($calendarId, ENT_QUOTES, 'UTF-8') ?>" class="cross-date-picker-calendar" data-date-picker-calendar role="dialog" aria-modal="false" aria-labelledby="<?= htmlspecialchars($monthId, ENT_QUOTES, 'UTF-8') ?>" hidden>
        <div class="cross-date-picker-nav"><button data-date-picker-previous type="button" class="secondary" aria-label="Vorheriger Monat">‹</button><strong id="<?= htmlspecialchars($monthId, ENT_QUOTES, 'UTF-8') ?>" data-date-picker-month aria-live="polite"></strong><button data-date-picker-next type="button" class="secondary" aria-label="Nächster Monat">›</button></div>
        <table role="grid" aria-labelledby="<?= htmlspecialchars($monthId, ENT_QUOTES, 'UTF-8') ?>"><thead><tr><th scope="col">Mo</th><th scope="col">Di</th><th scope="col">Mi</th><th scope="col">Do</th><th scope="col">Fr</th><th scope="col">Sa</th><th scope="col">So</th></tr></thead><tbody></tbody></table>
    </div>
</div>
<?php unset($datePickerPrefix, $prefix, $inputId, $errorId, $calendarId, $monthId); ?>

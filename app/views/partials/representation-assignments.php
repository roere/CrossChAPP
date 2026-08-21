<?php $assignmentRole=$assignmentRole??'requester';$assignmentTitle=$assignmentRole==='requester'?'Gefundene Vertreter':'Angenommene Vertretungen'; ?>
<details class="panel representation-assignments" data-assignment-role="<?= $assignmentRole ?>">
    <summary><span><?= htmlspecialchars($assignmentTitle,ENT_QUOTES,'UTF-8') ?> <span data-assignment-count>(0)</span></span><span aria-hidden="true" class="assignment-chevron">▾</span></summary>
    <div data-assignment-list><p>Vereinbarungen werden geladen …</p></div>
</details>
<dialog class="account-dialog assignment-cancel-dialog" aria-labelledby="assignment-cancel-heading"><div class="account-dialog-card">
    <button type="button" class="dialog-close" data-assignment-cancel-close aria-label="Stornierung abbrechen">×</button><h2 id="assignment-cancel-heading">Vertretung stornieren?</h2>
    <p>Möchtest Du diese vereinbarte Vertretung wirklich stornieren? Der Termin wird anschließend wieder für neue Vertretungen freigegeben.</p>
    <div data-assignment-cancel-message class="message" role="alert"></div><div class="registration-actions"><button type="button" class="danger" data-assignment-cancel-confirm>Stornieren</button><button type="button" class="secondary" data-assignment-cancel-close>Abbrechen</button></div>
</div></dialog>

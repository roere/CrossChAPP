<?php // Included only for the full admin. ?>
<details id="server-load-panel" class="panel misc-panel">
    <summary>Serverlast</summary>
    <div class="misc-content">
        <div class="section-heading"><div><h2>Serverlast</h2><p id="server-load-scope"></p></div>
            <label>Automatisch aktualisieren<select id="server-load-refresh"><option value="1">jede Minute</option><option value="2">alle 2 Minuten</option><option value="5" selected>alle 5 Minuten</option><option value="10">alle 10 Minuten</option><option value="60">alle 60 Minuten</option></select></label>
        </div>
        <p class="page-meta">Das Aktualisierungsintervall gilt gemeinsam für Serverlast und Leistungsmonitor.</p>
        <div id="server-load-server" class="stats performance-stats" aria-label="Gemessene Serverkennzahlen"></div>
        <h3>Web Push</h3><p id="server-load-status" role="status">Wird geladen …</p>
        <div id="server-load-push" class="stats performance-stats" aria-label="Web Push letzte 24 Stunden"></div>
        <h3>Web-Push-Last – letzte 24 Stunden</h3>
        <p>15-Minuten-Summen aus gemessenen Push-Läufen. Verarbeitungszeit enthält auch das Warten auf Push-Dienste; sie ist keine CPU-Auslastung.</p>
        <?php foreach (['attempts'=>'Push-Zustellversuche (Anzahl)','duration'=>'Verarbeitungszeit (ms)'] as $key=>$label): ?>
        <h4><?= $label ?></h4><div class="performance-chart-wrap"><svg id="server-load-<?= $key ?>-chart" viewBox="0 0 720 220" role="img" aria-labelledby="server-load-<?= $key ?>-title">
            <title id="server-load-<?= $key ?>-title"><?= $label ?> – letzte 24 Stunden</title>
            <desc>Gemessene Summen je 15 Minuten.</desc>
            <line x1="42" y1="12" x2="42" y2="190" class="chart-axis"/><line x1="42" y1="190" x2="708" y2="190" class="chart-axis"/>
            <polyline class="performance-line" points=""/><text class="chart-maximum" x="36" y="18" text-anchor="end">0</text>
            <text class="chart-start" x="42" y="212">--:--</text><text class="chart-end" x="708" y="212" text-anchor="end">--:--</text>
        </svg></div>
        <?php endforeach; ?>
        <h3>Web-Push-Verarbeitung</h3><p>Neueste 200 Läufe der letzten 24 Stunden. Technische Metadaten werden 30 Tage aufbewahrt. Erfolgreich bedeutet: vom Push-Dienst angenommen; die Anzeige am Gerät ist nicht messbar.</p>
        <div class="server-load-table table-wrap"><table id="server-load-table"><thead><tr>
            <?php foreach (['started_at_ms'=>'Zeit','duration_ms'=>'Dauer (ms)','candidate_notifications'=>'Benutzer/Gesuch','subscriptions_targeted'=>'Geräte','delivery_attempts'=>'Zustellversuche','success_count'=>'Erfolgreich','failure_count'=>'Fehler','expired_subscription_count'=>'Ungültige Geräte'] as $key=>$label): ?>
            <th data-sort-key="<?= $key ?>" aria-sort="<?= $key==='started_at_ms'?'descending':'none' ?>"><button type="button" class="sort-button"><?= $label ?> <span class="sort-indicator" aria-hidden="true"><?= $key==='started_at_ms'?'▼':'' ?></span></button></th>
            <?php endforeach; ?>
        </tr></thead><tbody id="server-load-runs"></tbody></table></div>
        <p id="server-load-empty" hidden>Noch keine Push-Läufe gemessen.</p>
        <p id="server-load-message" class="message" role="status" aria-live="polite"></p>
    </div>
</details>

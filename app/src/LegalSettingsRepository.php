<?php

declare(strict_types=1);

final class LegalSettingsRepository
{
    public const DEFAULT_IMPRINT = <<<'TEXT'
Impressum

[Bitte vor Veröffentlichung durch die tatsächlichen Anbieterangaben ersetzen.]

Anbieter:
Max Mustermann
Musterstraße 1
12345 Musterstadt

E-Mail: info@example.org

Verantwortlich für den Inhalt:
Max Mustermann
TEXT;

    public const DEFAULT_PRIVACY = <<<'TEXT'
DATENSCHUTZERKLÄRUNG

1. Verantwortlicher
Die konkreten Angaben zum Verantwortlichen müssen vor der Veröffentlichung im Adminbereich ergänzt werden.

2. Zweck der Verarbeitung
CrossChAPP dient dazu, BNI-Chapter zu finden, Vertretungsangebote zu veröffentlichen, Vertretungsgesuche anzulegen, andere Nutzer zu kontaktieren und Benutzerkonten zu verwalten.

3. Verarbeitete Kontodaten
Verarbeitet werden Vorname, Nachname, E-Mail-Adresse, das Passwort ausschließlich als Hash, das Heimatchapter, der Verifikationsstatus sowie gespeicherte Registrierungs- und Loginzeitpunkte.

4. Vertretungsdaten
Verarbeitet werden angebotene Chapter und Termine, Vertretungsgesuche sowie die für Kontaktvorgänge erforderlichen Zuordnungen.

5. Kontaktaufnahme
Bei einer Kontaktanfrage werden die für die Anfrage notwendigen Absenderdaten an den Empfänger übermittelt. Dazu können Name, E-Mail-Adresse, Chapter und Nachricht gehören.

6. BNI-Verzeichnisprüfung
Wenn die Chapter-Prüfung nicht übersprungen wird, werden Vor- und Nachname sowie das ausgewählte Chapter verwendet, um zu prüfen, ob der Name in der öffentlichen BNI-Mitgliederliste des Chapters vorhanden ist. Eine BNI-E-Mail-Adresse wird dabei nicht ausgelesen oder gespeichert. Bei übersprungener Prüfung findet dieser Abgleich nicht statt.

7. Technische Daten
Für Rate-Limits und Missbrauchsschutz werden IP-Adressen beziehungsweise daraus erzeugte Hashwerte, Authentifizierungs- und Rate-Limit-Ereignisse sowie ein technisch notwendiges Session-Cookie verarbeitet. Technische Logs können anfallen, soweit sie für den Betrieb erforderlich sind.

8. E-Mail-Versand
Für E-Mail-Bestätigung, Passwortreset, Einladungen und Vertretungsanfragen werden E-Mail-Adressen und die notwendigen Nachrichtendaten über den konfigurierten SMTP-Dienst verarbeitet.

9. Speicherdauer
Daten werden gespeichert, solange das Konto besteht beziehungsweise soweit sie für Funktion, Sicherheit und gesetzliche Pflichten erforderlich sind. Gelöschte Konten werden nach der bestehenden Account-Löschlogik samt abhängigen Daten entfernt, soweit keine zwingenden Aufbewahrungsgründe bestehen.

10. Cookies und Sessions
CrossChAPP verwendet ein technisch notwendiges Session-Cookie. Es werden keine Werbe- oder Tracking-Cookies eingesetzt.

11. Rechte der betroffenen Person
Betroffene Personen haben nach Maßgabe der gesetzlichen Voraussetzungen Rechte auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Widerspruch und Datenübertragbarkeit sowie ein Beschwerderecht bei einer Aufsichtsbehörde.

12. Hinweis
Diese Datenschutzerklärung ist ein technischer Ausgangstext und ersetzt keine rechtliche Prüfung.
TEXT;

    public function __construct(private readonly PDO $database) {}

    /** @return array{imprintText:string,privacyText:string,updatedAt:string} */
    public function settings(): array
    {
        $row = $this->database->query('SELECT imprint_text,privacy_text,updated_at FROM legal_settings WHERE id=1')->fetch();
        if (!is_array($row)) throw new RuntimeException('Rechtliche Texte fehlen.');
        return ['imprintText'=>(string)$row['imprint_text'],'privacyText'=>(string)$row['privacy_text'],'updatedAt'=>(string)$row['updated_at']];
    }

    public function save(string $imprint, string $privacy): void
    {
        $imprint = trim($imprint); $privacy = trim($privacy);
        if ($imprint === '' || $privacy === '' || strlen($imprint) > 100000 || strlen($privacy) > 100000) throw new InvalidArgumentException('Die rechtlichen Texte sind ungültig.');
        $statement=$this->database->prepare('UPDATE legal_settings SET imprint_text=:imprint,privacy_text=:privacy,updated_at=:updated WHERE id=1');
        $statement->execute([':imprint'=>$imprint,':privacy'=>$privacy,':updated'=>gmdate('Y-m-d\TH:i:s\Z')]);
    }
}

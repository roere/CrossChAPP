<?php

declare(strict_types=1);

final class TextTemplateCatalog
{
    /** @return array<string,array{label:string,description:string,category:string,subjectSupported:bool,allowedPlaceholders:list<string>,storage:string,placeholderName:?string}> */
    public static function definitions(): array
    {
        $assignment = ['requester_first_name','requester_full_name','requester_email','representative_first_name','representative_full_name','representative_email','chapter','requested_date','acceptance_link','cancelled_by','custom_message','app_name','base_url'];
        return [
            'verify_email' => self::mail('E-Mail-Adresse bestätigen', 'E-Mail an einen neu registrierten Anwender. Sie wird nach der Registrierung versendet und enthält den Link zur Bestätigung der E-Mail-Adresse.', 'Registrierung', ['first_name','last_name','email','verification_link','app_name']),
            'reset_password' => self::mail('Passwort zurücksetzen', 'E-Mail an einen Anwender, nachdem ein Passwortreset angefordert wurde. Sie enthält den Link zur Vergabe eines neuen Passworts.', 'Passwort / Konto', ['first_name','last_name','reset_link','app_name']),
            'user_invitation' => self::mail('Anwender einladen', 'Einladungsmail an einen neuen Anwender. Sie wird beim Versand einer Admin-Einladung ausgelöst und enthält den Aktivierungslink sowie das vorgemerkte Heimatchapter.', 'Einladungen', ['first_name','last_name','email','chapter','invitation_link','app_name']),
            'representation_contact' => self::mail('Vertretungsangebot kontaktieren', 'Kontaktmail an den Anbieter eines Vertretungsangebots im bisherigen Kontaktflow. Sie wird auch für die Vorschau der Vertretungsanfrage verwendet.', 'Kontakt', ['provider_first_name','requester_first_name','requester_last_name','requester_full_name','requester_email','requester_chapter','requested_date','custom_message','app_name']),
            'representation_request_contact' => self::mail('Vertretungsgesuch beantworten', 'Kontaktmail an den Ersteller eines Vertretungsgesuchs im bisherigen Kontaktflow. Sie wird auch für die Vorschau der Rückmeldung verwendet.', 'Kontakt', ['request_owner_first_name','contact_first_name','contact_last_name','contact_full_name','contact_email','contact_chapter','requested_chapter','requested_date','custom_message','app_name']),
            'request_contact_acceptance' => self::mail('Annahme eines Vertretungsangebots', 'E-Mail an den Ersteller eines Vertretungsgesuchs, wenn ein anderer Anwender anbietet, die Vertretung zu übernehmen. Enthält den Link zur Annahme des Vertretungsangebots.', 'Vertretungsvereinbarungen', $assignment),
            'offer_contact_acceptance' => self::mail('Annahme eines Vertretungsgesuchs', 'E-Mail an den Anbieter eines Vertretungsangebots, wenn ein Suchender Kontakt aufnimmt. Enthält den Link zur Annahme des Vertretungsgesuchs.', 'Vertretungsvereinbarungen', $assignment),
            'representation_assignment_confirmed_requester' => self::mail('Vereinbarung bestätigt – Suchender', 'Bestätigungsmail an den Suchenden, nachdem eine Vertretung verbindlich vereinbart wurde.', 'Vertretungsvereinbarungen', $assignment),
            'representation_assignment_confirmed_representative' => self::mail('Vereinbarung bestätigt – Vertreter', 'Bestätigungsmail an den Vertreter, nachdem eine Vertretung verbindlich vereinbart wurde.', 'Vertretungsvereinbarungen', $assignment),
            'representation_assignment_cancelled_requester' => self::mail('Stornierung – Suchender', 'Stornomail an den Suchenden, wenn eine bereits vereinbarte Vertretung storniert wurde.', 'Stornierungen', $assignment),
            'representation_assignment_cancelled_representative' => self::mail('Stornierung – Vertreter', 'Stornomail an den Vertreter, wenn eine bereits vereinbarte Vertretung storniert wurde.', 'Stornierungen', $assignment),
            'contact_hint' => self::text('Hinweis zur Vertretungsanfrage', 'Hinweis im Dialog, wenn ein Suchender einen Anbieter unter „Vertreter finden“ kontaktiert.', 'Oberflächentexte'),
            'request_contact_hint' => self::text('Hinweis zum Vertretungsgesuch', 'Hinweis im Dialog, wenn ein Anwender auf ein Vertretungsgesuch antwortet.', 'Oberflächentexte'),
            'offer_custom_message' => self::text('Standardnachricht an Anbieter', 'Vorausgefüllte persönliche Nachricht, wenn ein Suchender ein vorhandenes Vertretungsangebot kontaktiert. Sie wird in der Kontaktmail als {{custom_message}} eingesetzt.', 'Standardnachrichten', '{{custom_message}}'),
            'request_custom_message' => self::text('Standardnachricht an Suchenden', 'Vorausgefüllte persönliche Nachricht, wenn ein Anwender ein Vertretungsgesuch beantworten möchte. Sie wird in der Kontaktmail als {{custom_message}} eingesetzt.', 'Standardnachrichten', '{{custom_message}}'),
        ];
    }

    /** @return array{label:string,description:string,category:string,subjectSupported:bool,allowedPlaceholders:list<string>,storage:string,placeholderName:?string} */
    private static function mail(string $label, string $description, string $category, array $placeholders): array
    {
        return ['label'=>$label,'description'=>$description,'category'=>$category,'subjectSupported'=>true,'allowedPlaceholders'=>$placeholders,'storage'=>'email','placeholderName'=>null];
    }

    /** @return array{label:string,description:string,category:string,subjectSupported:bool,allowedPlaceholders:list<string>,storage:string,placeholderName:?string} */
    private static function text(string $label, string $description, string $category, ?string $placeholderName = null): array
    {
        return ['label'=>$label,'description'=>$description,'category'=>$category,'subjectSupported'=>false,'allowedPlaceholders'=>[],'storage'=>'settings','placeholderName'=>$placeholderName];
    }
}

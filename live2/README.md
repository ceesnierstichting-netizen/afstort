# Nieuw live portaal

Na inloggen opent voor iedereen automatisch `index2.php`. Het keuzescherm vervalt. Ook bestaande links naar `index.php`, `keuze.php` en `driver_mobile.php` verwijzen naar de nieuwe weergave; de API-acties van `index.php` blijven beschikbaar voor beheer vanuit de nieuwe weergave. Het e-mailoverzicht keert terug naar `index2.php`. Upload voor deze omschakeling ook `login.php`, `2fa_verify.php`, `2fa_recovery_codes.php`, `keuze.php`, `index.php`, `driver_mobile.php`, `emailRapport.php` en `beta.php`. Beide portalen gebruiken dezelfde databaseconfiguratie, sessie, accounts en tabel `ritten`. `beta.php` blijft de aparte testomgeving.

## Bestanden op de server

Plaats `index2.php`, de map `live2/`, `rit_concurrency.php`, `chauffeur_selectie.php` en de aangepaste `getNearestChauffeur.php`. De map `live2/` bevat ook `base.css`, `workflow.php`, `source.php` en `mail.php`; upload deze mee. Het nieuwe portaal gebruikt daarnaast de aanwezige `app_helpers.php`, `config.php`, `session.php` en `logohome.png`. Plaats ook de eerder aangepaste `index.php` voor de opslagbescherming en automatische verversing in het oude portaal.

PHP vereist `pdo_mysql`. De bestaande tabel `ritten` moet InnoDB gebruiken; het nieuwe portaal weigert de API bij een andere opslagengine. De databasegebruiker moet de aanvullende tabellen kunnen aanmaken. Bij de eerste API-aanroep worden met `CREATE TABLE IF NOT EXISTS` twee InnoDB-tabellen toegevoegd:

- `rit_live_ui`: conceptfase, versienummer, bonfoto’s en afrondingsopmerking per bestaande rit. Foto’s staan als base64 in deze tabel, niet in openbare bestanden.
- `rit_live_outbox`: inhoud, ontvangers en verzendstatus van mails uit het nieuwe portaal.

De bestaande helpers controleren ook de bestaande auditkolommen en tabellen voor ritaanbiedingen en mailhistorie. Er worden geen bestaande ritten naar een tweede rittenbestand gekopieerd.

## Samenwerking met het oude portaal

Medewerkers kunnen bij een afgeronde rit de gehele kilometers en het gestorte bedrag aanpassen. Elke gewijzigde waarde vraagt afzonderlijk om bevestiging. Bij het aangepaste bedrag staat in kleine letters `Aangepast door [medewerker]`; de naam komt uit het ingelogde account en blijft behouden bij een latere correctie van alleen de kilometers. Correcties gebruiken dezelfde versiecontrole en transactie als andere ritwijzigingen en versturen geen nieuwe afrondingsmail. De API voegt automatisch de kolom `rit_live_ui.amount_adjusted_by` toe; de databasegebruiker moet hiervoor ALTER mogen uitvoeren. Upload hiervoor de gewijzigde `live2/domain.php`, `live2/live.js` en `live2/live.css` samen.

Toewijzingen, datum, tijd, gestort bedrag, gehele kilometers en `Afgehandeld` worden direct in `ritten` opgeslagen. De bestaande waarden voor soort opbrengst blijven behouden. De aanvraagopmerking blijft in `ritten.opmerking`; de aparte afrondingsopmerking, interne opmerking en bonfoto’s zijn in het nieuwe portaal beschikbaar. Chauffeurs en medewerkers staan onder Beheer in twee tabbladen. Admin kan via de bestaande serverroutes accounts toevoegen en verwijderen, 2FA-herstelmails sturen en locaties herberekenen. Mailsjabloonbeheer loopt via het bestaande portaal. Verzendoverzicht opent het bestaande `emailRapport.php` in een nieuw tabblad voor gebruikers met de bestaande rapportrechten. De jaarweergave en kilometervergoeding sluiten aan op de bestaande inrichting: 2026 en € 0,30/km.

Beide overzichten verversen elke 30 seconden en bij terugkeer naar het venster, zolang er niet wordt bewerkt. De nieuwe interface ververst niet met een geopend formulier. Opslag controleert de versie terwijl de rit vergrendeld is in een database-transactie. Wijzigingen vanuit het oude portaal maken een reeds geopend nieuw formulier ongeldig, en omgekeerd. Bij een conflict blijft de eigen invoer in beeld.

Voor bestaande ritten zonder aanvullende fase betekent een gekoppelde chauffeur plus datum en tijd `Gepland`. Dit zegt niets over eerdere mailverzending. Een concept uit het nieuwe portaal blijft een concept wanneer alleen contactgegevens in het oude portaal worden aangepast. Een gewijzigde chauffeur, afspraak of eindstatus in het oude portaal wordt opnieuw afgeleid uit de actuele rit.

## Mail en bonnen

Bij Rit afronden kunnen gebruikers via `Foto maken` de camera op hun telefoon openen, één foto maken en daarna opnieuw een foto toevoegen. `Foto’s kiezen` laat meerdere bestaande foto’s toe. Alle nieuw toegevoegde foto’s blijven in dezelfde lijst en kunnen vóór opslag afzonderlijk worden verwijderd. Het maximum van drie bonfoto’s geldt inclusief eerder opgeslagen foto’s die niet voor verwijderen zijn geselecteerd. Grote camerafoto’s worden in de browser verkleind naar JPG van maximaal 2 MB. De cameraoptie gebruikt de camerafunctie van de browser; op apparaten zonder ondersteuning verschijnt een bestandskeuze. Controle: `node tests/live2_receipt_checks.js`.

Na een succesvolle aanvraagbevestiging selecteert het nieuwe portaal met dezelfde functie als het oude portaal de dichtstbijzijnde chauffeur. Het chauffeursvoorstel wordt verstuurd en bij succes in `rit_aanbiedingen` geregistreerd. De melding na invoer noemt de chauffeur; de ritkaart toont bij een open voorstel `Uitgezet bij` en `Wacht op reactie`. Dit koppelt de rit nog niet definitief aan een chauffeur. Een herhaald aanmaakverzoek behoudt het bestaande voorstel en verstuurt geen tweede voorstel. Bij een selectiefout blijft de rit bewaard en verschijnt een melding met de reden; opnieuw proberen kan via de verzendknop bij de rit.

Mails worden echt verzonden met PHP `mail()`, vanuit `noreply@nierstichtingnederland.nl`. De bestaande sjablonen 1, 3, 4, 5 en 6, onderwerpen, documentlinks en ontvangers worden gebruikt. Afrondingsmails hebben CC naar `collecte@nierstichting.nl` en BCC naar de chauffeur. Bonfoto’s worden als bijlagen meegestuurd; maximaal drie JPG-, PNG- of WebP-bestanden van elk 2 MB. Afspraak en afronding worden pas bevestigd nadat alle betreffende berichten door de mailserver zijn geaccepteerd. Acceptatie bevestigt geen aflevering in de mailbox.

Bij gedeeltelijke mislukking worden al geaccepteerde berichten overgeslagen bij opnieuw proberen. De opgeslagen berichtinhoud blijft tijdens een openstaande verzending vaststaan. Een onderbroken verzending kan een onbekende uitkomst hebben; de status blijft dan `sending` en een beheerder moet de mailserver controleren voordat die status handmatig wordt hersteld. Onzekere berichten worden niet automatisch opnieuw verstuurd. Het verzendoverzicht toont alleen mails vanuit het nieuwe portaal; de bestaande mailhistorie wordt daarnaast bijgewerkt.

## Controle

Uitgevoerde lokale controles:

```text
php -l index2.php
php -l live2/controller.php
php -l live2/domain.php
node --check live2/live.js
php tests/live2_checks.php
node tests/live2_ui_checks.js
php tests/rit_concurrency_checks.php
php tests/regression_checks.php
node tests/completed_trip_checks.js
node tests/confirmation_checks.js
```

De nieuwe servicetests gebruiken een database in het geheugen en een nagebootste mailfunctie. Ze controleren rechten, verouderde versies, concepten, gedeeltelijke mailfouten, onzekere verzending, bijlagen, documentlinks, afgeronde ritten, terugdraaien bij een ontbrekend sjabloon en voorkomen van dubbele ritten. De interfacetests controleren onder meer automatische verversing, behoud van geopende formulieren en behoud van wijzigingen die tijdens een laadverzoek beginnen.

De lokale PHP-installatie heeft geen `pdo_mysql`; de daadwerkelijke MySQL-verbinding, serverconfiguratie, bestandslimieten en aflevering van echte mails zijn hier nog niet getest. Controleer vóór de pilot op de server de volledige ritafhandeling en gelijktijdige wijzigingen vanuit beide interfaces met een gecontroleerde rit en eigen mailadressen.

## Beheer en collectejaar

Beheer bevat Administrators (de daadwerkelijke Full Access-vlag uit chauffeurs) en Instellingen. Het actuele jaar en de kilometervergoeding per jaar worden gedeeld via de tabel afstort_settings. Opslag controleert de instellingenrevision om gelijktijdig overschrijven te weigeren.

Bij het eerste gebruik wordt ritten.collectejaar toegevoegd met standaardwaarde 2026 voor bestaande ritten. Nieuwe ritten in index.php en saveRitten.php krijgen het actuele jaar; index2.php gebruikt het geselecteerde collectejaar. De traditionele weergave toont het actuele jaar; Administrators kunnen in index2.php ook eerdere jaren bekijken. Beide rapporten gebruiken de vergoeding van het betreffende ritjaar, ook bij een vergoeding van nul. Deploy portal_settings.php samen met de gewijzigde PHP-bestanden; de databasegebruiker moet CREATE/ALTER mogen uitvoeren.

E-mailteksten zijn onder Beheer aanpasbaar voor Administrators (Full Access). De vijf bestaande sjablonen in instellingen worden direct gedeeld door beide live portalen. HTML blijft behouden; de voorbeeldweergave gebruikt een iframe zonder sandboxrechten. Bestaande invulvelden moeten behouden blijven. Opslag vergelijkt zowel een inhoudshash als de oorspronkelijke databasewaarde om gelijktijdig overschrijven te weigeren. Reeds klaargezette outboxmails behouden hun oorspronkelijke inhoud. Controle: php tests/live2_template_checks.php.

TinyMCE 8.9.2 staat lokaal in assets/tinymce (GPL-2.0-or-later, zie license.md); upload deze hele map mee. Er is geen cloudaccount of API-sleutel nodig. Vet, cursief, links, lijsten, tabellen en HTML-bronbewerking staan in de werkbalk. De editor laadt bij openen van het tabblad. Ongewijzigde sjablonen behouden hun oorspronkelijke HTML. De CSP staat inline stijlen toe voor de editor, maar scripts blijven beperkt tot eigen bestanden. Documentatie: https://www.tiny.cloud/docs/tinymce/latest/zip-install/ en https://www.tiny.cloud/docs/tinymce/latest/ui-mode-configuration-options/.

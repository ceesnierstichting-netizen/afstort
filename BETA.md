# Afstort bèta testen

Open `afstort/beta.php` nadat je bent ingelogd via het bestaande portaal. De bestaande login kan je eerst naar het gewone overzicht brengen; open daarna opnieuw `beta.php`.

`index.php` en alle bestaande opslag- en mailroutes zijn ongewijzigd door deze uitbreiding. De bèta gebruikt de bestaande ingelogde sessie en rolhelpers. De fictieve oefenomgeving heeft geen databaseverbinding. Kantoor kan daarnaast een **persoonlijke testkopie van echte ritten** laden: alleen lezen uit de bestaande database, uitsluitend opslaan in de aparte bèta-opslag. Geen van beide omgevingen verstuurt echte e-mails.

## Plaatsen

Upload `beta.php` en de volledige map `beta/`, inclusief de verborgen bestanden in `beta/data/`. Maak `beta/data/` schrijfbaar voor de PHP-gebruiker. Geen databasemigratie nodig. PHP 7.4 of hoger is vereist. De omgeving gebruikt dezelfde beveiligde sessie als het huidige portaal; gebruik HTTPS.

De bèta maakt zijn eigen `beta/data/store.php` en lockbestand aan. De inhoud is JSON achter een PHP-blokkade; `.htaccess` blokkeert de hele datamap op Apache. Voor opslag buiten de webroot kun je de serveromgevingsvariabele `AFSTORT_BETA_DATA_DIR` instellen op een bestaande, schrijfbare privémap. Runtimegegevens horen niet in Git en mogen bij een volgende upload niet overschreven worden.

## Echte ritten en bestaande e-mailteksten

1. Kies als kantoor bij **Gegevens** voor **Testkopie van echte ritten**.
2. Klik **Testkopie van echte ritten laden**. De bèta leest de ritten, chauffeursnamen/-adressen en mailsjablonen uit de bestaande database. Er wordt niets gewijzigd in de bron. Bestaande mailhistorie en bonbestanden worden niet gekopieerd.
3. Je ziet het laadmoment bovenaan. Daarna kun je ritten oppakken, plannen, afronden en testmails bekijken binnen deze persoonlijke kopie.
4. Opnieuw laden vervangt jouw eerdere kopie, inclusief testwijzigingen en testmails, na een bevestiging. Er is geen automatische synchronisatie terug naar het huidige portaal. De fictieve oefenomgeving blijft intact.

De verbinding gebruikt dezelfde `AFSTORT_DB_*`-instellingen of hetzelfde bestaande `afstort-db-config.php`-bestand als het portaal. De PHP-extensie `pdo_mysql` moet beschikbaar zijn. Optioneel kun je met `AFSTORT_BETA_DB_USER` en `AFSTORT_BETA_DB_PASSWORD` een afzonderlijk databaseaccount met alleen SELECT-rechten gebruiken. Elke bronraadpleging vindt plaats binnen een MySQL/MariaDB **READ ONLY**-transactie. De bèta roept geen bestaande opslag-, migratie- of mailroutes aan. Bij elke toegang tot de persoonlijke kopie worden de actuele kantoorrechten opnieuw gelezen; gewone chauffeurs hebben geen toegang tot deze echte gegevenskopieën.

Kopieën staan onder `snapshot-<hash van account-id>.php` in dezelfde beschermde datamap. Ze zijn per kantooraccount gescheiden. Laat deze map bij voorkeur via `AFSTORT_BETA_DATA_DIR` buiten de webroot staan.

De mails gebruiken de gekopieerde inhoud van `instellingen.email_template`: **1** voor de aanvraag, **4** voor de afspraakmail aan de contactpersoon, **3** voor de aparte chauffeurmail, **5** voor gebiedsafronding en **6** voor wijkafronding. Onderwerpen, CC en BCC volgen het bestaande portaal. Een ontbrekend sjabloon levert een melding op, geen stilzwijgende voorbeeldtekst. De preview en testmailbox tonen de ingevulde tekst; HTML-opmaak, externe afbeeldingen en actieve documentlinks worden niet uitgevoerd. De bij deze bèta toegevoegde opmerking wordt onderaan de afrondingsmail gezet. Echte aflevering en daadwerkelijke documentlinks zijn niet onderdeel van deze test.

De indeling van geïmporteerde ritten wordt afgeleid van chauffeur, datum/tijd en de bestaande status `Afgehandeld`. **Gepland** betekent hier dat een chauffeur en afspraakgegevens aanwezig zijn; het bewijst niet dat een eerdere bevestigingsmail is verzonden. Chauffeursaanbiedingen worden niet overgenomen. Kantoor ziet de volledige eigen kopie; **Chauffeur (ikzelf)** filtert op beschikbare ritten en de eigen naam.

## Wat je kunt testen

1. Kantoor ziet **Nieuwe testrit** en kan met fictieve gegevens een rit maken. De aanvraagbevestiging verschijnt in **Testmailbox**.
2. Kantoor kan via **Testweergave** overschakelen naar **Chauffeur (ikzelf)**. Gewone chauffeurs krijgen alleen hun eigen ritten en de beschikbare testritten. Dit verandert geen rechten in het huidige portaal.
3. Medewerkers en de admin kiezen **Chauffeur toewijzen**, selecteren een chauffeur en bevestigen met **Rit toewijzen**. Bij fictieve ritten zijn dit testchauffeurs; in de echte gegevenskopie wordt de actuele lijst kiesbare chauffeurs alleen uitgelezen. Ook eerder geladen kopieën krijgen deze lijst zonder hun testwijzigingen te verliezen. Gewone chauffeurs houden **Ik pak deze rit op** en koppelen daarmee uitsluitend hun eigen naam. De rit verhuist naar **Nog te plannen**. Deze stap verstuurt geen mail en een tweede tester kan dezelfde rit niet meer oppakken of toewijzen. De testweergave verandert de rechten van het ingelogde account niet.
4. Vul datum en tijd in. De tijdkeuze gaat per kwartier (:00, :15, :30, :45). Een geïmporteerde afwijkende tijd wordt herkenbaar behouden als bestaande afspraak. **Bewaren en later verder** bewaart een concept; **Afspraak bevestigen en testmails versturen** zet de rit op **Gepland**.
5. Open **Rit afronden** en vul kilometers, werkelijk gestort bedrag aan **MUNTEN** en optioneel bonfoto’s/opmerking in. Maximaal drie JPG-, PNG- of WebP-foto’s van elk 2 MB. Vul alleen het gestorte muntbedrag in; het onbekende bedrag in de sealbag telt niet mee. Zijn er geen munten gestort, vul dan 0 in. Ook hier kun je eerst bewaren.
6. Bekijk de mail en rond af. Controleer de mail en bijlagen in het afgeronde ritdetail en de testmailbox.
7. Vink bij **Testopties** een mailfout aan. De invoer en bonnen blijven bewaard; de rit gaat niet naar de volgende fase. Probeer daarna opnieuw zonder de foutsimulatie. Er horen geen dubbele mails of bijlagen te ontstaan.
8. Vernieuw de pagina of log uit en opnieuw in: opgeslagen invoer blijft bestaan. Niet-opgeslagen formulierwijzigingen geven een waarschuwing bij sluiten of weggaan.

## Grenzen van deze testomgeving

- Gebruik in de gedeelde oefenomgeving uitsluitend fictieve gegevens en testbonnen. De persoonlijke echte gegevenskopie is alleen voor het betreffende kantooraccount toegankelijk.
- Mails worden **nooit echt verzonden**. Fictieve oefenritten gebruiken voorbeeldteksten; de echte gegevenskopie gebruikt de bestaande, meegekopieerde sjablonen.
- Telefoonnummers zijn bewust geen belknoppen, zodat tijdens oefenen niet onbedoeld gebeld wordt.
- Rapportage, declaratie en bestaande automatische chauffeursvoorstellen vallen buiten deze bèta.
- Geen automatische omzetting naar productie. Daarvoor is een afzonderlijke, geteste koppeling met bestaande data en mailverzending nodig.
- De opslag is geschikt voor een beperkte pilot (maximaal 10.000 ritten per omgeving en 12 MB gecodeerde fotodata, circa 9 MB aan foto’s). Bonnen zitten in hetzelfde beschermde bestand; houd de hoeveelheid testmateriaal klein. Een upload die de limiet overschrijdt wordt geweigerd zonder de opgeslagen gegevens te wijzigen.
- Een beheerder kan de bèta leegmaken door, wanneer niemand test, alleen `store.php` uit de ingestelde bèta-datamap te verwijderen. Bij de volgende opening verschijnt weer één voorbeeldrit. Verwijder nooit een databestand uit het huidige portaal.

## Collectejaren, beheer en rapporten

De jaarselectie houdt ritten en de bijbehorende testmailbox gescheiden. Bestaande ritten zonder jaar horen bij collecte 2026. De admin kan via **Beheer** een nieuw collectejaar aanmaken: dat begint leeg, terwijl de voorgaande jaren beschikbaar blijven. Geef bij het aanmaken van ritten eerst het gewenste jaar op in het overzicht. Een collectejaar is onafhankelijk van de afhaaldatum.

**Beheer** is uitsluitend beschikbaar met adminrechten en bewaart de kilometervergoeding per jaar en per testomgeving. De beginwaarde voor 2026 is € 0,30/km, gelijk aan het huidige live-rapport. Wijzigen rekent de rapporten van dat jaar opnieuw uit; een vergoeding voor 2027 wijzigt die van 2026 niet.

**Rapport** toont de afgeronde ritten van het geselecteerde jaar, met gestort bedrag aan munten, kilometers en declaratie, en totalen per chauffeur. Full Access ziet in de kantoorweergave alle chauffeurs; andere gebruikers en de chauffeurtestweergave zien uitsluitend hun eigen afgeronde ritten. Bij een nieuw geïmporteerde testkopie wordt ook het IBAN voor het rapport gelezen. Oude testkopieën bevatten dit nog niet. Het rapport kan via **Afdrukken / PDF** worden afgedrukt of opgeslagen.

De bron heeft nog geen collectejaar. Daarom hoort de import van echte ritten expliciet bij **2026**; de importknop is alleen in dat jaar beschikbaar. Opnieuw importeren vervangt de testwijzigingen van 2026, maar behoudt aangemaakte andere collectejaren, hun ritten, bonnen, testmails en vergoedingen. Voor gebruik van collectejaren op de live site is nog een afzonderlijke databasemigratie en aanpassing van de bestaande routes nodig.

**Beheer** bevat ook aparte lijsten voor chauffeurs en medewerkers. Alleen Admin kan gebruikers toevoegen, bewerken en actief/inactief maken. Voor chauffeurs kunnen postcode en IBAN worden ingevuld. Actieve chauffeurs komen in de toewijslijst; medewerkers en inactieve chauffeurs niet. Bestaande ritten en rapporten blijven bij inactief maken behouden. De rollen geven testgebruikers nog geen echte login: de bèta verstuurt geen uitnodiging en wijzigt geen echte gebruikersaccounts.

In de oefenomgeving begin je met de fictieve chauffeurs Anna en Bram. Bij de testkopie worden de bestaande chauffeurs en medewerkers gelezen; admins zijn uitgesloten van deze bewerklijst. Bewerkingen worden als aparte bèta-gegevens opgeslagen en blijven bij verversen en opnieuw importeren behouden. Deze accountlijsten gelden voor alle collectejaren binnen de gekozen testomgeving.

Upload bij deze uitbreiding `beta.php`, `beta/beta.js`, `beta/beta.css`, `beta/domain.php` en `beta/source.php`. De versieparameter voor JS en CSS voorkomt dat oude bestanden in de browsercache blijven hangen.

## Technische controles

```text
php -l beta.php
php -l beta/domain.php
php -l beta/storage.php
php -l beta/source.php
php -l beta/mail.php
node --check beta/beta.js
node tests/beta_ui_checks.js
php tests/beta_checks.php
php tests/beta_snapshot_checks.php
php tests/beta_account_checks.php
php tests/regression_checks.php
```

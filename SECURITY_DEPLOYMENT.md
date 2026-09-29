# Beveiligingsuitrol

1. Roteer het wachtwoord van de bestaande databasegebruiker in het hostingpaneel. Het oude wachtwoord stond in de Git-geschiedenis; behandel het als gelekt. Controleer de toegangsrechten van de repository en de serverback-ups. Trek toegang van onbekende accounts in en bekijk de database- en hostinglogs op ongebruikelijke toegang.
2. De Strato-hosting van dit project geeft Apache `SetEnv`-waarden niet door aan PHP. Bewaar de databasegegevens daarom in `afstort-db-config.php` in de bovenliggende WebFTP-map van `afstort`, buiten de Git-repository. De bestaande `.htaccess` in die map moet directe toegang tot dit bestand weigeren. Controleer dat de URL `/afstort-db-config.php` HTTP 403 geeft. Het bestand retourneert een PHP-array:

   ```php
   <?php
   return [
       'host' => 'databasehost-uit-strato',
       'database' => 'databasenaam-uit-strato',
       'user' => 'databasegebruiker-uit-strato',
       'password' => 'nieuw-geroteerd-wachtwoord',
   ];
   ```

   Stel eerst het nieuwe databasewachtwoord in Strato in en vervang daarna de tijdelijke wachtwoordwaarde in dit serverbestand. Deel het wachtwoord niet via chat of Git. `config.php` kan ook de omgevingsvariabelen `AFSTORT_DB_HOST`, `AFSTORT_DB_NAME`, `AFSTORT_DB_USER` en `AFSTORT_DB_PASSWORD` lezen op andere hosting. Een ontbrekende of foutieve configuratie geeft HTTP 503.
3. Controleer dat `hash_migratie.php`, `add_user.php` en `emailtest.php` ook fysiek van de webserver zijn verwijderd. Een deploy die alleen bestaande bestanden overschrijft, laat oude scripts mogelijk staan.
4. Verifieer dat HTTPS op alle app-routes actief is. De sessiecookie gebruikt `Secure`, `HttpOnly` en `SameSite=Lax`.
5. Het afwijzen van een aangeboden rit vereist nu een login en een bevestiging. Een chauffeur die een link uit e-mail opent, moet ingelogd zijn met het account waaraan de rit is aangeboden. Printpagina's voor ritten vereisen ook een login; externe contactpersonen kunnen de oude publieke links niet meer openen.
6. De nieuwe tabel `auth_failed_attempts` wordt bij de eerste inlogpoging aangemaakt. Controleer dat de databasegebruiker hiervoor rechten heeft of maak de tabel vooraf aan op basis van `auth_rate_limit.php`.

Het verwijderen van het wachtwoord uit de huidige versie verwijdert het niet uit eerdere Git-commits of eventuele clones. Geschiedenis herschrijven kan bestaande clones en deploys verstoren en vereist een apart uitrolplan; de wachtwoordrotatie is de directe maatregel.

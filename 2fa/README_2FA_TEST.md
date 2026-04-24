# Afstort 2FA-testmap

Deze map is een aparte testvariant van de bestaande app. De root-app blijft daardoor werken zoals hij nu werkt.

De testmap gebruikt een eigen PHP-sessienaam (`AFSTORT2FASESSID`), zodat een login in de huidige map niet automatisch geldig is in de 2FA-map.

## Installatie op Strato

1. Upload de map `2fa` naast de huidige map op de server.
2. Voer eenmalig `database_2fa_migratie.sql` uit op dezelfde database.
3. Open de testmap in de browser, bijvoorbeeld `https://jouwdomein.nl/2fa/login.php`.
4. Log in met een bestaand account.
5. De eerste keer wordt 2FA ingesteld met een QR-code. Daarna vraagt de testmap om een authenticator-code.

## Belangrijk

De bestaande app gebruikt dezelfde database maar kijkt niet naar de 2FA-velden. Tijdens de testperiode blijft de oude map dus nog met alleen wachtwoord werken. Zodra de test goed is, hernoem je de mappen zodat alleen de 2FA-versie publiek gebruikt wordt.

Herstelcodes worden gehasht opgeslagen. De authenticator-secret staat in de database omdat TOTP die nodig heeft om codes te controleren.

De QR-code wordt lokaal door PHP gegenereerd. De 2FA-secret wordt dus niet naar een externe QR-dienst gestuurd.

Als een testgebruiker opnieuw moet beginnen met 2FA, kun je dit in de database doen:

```sql
UPDATE chauffeurs
SET twofa_secret = NULL,
    twofa_enabled = 0,
    twofa_recovery_codes = NULL,
    twofa_confirmed_at = NULL,
    twofa_last_used_step = NULL
WHERE email = 'gebruiker@example.nl';
```

Let op: sommige e-mailteksten in de app verwijzen nog naar `/afstort`. Dat is handig na de uiteindelijke mapwissel, maar tijdens testen kunnen zulke links dus nog naar de huidige app wijzen.

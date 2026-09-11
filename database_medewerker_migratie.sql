-- Eenmalig uitvoeren voordat de gewijzigde PHP-bestanden in gebruik worden genomen.
ALTER TABLE chauffeurs ADD COLUMN is_medewerker TINYINT(1) NOT NULL DEFAULT 0;

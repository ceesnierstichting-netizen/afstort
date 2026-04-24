ALTER TABLE chauffeurs
  ADD COLUMN twofa_secret VARCHAR(64) NULL,
  ADD COLUMN twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN twofa_recovery_codes TEXT NULL,
  ADD COLUMN twofa_confirmed_at DATETIME NULL,
  ADD COLUMN twofa_last_used_step BIGINT UNSIGNED NULL;

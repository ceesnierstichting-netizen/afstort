CREATE TABLE IF NOT EXISTS rit_aanbiedingen (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rit_id INT NOT NULL,
    chauffeur_naam VARCHAR(255) NOT NULL,
    chauffeur_email VARCHAR(255) DEFAULT NULL,
    afstand_km DECIMAL(10,2) DEFAULT NULL,
    status ENUM('aangeboden', 'afgewezen') NOT NULL DEFAULT 'aangeboden',
    aangeboden_op DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    afgewezen_op DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uniq_rit_chauffeur (rit_id, chauffeur_naam),
    KEY idx_rit_status (rit_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

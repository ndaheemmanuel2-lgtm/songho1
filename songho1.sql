CREATE DATABASE IF NOT EXISTS songho_db CHARACTER SET utf8 COLLATE utf8_general_ci;
USE songho_db;

DROP TABLE IF EXISTS parties;

CREATE TABLE parties (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    code         VARCHAR(10) UNIQUE NOT NULL,
    etat         ENUM('attente','en_cours','termine') DEFAULT 'attente',
    tour         ENUM('Nord','Sud') DEFAULT 'Sud',
    cases_nord   TEXT NOT NULL,
    cases_sud    TEXT NOT NULL,
    score_nord   INT DEFAULT 0,
    score_sud    INT DEFAULT 0,
    dernier_coup TEXT DEFAULT '',
    historique   TEXT DEFAULT '[]',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
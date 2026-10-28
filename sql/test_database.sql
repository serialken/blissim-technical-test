-- =====================================================================
-- Base dédiée aux tests d'intégration (tests/Repository).
-- Exécuté en root par l'image MySQL au premier démarrage du conteneur.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS blissim_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON blissim_test.* TO 'blissim'@'%';

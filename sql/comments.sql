-- =====================================================================
-- Blissim — Test technique, partie PHP
-- Table des commentaires de l'application catalogue
-- SGBD : MySQL 8 
-- =====================================================================

SET NAMES utf8mb4;

DROP TABLE IF EXISTS comments;

CREATE TABLE comments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Identifiant du produit dans l'API externe (fakestoreapi / dummyjson).
    -- Pas de clé étrangère : les produits ne sont pas stockés dans notre base.
    product_id  INT UNSIGNED NOT NULL,
    author      VARCHAR(100) NOT NULL,
    content     TEXT         NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT pk_comments             PRIMARY KEY (id),
    CONSTRAINT chk_comments_product    CHECK (product_id > 0),
    CONSTRAINT chk_comments_author     CHECK (CHAR_LENGTH(TRIM(author)) > 0),
    CONSTRAINT chk_comments_content    CHECK (CHAR_LENGTH(TRIM(content)) BETWEEN 1 AND 2000),
    -- Affichage des commentaires d'un produit, du plus récent au plus ancien
    INDEX idx_comments_product_created (product_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Quelques commentaires de démonstration (produits 1 et 2 de l'API)
-- ---------------------------------------------------------------------

INSERT INTO comments (product_id, author, content, created_at) VALUES
    (1, 'Camille', 'Très bonne qualité, conforme à la description.', NOW() - INTERVAL 2 DAY),
    (1, 'Léa',     'Livraison rapide, je recommande.',               NOW() - INTERVAL 1 DAY),
    (2, 'Sarah',   'Bon rapport qualité-prix.',                      NOW() - INTERVAL 3 HOUR);
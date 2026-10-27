-- =====================================================================
-- Blissim — Test technique, partie SQL
-- SGBD : MySQL 8 
-- Contenu : schéma e-commerce, jeu de données de test, 2 requêtes
-- =====================================================================

SET NAMES utf8mb4;

-- Suppression dans l'ordre inverse des dépendances (fichier rejouable)
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS customers;

-- ---------------------------------------------------------------------
-- Schéma
-- ---------------------------------------------------------------------

CREATE TABLE customers (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    first_name  VARCHAR(100) NOT NULL,
    last_name   VARCHAR(100) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_customers       PRIMARY KEY (id),
    CONSTRAINT uq_customers_email UNIQUE (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255)  NOT NULL,
    price       DECIMAL(10,2) NOT NULL,  -- prix actuel ; DECIMAL et non FLOAT pour l'argent
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_products       PRIMARY KEY (id),
    CONSTRAINT chk_products_price CHECK (price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id  INT UNSIGNED NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
    ordered_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_orders          PRIMARY KEY (id),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id)
        REFERENCES customers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_orders_status  CHECK (status IN ('pending', 'paid', 'shipped', 'cancelled')),
    INDEX idx_orders_customer   (customer_id),
    INDEX idx_orders_ordered_at (ordered_at)   -- utilisé par le filtre « 7 derniers jours »
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    order_id    INT UNSIGNED  NOT NULL,
    product_id  INT UNSIGNED  NOT NULL,
    quantity    INT UNSIGNED  NOT NULL,
    -- Prix unitaire figé au moment de l'achat : le prix du produit peut
    -- évoluer ensuite sans modifier l'historique des commandes.
    unit_price  DECIMAL(10,2) NOT NULL,
    CONSTRAINT pk_order_items            PRIMARY KEY (id),
    CONSTRAINT fk_order_items_order      FOREIGN KEY (order_id)
        REFERENCES orders (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_order_items_product    FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_order_items_quantity  CHECK (quantity > 0),
    CONSTRAINT chk_order_items_price     CHECK (unit_price >= 0),
    CONSTRAINT uq_order_items_order_prod UNIQUE (order_id, product_id),  -- un produit = une ligne par commande
    INDEX idx_order_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Jeu de données de test
-- Dates relatives à NOW() pour que la requête 2 reste valable quel que
-- soit le jour où le fichier est chargé.
-- ---------------------------------------------------------------------

INSERT INTO customers (id, first_name, last_name, email) VALUES
    (1, 'Camille', 'Martin',  'camille.martin@testmail.com'),
    (2, 'Léa',     'Bernard', 'lea.bernard@testmail.com'),
    (3, 'Sarah',   'Dubois',  'sarah.dubois@testmail.com'),
    (4, 'Inès',    'Thomas',  'ines.thomas@testmail.com'),
    (5, 'Julie',   'Robert',  'julie.robert@testmail.com'),
    (6, 'Manon',   'Petit',   'manon.petit@testmail.com');

INSERT INTO products (id, name, price) VALUES
    (1, 'Box beauté mensuelle',   18.90),
    (2, 'Sérum hydratant',        32.00),
    (3, 'Crème visage bio',       24.50),
    (4, 'Mascara volume',         15.90),
    (5, 'Huile démaquillante',    19.00),
    (6, 'Calendrier de l''Avent', 69.99);

INSERT INTO orders (id, customer_id, status, ordered_at) VALUES
    (1, 1, 'paid',      NOW() - INTERVAL 1 DAY),
    (2, 2, 'shipped',   NOW() - INTERVAL 2 DAY),
    (3, 3, 'paid',      NOW() - INTERVAL 3 DAY),
    (4, 1, 'cancelled', NOW() - INTERVAL 4 DAY),   -- annulée : exclue de la requête 2
    (5, 4, 'shipped',   NOW() - INTERVAL 6 DAY),
    (6, 5, 'shipped',   NOW() - INTERVAL 10 DAY),  -- hors fenêtre de 7 jours
    (7, 2, 'shipped',   NOW() - INTERVAL 20 DAY),
    (8, 6, 'shipped',   NOW() - INTERVAL 30 DAY),
    (9, 3, 'pending',   NOW() - INTERVAL 5 DAY);

INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES
    (1, 1, 2, 18.90),
    (1, 2, 1, 32.00),
    (2, 1, 1, 18.90),
    (2, 4, 2, 15.90),
    (3, 3, 1, 24.50),
    (3, 6, 1, 69.99),
    (4, 2, 3, 32.00),
    (5, 4, 1, 15.90),
    (5, 5, 2, 19.00),
    (6, 1, 1, 17.90),   -- ancien prix de la box : le prix a évolué depuis
    (6, 3, 2, 24.50),
    (7, 5, 1, 19.00),
    (7, 1, 3, 17.90),   -- ancien prix
    (8, 6, 1, 59.99),   -- ancien prix promotionnel
    (9, 2, 1, 32.00);

-- ---------------------------------------------------------------------
-- Requête 1 : prénom et nom des clients ayant commandé le produit 1
-- DISTINCT : un client qui a commandé le produit plusieurs fois
-- n'apparaît qu'une fois.
-- Résultat attendu : Camille Martin, Léa Bernard, Julie Robert
-- ---------------------------------------------------------------------

SELECT DISTINCT c.first_name, c.last_name
FROM customers c
JOIN orders o       ON o.customer_id = c.id
JOIN order_items oi ON oi.order_id   = o.id
WHERE oi.product_id = 1
ORDER BY c.last_name, c.first_name;

-- ---------------------------------------------------------------------
-- Requête 2 : produits vendus au cours des 7 derniers jours, avec la
-- somme des quantités vendues, triés par quantité décroissante.
-- Choix : les commandes annulées ne comptent pas comme des ventes.
-- Résultat attendu :
--   Box beauté mensuelle 3 · Mascara volume 3 · Huile démaquillante 2 ·
--   Sérum hydratant 2 · Calendrier de l'Avent 1 · Crème visage bio 1
-- ---------------------------------------------------------------------

SELECT p.id, p.name, SUM(oi.quantity) AS total_quantity
FROM order_items oi
JOIN orders o   ON o.id = oi.order_id
JOIN products p ON p.id = oi.product_id
WHERE o.ordered_at >= NOW() - INTERVAL 7 DAY
  AND o.status <> 'cancelled'
GROUP BY p.id, p.name
ORDER BY total_quantity DESC, p.name ASC;
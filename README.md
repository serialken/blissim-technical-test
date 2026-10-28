# Blissim — Test technique

- **Partie SQL** : schéma e-commerce, jeu de données et deux requêtes, dans [`blissim.sql`](blissim.sql).
- **Partie PHP** : catalogue alimenté par une API externe ([FakeStoreAPI](https://fakestoreapi.com)), avec la gestion complète des commentaires (ajout, modification, suppression), stockés via PDO.

**Stack** : PHP 8.5 · Symfony 8.1 · MySQL 8.4 · Twig · PHPUnit 13 · Docker

## Sommaire

1. [Prérequis](#1-prérequis)
2. [Installation et lancement](#2-installation-et-lancement)
3. [Base de données](#3-base-de-données)
4. [Partie SQL](#4-partie-sql)
5. [Partie PHP](#5-partie-php)
6. [Choix techniques](#6-choix-techniques)
7. [Tests](#7-tests)
8. [Limites connues et suite](#8-limites-connues-et-suite)

---

## 1. Prérequis

| Mode | Prérequis |
|---|---|
| **Docker (recommandé)** | Git, Docker et Docker Compose v2. Les ports **8080** et **3307** doivent être libres. |
| **Sans Docker** | Git, PHP ≥ 8.4 avec les extensions `pdo_mysql`, `ctype` et `iconv`, Composer 2, MySQL ≥ 8.0.16 (pour l'application des contraintes `CHECK`), et éventuellement la [CLI Symfony](https://symfony.com/download). |

L'application a besoin d'un accès Internet pour joindre l'API produits.

## 2. Installation et lancement

### Cloner le dépôt

```bash
git clone https://github.com/serialken/blissim-technical-test.git
cd blissim-technical-test
```

### Avec Docker

```bash
docker compose up -d --build
```

- L'application est disponible sur **http://localhost:8080**.
- Au premier démarrage, MySQL charge automatiquement, dans l'ordre :
  1. `blissim.sql` : la partie SQL ;
  2. `sql/comments.sql` : la table des commentaires, avec 3 commentaires de démonstration sur les produits 1 et 2 ;
  3. `sql/test_database.sql` : la base des tests d'intégration.
- L'application attend que MySQL soit prêt avant de démarrer.

Pour arrêter : `docker compose down`. Pour repartir d'une base vierge : `docker compose down -v`, puis relancer.

### Sans Docker

```bash
composer install
```

1. Créer la base `blissim` et un utilisateur ayant les droits dessus.
2. Charger les scripts :
   ```bash
   mysql -u<user> -p blissim < blissim.sql
   mysql -u<user> -p blissim < sql/comments.sql
   ```
3. Créer un fichier `.env.local` avec vos valeurs `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` et `DB_PASSWORD`.
4. Lancer le serveur : `symfony serve`, ou `php -S 127.0.0.1:8000 -t public`.

**Variante hybride** : lancer seulement la base avec `docker compose up -d database`, et l'application avec `symfony serve`. Les valeurs par défaut de `.env` (`127.0.0.1:3307`) pointent déjà sur ce conteneur : il n'y a rien à configurer.

## 3. Base de données

| | Valeur |
|---|---|
| Base | `blissim` (et `blissim_test` pour les tests) |
| Utilisateur | `blissim` |
| Mot de passe | `blissim` |
| Utilisateur root | `root` / `root` |
| Hôte et port depuis la machine | `127.0.0.1:3307` |
| Hôte et port depuis le conteneur `app` | `database:3306` |

Connexion avec un client MySQL depuis la machine :

```bash
mysql -h127.0.0.1 -P3307 -ublissim -pblissim blissim
```

Le port **3307** est exposé côté machine pour éviter un conflit avec un MySQL local sur 3306.

Ces identifiants sont des valeurs de développement : ils sont volontairement présents dans `.env` et `docker-compose.yml`. En production, ils seraient injectés par l'environnement ou par les secrets Symfony.

Variables d'environnement :

| Variable | Rôle | Défaut (`.env`) |
|---|---|---|
| `DB_HOST`, `DB_PORT` | Serveur MySQL | `127.0.0.1`, `3307` |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Base et identifiants | `blissim` |
| `PRODUCTS_API_URL` | URL de base de l'API produits | `https://fakestoreapi.com` |

Les variables définies dans `docker-compose.yml` l'emportent sur `.env` dans le conteneur.

---

## 4. Partie SQL

`blissim.sql` contient, dans l'ordre :
1. le schéma ;
2. le jeu de données ;
3. les deux requêtes.

Le script est **rejouable** : les tables sont supprimées puis recréées dans l'ordre des dépendances.

### Charger `blissim.sql`

Avec Docker, le fichier est chargé automatiquement au premier démarrage. Pour le recharger, ce qui exécute aussi les deux requêtes et affiche leurs résultats :

```bash
docker compose exec -T database mysql --default-character-set=utf8mb4 -ublissim -pblissim blissim < blissim.sql
```

Sans Docker :

```bash
mysql --default-character-set=utf8mb4 -u<user> -p blissim < blissim.sql
```

### Lancer les deux requêtes

Les requêtes sont à la fin de `blissim.sql`. Pour les lancer seules, sur une base déjà chargée :

```bash
docker compose exec -T database mysql --default-character-set=utf8mb4 -ublissim -pblissim blissim <<'SQL'
-- Requête 1 : clients ayant commandé le produit 1
SELECT DISTINCT c.first_name, c.last_name
FROM customers c
JOIN orders o       ON o.customer_id = c.id
JOIN order_items oi ON oi.order_id   = o.id
WHERE oi.product_id = 1
ORDER BY c.last_name, c.first_name;

-- Requête 2 : produits vendus sur les 7 derniers jours, par quantité décroissante
SELECT p.id, p.name, SUM(oi.quantity) AS total_quantity
FROM order_items oi
JOIN orders o   ON o.id = oi.order_id
JOIN products p ON p.id = oi.product_id
WHERE o.ordered_at >= NOW() - INTERVAL 7 DAY
  AND o.status <> 'cancelled'
GROUP BY p.id, p.name
ORDER BY total_quantity DESC, p.name ASC;
SQL
```

Vous pouvez aussi copier les requêtes dans un client MySQL connecté sur `127.0.0.1:3307`.

### Résultats attendus

**Requête 1.** `DISTINCT` évite les doublons quand un client a commandé le produit plusieurs fois.

| first_name | last_name |
|---|---|
| Léa | Bernard |
| Camille | Martin |
| Julie | Robert |

**Requête 2.** Les commandes annulées sont exclues (voir [Choix techniques](#commandes-annulées)). En cas d'égalité, le tri par nom garantit un résultat stable.

| id | name | total_quantity |
|---|---|---|
| 1 | Box beauté mensuelle | 3 |
| 4 | Mascara volume | 3 |
| 5 | Huile démaquillante | 2 |
| 2 | Sérum hydratant | 2 |
| 6 | Calendrier de l'Avent | 1 |
| 3 | Crème visage bio | 1 |

Les dates du jeu de données sont relatives à `NOW()` : ces résultats sont les mêmes quel que soit le jour d'exécution.

### Modèle

| Table | Contraintes principales |
|---|---|
| `customers` | `email` unique |
| `products` | `price DECIMAL(10,2)`, `CHECK (price >= 0)` |
| `orders` | FK vers `customers` (`RESTRICT`), `status` parmi `pending`, `paid`, `shipped`, `cancelled` ; index sur `customer_id` et `ordered_at` |
| `order_items` | FK vers `orders` (`CASCADE`) et `products` (`RESTRICT`), `quantity > 0`, `unit_price >= 0`, `UNIQUE (order_id, product_id)` ; index sur `product_id` |

---

## 5. Partie PHP

### Routes

| | Route |
|---|---|
| Catalogue | `GET /` |
| Fiche produit et commentaires | `GET /products/{id}` |
| Ajout | `POST /products/{productId}/comments` |
| Modification | `GET\|POST /products/{productId}/comments/{id}/edit` |
| Suppression | `POST /products/{productId}/comments/{id}/delete` |

### Architecture

```
src/
  Controller/       ProductController, CommentController (minces : orchestration uniquement)
  Service/          ProductApiClient : accès à l'API, résilience, cache
  Repository/       CommentRepository : PDO, requêtes préparées
  Dto/              CommentInput : entrée du formulaire et contraintes de validation
  Model/            Product, Comment : objets readonly
  Exception/        ProductApiException : erreur métier unique pour l'API
  EventListener/    ServiceUnavailableListener : dépendance indisponible → 503
templates/          Twig + Bootstrap 5 (CDN)
sql/                comments.sql (table des commentaires), test_database.sql
tests/              Service/ (unitaires), Repository/ (intégration)
```

---

## 6. Choix techniques

### Symfony + PDO

**Pourquoi Symfony :**
- C'est le framework de la stack Blissim.
- Il apporte sans code maison ce qu'un vrai projet exige : routing, injection de dépendances, Twig avec échappement automatique, protection CSRF, validation, client HTTP et cache.
- Le code métier reste dans des classes simples et testables.

**Pourquoi PDO plutôt que Doctrine :**
- L'énoncé impose PDO.
- Pour une table et quatre requêtes, un ORM ajouterait du poids sans bénéfice.

**La mise en œuvre :**
- PDO est déclaré comme **service** dans `config/services.yaml` et injecté dans `CommentRepository`, qui centralise tout le SQL.
- Trois options sont activées :
  - `ERRMODE_EXCEPTION` : aucune erreur SQL silencieuse ;
  - `FETCH_ASSOC` ;
  - `EMULATE_PREPARES=false` : les requêtes sont réellement préparées par MySQL.
- **Requêtes préparées partout**, avec des paramètres typés : aucune injection SQL possible.
- **Chaque accès à un commentaire passe par le couple (`id`, `product_id`)** : modifier l'URL ne permet pas d'agir sur le commentaire d'un autre produit.
- `comments.product_id` n'a pas de clé étrangère, car les produits vivent dans l'API et non dans notre base.

**Validation :** elle se fait sur un DTO (`CommentInput`) avec Symfony Validator, plutôt qu'avec le composant Form, jugé disproportionné pour deux champs.
- Les limites sont alignées sur le schéma : 100 caractères pour l'auteur, 2000 pour le commentaire.
- Les contraintes `CHECK` SQL restent le dernier garde-fou.

**Sécurité :**
- jeton CSRF sur chaque formulaire, avec un jeton propre à chaque suppression ;
- écritures uniquement en POST, suivies d'une redirection (Post/Redirect/Get) ;
- échappement Twig actif par défaut.

### MySQL

- **C'est le SGBD de la stack Blissim.** La version 8.0.16 minimum applique réellement les contraintes `CHECK`.
- **InnoDB** pour les transactions et les clés étrangères, **utf8mb4** pour gérer tous les caractères.
- **Les montants sont en `DECIMAL(10,2)`.**
- **`order_items.unit_price` fige le prix au moment de l'achat** : l'historique des commandes reste juste quand le prix catalogue change. Le jeu de données contient des lignes à un ancien prix pour le montrer.
- **L'intégrité est portée par la base**, et pas seulement par le code applicatif :
  - FK `CASCADE` des commandes vers leurs lignes ;
  - FK `RESTRICT` vers les clients et les produits, pour ne pas perdre d'historique ;
  - contraintes `CHECK` et `UNIQUE`.
- **Index** choisis pour les jointures et le filtre temporel de la requête 2.

### Gestion des erreurs

**Le client API traite l'API comme une dépendance non fiable :**
- `timeout` de 5 s et `max_duration` de 10 s : une API lente ne bloque pas la page ;
- le code HTTP est contrôlé, le JSON décodé en mode strict (`JSON_THROW_ON_ERROR`) et chaque produit validé à l'hydratation ;
- toutes les erreurs (transport, statut, contenu) sont converties en une seule **`ProductApiException`**, ce qui découple l'appelant de l'implémentation HTTP ;
- un produit inconnu renvoie `null`, puis une 404. FakeStoreAPI répond 200 avec un corps vide dans ce cas, ce qui est géré ;
- un **cache de 5 minutes** limite les appels. Une erreur n'est jamais mise en cache.

**Les erreurs sont centralisées** dans `ServiceUnavailableListener`, plutôt que dans des `try/catch` dans chaque contrôleur :

| Situation | Réponse |
|---|---|
| API ou base indisponible | **503**, page lisible ; le détail technique est écrit dans les logs, jamais montré à l'utilisateur |
| Produit ou commentaire introuvable | **404** |
| Jeton CSRF invalide | **403** |
| Saisie invalide | **422**, formulaire réaffiché avec les valeurs et les messages d'erreur |

### Commandes annulées

La requête 2 **exclut les commandes au statut `cancelled`**. Une commande annulée n'est pas une vente : la compter fausserait les quantités vendues, et donc les décisions de réassort ou de mise en avant.

Les autres statuts (`pending`, `paid`, `shipped`) sont comptés : ils correspondent à une intention d'achat confirmée.

Le jeu de données contient une commande annulée récente pour vérifier cette exclusion. Si le métier voulait ne compter que les commandes payées, il suffirait de remplacer le filtre par `o.status IN ('paid', 'shipped')`.

### Docker

- L'application démarre une fois le `healthcheck` MySQL au vert, et le schéma est provisionné au premier démarrage.
- OPcache est intégré à PHP depuis la 8.5 : il n'est donc pas installé comme extension.

---

## 7. Tests

```bash
vendor/bin/phpunit
```

Ils se lancent depuis la machine, après `composer install`, avec la base démarrée (`docker compose up -d database`). Si la base ne répond pas, les tests d'intégration sont ignorés.

**`ProductApiClientTest`, 12 tests unitaires.** `MockHttpClient` simule l'API, sans réseau, et un cache en mémoire remplace le cache réel. Les cas couverts :
- le chemin nominal ;
- le cache, et le fait qu'une erreur n'est pas mise en cache ;
- l'URL et les timeouts envoyés ;
- 404 et corps vide ;
- 5xx ;
- JSON invalide, format inattendu, champ manquant ;
- erreur de transport.

**`CommentRepositoryTest`, 11 tests d'intégration** sur un vrai MySQL, la base `blissim_test`.
- **Configuration réelle** : le schéma vient de `sql/comments.sql`, le même fichier que l'application, et le service PDO est celui de `services.yaml`.
- **Isolation** : chaque test tourne dans une transaction annulée à la fin.
- **Garde-fou** : les tests refusent de s'exécuter si `DB_NAME` ne se termine pas par `_test`.
- **Cas couverts** :
  - opérations CRUD ;
  - tri du plus récent au plus ancien ;
  - impossible d'atteindre le commentaire d'un autre produit ;
  - mise à jour sans changement de valeur (`rowCount()` vaut 0) ;
  - stockage littéral d'une injection SQL ou de HTML ;
  - rejet d'un auteur vide par la contrainte `CHECK`.

**Vérifications manuelles**, sur l'application lancée :
- le parcours complet ;
- les statuts 403, 404, 422 et 503, ce dernier avec la base arrêtée.

## 8. Limites connues et suite

**Limites assumées dans le temps imparti :**
- Pas d'authentification : tout visiteur peut modifier ou supprimer un commentaire. C'est le premier point à traiter.
- Pas de test fonctionnel des contrôleurs. La suite naturelle : `WebTestCase`, avec l'API simulée.
- Pas de pagination des commentaires.
- Les dates sont stockées à la seconde. Une modification faite dans la même seconde que la création n'est pas signalée « modifié ».

**Pour la production :**
- Secrets hors du dépôt.
- Migrations versionnées.
- Cache partagé (Redis).
- CI : PHPStan, tests et build de l'image.
- Image Docker multi-étapes.
- Logs centralisés.

## Outils

Développé avec Claude Code comme assistant (génération, tests, revue). Chaque choix a été arbitré, relu et testé.

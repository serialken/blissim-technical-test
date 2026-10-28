# Blissim — Test technique

- **Partie SQL** : schéma e-commerce, jeu de données et deux requêtes, dans [`blissim.sql`](blissim.sql).
- **Partie PHP** : catalogue alimenté par une API externe ([FakeStoreAPI](https://fakestoreapi.com)), avec la gestion complète des commentaires (ajout, modification, suppression), stockés via PDO.

**Stack** : PHP 8.5 · Symfony 8.1 · MySQL 8.4 · Twig · PHPUnit 13 · Docker

## Démarrage

```bash
docker compose up -d --build
```

- Application : http://localhost:8080
- MySQL : `127.0.0.1:3307`, base, utilisateur et mot de passe `blissim`

Au premier démarrage, le conteneur MySQL charge `blissim.sql` puis `sql/comments.sql`, qui contient 3 commentaires de démonstration sur les produits 1 et 2. Pour réinitialiser la base : `docker compose down -v`.

**Sans Docker** : PHP 8.4 ou plus avec `pdo_mysql`, puis :
1. `composer install` ;
2. chargez les deux scripts SQL ;
3. surchargez `DB_*` dans `.env.local` ;
4. lancez `symfony serve`.

Vous pouvez aussi lancer seulement la base avec `docker compose up -d database` : les valeurs par défaut de `.env` pointent sur ce conteneur.

**Tests** : `vendor/bin/phpunit`. Les tests du repository utilisent la base `blissim_test`, créée par `sql/test_database.sql` dans le même conteneur. Si la base ne répond pas, ils sont ignorés.

## Partie SQL

Pour exécuter le script : `docker compose exec -T database mysql --default-character-set=utf8mb4 -ublissim -pblissim blissim < blissim.sql`.
Le script est rejouable.

### Décisions

- **`order_items.unit_price`** fige le prix au moment de l'achat : l'historique reste juste quand le catalogue change. Le jeu de données contient des lignes à un ancien prix pour le montrer.
- **Les montants sont en `DECIMAL(10,2)`**.
- **Intégrité dans la base**, plutôt que dans le seul code applicatif :
  - FK `CASCADE` des commandes vers leurs lignes ;
  - FK `RESTRICT` vers les clients et les produits, pour ne pas perdre d'historique ;
  - `CHECK` sur les prix, les quantités et les statuts (appliqués par MySQL ≥ 8.0.16) ;
  - `UNIQUE (order_id, product_id)`.
- **Index** sur `orders(customer_id)`, `orders(ordered_at)` et `order_items(product_id)` : ils servent les jointures et le filtre temporel de la requête 2.
- **Dates du jeu de données relatives à `NOW()`** : les résultats de la requête sur 7 jours ne dépendent pas du jour d'exécution.

### Résultats

**Requête 1, clients ayant commandé le produit 1.** `DISTINCT` évite les doublons quand un client a commandé le produit plusieurs fois.

| first_name | last_name |
|---|---|
| Léa | Bernard |
| Camille | Martin |
| Julie | Robert |

**Requête 2, produits vendus sur 7 jours, par quantité décroissante.** Les commandes `cancelled` sont **exclues** : une commande annulée n'est pas une vente. Le jeu de données en contient une, récente, pour le vérifier. En cas d'égalité, le tri par nom garantit un résultat stable.

| id | name | total_quantity |
|---|---|---|
| 1 | Box beauté mensuelle | 3 |
| 4 | Mascara volume | 3 |
| 5 | Huile démaquillante | 2 |
| 2 | Sérum hydratant | 2 |
| 6 | Calendrier de l'Avent | 1 |
| 3 | Crème visage bio | 1 |

## Partie PHP

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
```

### Décisions et compromis

**PDO sans Doctrine**, comme l'impose l'énoncé.
- PDO est déclaré comme service : `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false` (requêtes préparées côté serveur).
- Le repository n'expose que les opérations dont l'application a besoin.

**Le client API est traité comme une dépendance non fiable.**
- `timeout` de 5 s et `max_duration` de 10 s.
- Le code HTTP est contrôlé, le JSON décodé en mode strict et chaque produit validé à l'hydratation.
- Toutes les erreurs (transport, statut, contenu) deviennent une seule `ProductApiException` : l'appelant ne dépend pas de l'implémentation HTTP.
- Un produit inconnu renvoie `null`, puis une 404. FakeStoreAPI répond 200 avec un corps vide dans ce cas, ce qui est géré.
- Le cache dure 5 minutes et ne conserve jamais une erreur.

**Les commentaires sont toujours accédés par le couple (`id`, `product_id`).** Modifier l'URL ne permet pas d'agir sur le commentaire d'un autre produit. `comments.product_id` n'a pas de clé étrangère, car les produits vivent dans l'API.

**Validation sur un DTO avec Symfony Validator, plutôt que le composant Form**, jugé disproportionné pour deux champs.
- Les limites sont alignées sur le schéma (100 et 2000 caractères).
- Les `CHECK` SQL restent le dernier garde-fou.
- Une saisie invalide renvoie 422, avec les valeurs et les erreurs réaffichées.

**Sécurité.**
- Jeton CSRF sur chaque formulaire, avec un jeton propre à chaque suppression.
- Les écritures passent uniquement en POST, suivies d'une redirection (Post/Redirect/Get).
- L'échappement Twig est actif par défaut.

**Les erreurs sont centralisées dans un listener plutôt que dans des `try/catch` répartis.** API ou base indisponible : page 503, avec le détail dans les logs. Ressource absente : 404. CSRF invalide : 403.

**Docker.**
- L'application attend le `healthcheck` de MySQL avant de démarrer.
- Le schéma est provisionné au premier démarrage.
- Le port 3307 côté hôte évite les conflits avec un MySQL local.
- OPcache étant intégré à PHP depuis la 8.5, il n'est pas installé comme extension.

### Tests

**Tests automatisés : `ProductApiClientTest`**, 12 tests unitaires. `MockHttpClient` simule l'API et un cache en mémoire remplace le cache réel. Les cas couverts :
- le chemin nominal ;
- le cache, et le fait qu'une erreur n'est pas mise en cache ;
- l'URL et les timeouts envoyés ;
- 404 et corps vide ;
- 5xx ;
- JSON invalide, format inattendu, champ manquant ;
- erreur de transport.

**Tests d'intégration : `CommentRepositoryTest`**, 11 tests sur un vrai MySQL, la base `blissim_test`.
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
- le parcours complet de bout en bout ;
- les statuts 403, 404, 422 et 503, ce dernier avec la base arrêtée.

## Limites connues et suite

**Limites assumées dans le temps imparti :**
- Pas d'authentification : tout visiteur peut modifier ou supprimer un commentaire. C'est le premier point à traiter.
- Pas de test fonctionnel des contrôleurs. La suite naturelle : `WebTestCase`, avec l'API simulée.
- Pas de pagination des commentaires.
- Les dates sont stockées à la seconde. Une modification faite dans la même seconde que la création n'est pas signalée « modifié ».

**Pour la production :**
- Secrets hors du dépôt : les identifiants présents ici sont des valeurs de développement.
- Migrations versionnées.
- Cache partagé (Redis).
- CI : PHPStan, tests et build de l'image.
- Image Docker multi-étapes.
- Logs centralisés.

## Outils

Développé avec Claude Code comme assistant (génération, tests, revue). Chaque choix a été arbitré, relu et testé.

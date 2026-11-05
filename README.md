# Étude de cas e-commerce : du monolithe aux microservices

Trois implémentations fonctionnelles du même domaine (authentification/RBAC, catalogue, commandes, stock, paiement simulé et livraison simulée), ainsi qu'un protocole de benchmark reproductible. La charte SLA de la version 3 précède ses fichiers d'implémentation dans [docs/SLA.md](./docs/SLA.md).

## Arborescence

```text
docs/SLA.md
version1/
  database_v1.sql
  index.php
version2/
  database_v2.sql
  Core/{Auth,Database,Jwt,Response,Router}.php
  Models/{Delivery,Order,Payment,Product,User}.php
  Controllers/{Auth,Delivery,Order,Payment,Product}Controller.php
  client/index.php
  public/{index.php,.htaccess}
  openapi.yaml
version3/
  docker-compose.yml
  .env.example
  php-common/http.php
  auth-service/{Dockerfile,Caddyfile,composer.json,composer.lock,public/index.php}
  log-service/{Dockerfile,requirements.txt,app.py}
  order-service/{Dockerfile,Caddyfile,init.sql,public/index.php}
  inventory-service/{Dockerfile,requirements.txt,init.sql,app.py}
  payment-service/{Dockerfile,Caddyfile,init.sql,public/index.php}
  delivery-service/{Dockerfile,Caddyfile,init.sql,public/index.php}
  benchmark/run.php
benchmark.ps1
```

## Comptes de démonstration

| Identifiant | Mot de passe | Rôle |
|---|---|---|
| `admin` | `Admin123!` | ADMIN |
| `inventory` | `Inventory123!` | INVENTORY_MANAGER |
| `orders` | `Order123!` | ORDER_MANAGER |
| `delivery` | `Delivery123!` | DELIVERY_MANAGER |
| `finance` | `Finance123!` | FINANCE_OFFICER |
| `customer` | `Customer123!` | CUSTOMER |

Ces comptes sont exclusivement destinés à la démonstration locale. Les secrets par défaut du Compose et le secret JWT doivent être changés avant toute réutilisation hors d'un environnement d'enseignement isolé. Le paiement et la livraison sont des simulations.

## Version 1 — monolithe PHP procédural

1. Importer `version1/database_v1.sql` dans MySQL/MariaDB de WAMP avec phpMyAdmin ou le client MySQL.
2. Ouvrir le projet via WAMP, par exemple `http://localhost/lab_mpin/version1/index.php`. Si WAMP sert un autre dossier, copier le dossier `version1` sous `C:\wamp64\www\monolithe-spaghetti` et ouvrir `http://localhost/monolithe-spaghetti/`.
3. Connexion avec le compte correspondant au métier. CUSTOMER achète; INVENTORY_MANAGER réapprovisionne; ORDER_MANAGER suit et met à jour les commandes; DELIVERY_MANAGER crée et suit les livraisons; FINANCE_OFFICER enregistre les paiements; ADMIN dispose de toutes les opérations. Le stock et la commande sont modifiés dans une transaction SQL unique avec verrouillage de ligne. Les événements sont ajoutés à `version1/security_audit.log`.

La connexion utilise par défaut `127.0.0.1`, base `mpin_spaghetti_db`, utilisateur `root` sans mot de passe, configuration WAMP courante. Variables d'environnement de remplacement : `V1_DB_HOST`, `V1_DB_NAME`, `V1_DB_USER`, `V1_DB_PASS`. Au démarrage, l'application ajoute automatiquement la colonne `system_orders.status` si la base WAMP existante a été créée avec l'ancien schéma; elle n'efface aucune donnée. Accorder au processus Apache le droit d'écrire le fichier de log.

## Version 2 — API REST MVC avec JWT et client PHP/Tailwind

1. Importer `version2/database_v2.sql` dans le MySQL local.
2. Définir les variables `V2_DB_HOST`, `V2_DB_NAME`, `V2_DB_USER`, `V2_DB_PASS` si nécessaire. Pour tout environnement autre que local, définir aussi `V2_JWT_SECRET` avec au moins 32 caractères aléatoires.
3. Tester `GET http://localhost/lab_mpin/version2/public/index.php/health`.
4. Ouvrir le client métier à `http://localhost/lab_mpin/version2/client/`. Il propose la même interface par rôle que la version 1 mais exécute toutes les opérations via l'API. Il obtient un JWT à la connexion, le conserve côté serveur dans la session du client web et ajoute `Authorization: Bearer <TOKEN>` aux appels protégés. L'URL par défaut est `http://localhost/lab_mpin/version2/public/index.php`; elle peut être remplacée par la variable Apache/PHP `V2_API_BASE_URL`. TailwindCSS est chargé via CDN; une connexion Internet est nécessaire pour le style.
5. Importer `version2/openapi.yaml` dans Postman ou Swagger pour explorer/tester directement le contrat complet. Adapter son URL serveur si le projet est servi sous un autre chemin.

Authentification Postman : appeler `POST /api/v1/auth/login` avec `{ "username": "customer", "password": "Customer123!" }`, puis utiliser `access_token` comme Bearer token. `GET /api/v1/auth/me` valide le JWT et retourne le rôle. Les routes de stock, commandes, livraisons et paiements appliquent les permissions propres à chaque rôle; leurs parcours sont décrits dans OpenAPI. La commande, la réservation de stock et l'enregistrement du paiement initial PENDING sont atomiques dans la base du monolithe. L'API est sans session PHP; seule l'application cliente possède une session de présentation, sans la transmettre à l'API. L'implémentation JWT HS256 est volontairement native PHP pour l'exercice; ne pas en faire une bibliothèque JWT de production.

## Version 3 — six microservices sous Docker Desktop

La version 3 expose les API uniquement sur le réseau bridge interne `backend`; seul MySQL est publié sur l'hôte en `3307:3306` conformément au contrat. Le benchmark s'exécute dans le conteneur Order. Auth et Order, Payment et Delivery utilisent FrankenPHP/Caddy sur Alpine et un compte non-root; Inventory et Log utilisent FastAPI/Uvicorn sur Alpine, également non-root.

| Service | Technologie | Persistance |
|---|---|---|
| Auth | PHP 8.2, `firebase/php-jwt` | Claims JWT; comptes de démonstration hashés |
| Log | Python 3.12, FastAPI + logging JSON | stdout Docker |
| Order | PHP 8.2, FrankenPHP | MySQL dédié (`order-db`, hôte `3307`) |
| Inventory | Python 3.12, FastAPI, psycopg | PostgreSQL dédié (`inventory-db`, port privé `5432`) |
| Payment | PHP 8.2, FrankenPHP | SQLite isolé (`init.sql`), volume `payment-data` |
| Delivery | PHP 8.2, FrankenPHP | SQLite isolé (`init.sql`), volume `delivery-data` |

Depuis `version3` dans PowerShell :

```powershell
Copy-Item .env.example .env
# Remplacer chaque valeur de .env par un secret distinct avant lancement.
docker compose up --build -d
docker compose ps
docker compose logs -f order-service
```

La première compilation requiert un accès Internet aux registres d'images et dépôts de paquets. Une fois les healthchecks verts, le test fonctionnel interne complet (JWT -> Order -> Inventory -> Payment -> Delivery) s'exécute avec :

```powershell
docker compose exec -T auth-service php -r '$c=stream_context_create(["http"=>["method"=>"POST","header"=>"Content-Type: application/json\r\n","content"=>json_encode(["username"=>"customer","password"=>"Customer123!"]),"ignore_errors"=>true]]); echo file_get_contents("http://auth-service:8080/login",false,$c),PHP_EOL;'
```

Pour créer une commande à partir d'un poste disposant de `curl.exe`, on peut aussi utiliser une image curl temporaire connectée au réseau Compose: voir `benchmark.ps1` pour le test de bout en bout et la sortie chronométrée. Pour arrêter la stack sans supprimer les données : `docker compose down`; pour réinitialiser les bases de cette maquette et effacer ses données persistantes : `docker compose down -v`.

Chaque service métier valide le JWT en appelant Auth avec un timeout borné à deux secondes. L'orchestrateur Order réserve le stock, simule un débit, crée la livraison, puis finalise son propre enregistrement. En cas d'échec connu, il tente annulation de livraison, remboursement et libération du stock dans cet ordre; échecs de compensation sont journalisés comme critiques. Les clés d'idempotence réussies rejouent le résultat; une clé en échec/en cours est refusée (`409`), et une commande corrigée doit utiliser une nouvelle clé. La maquette ne prétend pas résoudre une réponse perdue après effet distant : un système réel doit ajouter outbox, saga persistée et réconciliation. Aucun vrai moyen de paiement ou transporteur n'est intégré.

Les mêmes opérations d'équipe sont disponibles sur le réseau interne Docker avec un JWT obtenu auprès de `auth-service` : Inventory expose `GET /products` et `POST /restock` (INVENTORY_MANAGER/ADMIN); Order expose `GET /orders` et `POST /orders/{id}/status` (ORDER_MANAGER/ADMIN, toutes les commandes étant visibles également par DELIVERY_MANAGER et FINANCE_OFFICER); Delivery expose `GET /deliveries` et `POST /status` (DELIVERY_MANAGER/ADMIN); Payment expose `GET /payments`, `POST /record` et `POST /refund` (FINANCE_OFFICER/ADMIN). Les opérations client `/reserve`, `/orders`, `/charge` et `/ship` restent intégrées dans le parcours d'achat. Les services n'ont volontairement aucun port HTTP publié sur l'hôte.

## Benchmark et crash test

`benchmark.ps1` suppose WAMP opérationnel à l'URL de workspace par défaut et la stack Docker démarrée. Il réutilise la session PHP v1 et les JWT v2/v3 après authentification, mesure seulement chaque opération de commande, et vérifie que chaque réponse réussit. Les commandes consomment une unité de produit 3 à chaque itération; limiter la série à 10 par défaut et réinitialiser les bases avant chaque série comparative.

```powershell
.\benchmark.ps1 -Iterations 5
.\benchmark.ps1 -Iterations 5 -V1Url http://localhost/monolithe-spaghetti/index.php -V2Url http://localhost/mpin_api_mvc/version2/public/index.php
```

Le résultat fournit les mesures en millisecondes, la médiane et un p95 empirique. V1/V2 incluent l'aller-retour HTTP depuis Windows vers WAMP; V3 chronomètre la requête Order depuis le réseau privé Docker et inclut les appels synchrones aux quatre autres services. La méthode et le périmètre sont explicités, mais ces chiffres ne sont pas strictement comparables comme un benchmark de performance de production. Pour démontrer le secours, arrêter temporairement Inventory avec `docker compose stop inventory-service`, envoyer une commande dans le conteneur Order: l'appel doit échouer explicitement avec `503` (pas de succès de substitution); relancer avec `docker compose start inventory-service`. L'API est volontairement non publiée sur l'hôte.

Le benchmark chrono observe l'état des services déjà démarrés; la disponibilité mensuelle, le p95 SLA et les taux d'erreur exigent une charge soutenue et une collecte de métriques, pas quelques appels locaux. Pour provoquer un timeout métier et vérifier le retour `503`:

```powershell
.\benchmark-crash.ps1
```

Le script arrête temporairement Inventory, vérifie que la création de commande échoue explicitement, puis redémarre Inventory même si le test échoue. Voir les limites pédagogiques du [contrat SLA](./docs/SLA.md).

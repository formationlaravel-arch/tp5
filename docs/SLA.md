# Contrat de service — étude de cas e-commerce

Cette charte est le contrat de conception des équipes indépendantes de la version 3. Les objectifs supposent un environnement Docker Desktop local correctement dimensionné; ils ne constituent pas une garantie de production mesurée.

## Objectifs mesurables

| Indicateur | Objectif | Périmètre et mesure |
|---|---:|---|
| Disponibilité mensuelle de chaque API métier | >= 99,9 % | Réponses HTTP disponibles, hors maintenance annoncée |
| Latence inter-services | p95 < 30 ms, p99 < 100 ms | Requêtes HTTP réussies sur le réseau Docker privé, hors démarrage à froid |
| Latence de création de commande | p95 < 250 ms | Appel synchrone complet Order -> Inventory -> Payment -> Delivery sur le réseau privé |
| Délai de réponse maximal d'un appel inter-service | 2 s | Timeout client obligatoire; retries limités à une seule tentative après échec transitoire |
| Erreurs 5xx inter-services | < 0,1 % | Fenêtre glissante de 30 jours, hors tests de panne |
| Traçabilité | 100 % | Chaque requête porte un `X-Request-ID` propagé et chaque erreur est transmise au log-service |

## Obligations d'intégration

1. Les appels métier utilisent HTTP/JSON sur les noms DNS Compose (`auth-service`, `inventory-service`, `payment-service`, `delivery-service`, `log-service`); aucun port de service n'est publié sur l'hôte.
2. Les endpoints de santé `/health` ne dépendent pas de services tiers. Ils répondent `200` lorsque le processus peut accepter une requête.
3. Les clients fixent un timeout de connexion et de lecture à 2 secondes. Ils ne répètent pas automatiquement les opérations financières ou de création d'expédition.
4. Les services valident les entrées, renvoient des erreurs HTTP explicites et ne renvoient jamais un succès de substitution après un timeout.
5. Le JWT est signé avec un secret injecté par environnement, expire, et transporte le rôle. Les services métier valident le token auprès de l'auth-service avant traitement.
6. Chaque service conserve son propre stockage. Aucune transaction distribuée n'est prétendue: l'orchestrateur de commandes applique une compensation de stock en cas d'échec de paiement ou de livraison. Les systèmes réels nécessitent idempotence, outbox et processus de réconciliation.
7. Les logs sont structurés en JSON sur stdout; l'envoi au log-service est best-effort avec timeout borné à 1,5 s et ne transforme jamais l'indisponibilité des logs en succès métier. Les secrets et données de paiement sensibles ne sont jamais journalisés.

## Mode dégradé et secours

- En cas de timeout ou de réponse non-2xx d'Inventory, Payment ou Delivery, la commande échoue explicitement avec `503`; une clé d'idempotence terminée réussie renvoie le résultat enregistré, tandis qu'une clé déjà en échec/en cours renvoie `409` et ne relance aucun effet financier. Après résolution/réconciliation, l'appelant doit employer une nouvelle clé.
- Si le paiement échoue après réservation, Order appelle la libération de stock. Si la livraison échoue après paiement, Order demande l'annulation du paiement puis libère le stock. Toute compensation échouée est journalisée comme erreur critique et nécessite une réconciliation opérateur.
- Si auth-service est indisponible, les endpoints protégés échouent fermés (`503`); les jetons ne sont pas acceptés sur la base d'une validation non vérifiée.
- Si log-service est indisponible, le service émet l'événement sur son propre stdout; l'opération métier continue, car la journalisation centralisée est secondaire.
- Les données de démonstration et les simulations de paiement ne doivent pas être utilisées pour des données réelles ou des paiements réels.

## Sécurité et exploitation

- Communication non chiffrée uniquement sur le réseau Docker local isolé; toute exposition hors de la machine exige TLS, gestionnaire de secrets, limitation de débit et durcissement réseau.
- Images exécutées sans privilèges root, bases non exposées sauf le port MySQL de démonstration `3307`, montages limités aux volumes de données requis.
- L'équipe propriétaire d'un service fournit son healthcheck, son image versionnée, son schéma de données et ses métriques. Les changements d'API sont rétrocompatibles ou versionnés.
- Les bases utilisent des utilisateurs non-root dédiés. Les sauvegardes, restauration, rotation de secrets et alertes ne sont pas automatisées par cette maquette pédagogique.

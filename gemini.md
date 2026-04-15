# API Documentation - Loyalty Card System

## Overview

Cette API Symfony fournit un système complet de gestion de cartes de fidélité pour les commerçants. Elle inclut :

- Authentification via Google OAuth2 + JWT
- Gestion des commerçants, programmes de fidélité, cartes, transactions, clients
- Rewards (récompenses) réclamables via QR code
- **Customer Portal** : accès temporaire sécurisé pour les clients non authentifiés JWT, via `portal_token` court TTL issu d'un wallet token

L'API utilise JWT pour l'authentification des marchands et API Platform pour la gestion des ressources.

## Base URL

```
http://coachat-bakend-loyalty-card.test/api
```

Ou en développement :
```
http://localhost:8000/api
```

## Authentication

L'API utilise JWT (JSON Web Tokens) pour l'authentification. Les tokens doivent être inclus dans l'en-tête `Authorization: Bearer <token>` pour toutes les requêtes authentifiées.

### Google OAuth2 Authentication

#### À propos du paramètre `state`

Le paramètre `state` est inclus pour la conformité avec la spécification OAuth2. Il s'agit d'une chaîne aléatoire générée par le serveur lors de l'initiation de l'authentification.

**Rôle du `state` :**
- **Conformité OAuth2** : Respecte la spécification OAuth2 qui recommande l'utilisation du state
- **Traçabilité** : Permet au frontend de faire correspondre les réponses callback aux requêtes d'authentification
- **Protection optionnelle** : Le frontend peut l'utiliser pour sa propre logique de sécurité

**Important :** Contrairement aux applications traditionnelles, cette API ne valide pas le `state` côté serveur car elle est stateless. Le frontend est responsable de la gestion et validation du state selon ses besoins.

#### Payload JWT réel

Le JWT émis par le backend contient les claims standards suivants :

```json
{
  "iat": 1776278191,
  "exp": 1776281791,
  "roles": ["ROLE_CUSTOMER", "ROLE_USER"],
  "username": "front-contract@example.com"
}
```

**Claims présents :**
- `iat`
- `exp`
- `roles`
- `username`

**Claims absents :**
- `id`
- `name`
- `customer`
- `merchant`

**Règles de rôles :**
- login/signup merchant : `roles` contient au minimum `ROLE_MERCHANT` et `ROLE_USER`
- login/signup customer : `roles` contient au minimum `ROLE_CUSTOMER` et `ROLE_USER`
- si un même compte cumule les deux parcours, le JWT peut contenir `ROLE_MERCHANT`, `ROLE_CUSTOMER`, `ROLE_USER`

**Consigne frontend :**
- pour router rapidement selon le rôle, décoder localement le claim `roles`
- ne pas attendre `id`, `name`, `customer` ou `merchant` dans le JWT
- après login :
  - merchant : appeler `GET /api/merchants/me`
  - customer : appeler `GET /api/customers/me/bootstrap`

#### 1. Initiate Google Login
```
GET /api/auth/google?redirect_uri=<frontend_callback_url>
```

Retourne l'URL d'autorisation Google à ouvrir dans le navigateur ainsi qu'un paramètre `state` pour la protection CSRF.

**Paramètres :**
- `redirect_uri` (requis) : URL de callback du frontend (ex: `http://localhost:5173/auth/callback`)

**Réponse :**
```json
{
  "redirectUrl": "https://accounts.google.com/oauth/authorize?...",
  "state": "random_state_string_for_csrf_protection"
}
```

**Important :** Le frontend doit stocker la valeur `state` retournée pour l'utiliser dans l'étape 2.

#### 2. Handle Google Callback
```
POST /api/auth/google/callback
```

Échange le code d'autorisation contre un JWT token. Doit recevoir le code Google, l'URI de redirection et le state pour la protection CSRF.

**Body :**
```json
{
  "code": "authorization_code_from_google",
  "redirect_uri": "http://localhost:5173/auth/callback",
  "state": "state_value_from_step_1"
}
```

**Paramètres :**
- `code` (requis) : Code d'autorisation reçu de Google dans l'URL de callback
- `redirect_uri` (requis) : Doit correspondre exactement à l'URI envoyé à l'étape 1 et configuré dans Google Console
- `state` (optionnel) : Valeur state retournée à l'étape 1, inclus pour conformité OAuth2

**Réponse :**
```json
{
  "token": "jwt_token_here",
  "refresh_token": null,
  "user": {
    "id": 1,
    "email": "user@example.com",
    "name": "User Name"
  }
}
```

**Erreurs possibles :**
- `400` : `code` ou `redirect_uri` manquant
- `401` : Code invalide ou expiré
- `500` : Erreur lors de l'échange du code avec Google

#### 2.b Customer Google Login (QR / merchant_ref requis)
```
GET /api/auth/customer/google?redirect_uri=<frontend_callback_url>&merchant_ref=<merchant_uuid>
POST /api/auth/customer/google/callback
```

Ce flow est dédié aux customers et ne fonctionne qu'avec un `merchant_ref` valide (QR).

**Body callback :**
```json
{
  "code": "authorization_code_from_google",
  "state": "optional_state_from_step_1",
  "redirect_uri": "http://localhost:5173/auth/callback",
  "merchant_ref": "uuid-merchant"
}
```

**Contrat exact GET `/api/auth/customer/google` :**
- Paramètres query requis :
  - `redirect_uri` (string)
  - `merchant_ref` (string UUID v4/v7 du merchant)
- Réponse 200 :
```json
{
  "redirectUrl": "https://accounts.google.com/oauth/authorize?...",
  "state": "random_state_string_for_csrf_protection",
  "merchant_ref": "uuid-merchant"
}
```

**Contrat exact POST `/api/auth/customer/google/callback` :**
- Champs requis : `code`, `redirect_uri`, `merchant_ref`
- Champ accepté mais non validé côté backend : `state`
- `merchant_ref` attendu : UUID du merchant (format RFC4122)

**Format de réponse callback customer :**
- Même base que merchant (`token`, `refresh_token`, `user`) + bloc `customer`
```json
{
  "token": "jwt_token_here",
  "refresh_token": "refresh_token_here",
  "user": {
    "id": 10,
    "email": "customer@example.com",
    "name": "Customer Name"
  },
  "customer": {
    "id": 42,
    "email": "customer@example.com",
    "name": "Customer Name",
    "merchant_ref": "uuid-merchant"
  }
}
```

**Erreurs métier stables :**
- `422` : `merchant_ref_missing`
- `422` : `merchant_ref_invalid`
- `422` : `merchant_ref_inactive`

**Matrice erreurs customer auth :**
- `400` + `redirect_uri required` (GET) si `redirect_uri` absent
- `400` + `code and redirect_uri required` (POST callback) si `code` ou `redirect_uri` absent
- `422` + `merchant_ref_missing` si `merchant_ref` absent/vidé
- `422` + `merchant_ref_invalid` si UUID invalide ou merchant inexistant
- `422` + `merchant_ref_inactive` si merchant existe mais `subscription_status = canceled`
- `400` + `Authentication failed: ...` si échange Google KO

**Comportement :**
- Crée/met à jour un `User` Google sans doublon (googleId/email)
- Attribue le rôle `ROLE_CUSTOMER`
- Crée/relie le `Customer` au `User` sans doublon (priorité : `customer.user`, fallback `customer.email` si `user` manquant)
- Lie le customer au merchant via relation multi-marchands

#### 3. Get User Profile
```
GET /api/profile
```

Retourne les informations du profil utilisateur connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "id": 1,
  "email": "user@example.com",
  "name": "User Name"
}
```

#### 4. Refresh Token
```
POST /api/auth/refresh
```

Régénère un nouveau JWT token avec un nouveau refresh token.

**Body :**
```json
{
  "refresh_token": "refresh_token_from_storage"
}
```

**Réponse :**
```json
{
  "token": "new_jwt_token_here",
  "refresh_token": "new_refresh_token_here",
  "user": {
    "id": 1,
    "email": "user@example.com",
    "name": "User Name"
  }
}
```

#### 5. Logout
```
POST /api/auth/logout
```

Déconnecte l'utilisateur en invalidant le refresh token.

**Headers :**
- `Authorization: Bearer <token>`

## Merchants (Commerçants)

### Get Current Merchant (Compact)
```
GET /api/merchants/me
```

Retourne le commerçant associé à l'utilisateur connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "company_name": "My Store",
  "email": "store@example.com",
  "phone": "01 23 45 67 89",
  "address": "123 rue de la Paix, 75000 Paris",
  "postal_code": "75000",
  "city": "Paris",
  "logo_url": "data:image/png;base64,iVBORw0KGgoAAAANS...",
  "user": {
    "id": 1,
    "email": "owner@example.com",
    "name": "Owner Name"
  }
}
```

### Get Current Merchant (Detailed)
```
GET /api/merchant/me
```

Retourne le commerçant connecté avec plan + compteurs.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "company_name": "My Store",
  "email": "store@example.com",
  "phone": "01 23 45 67 89",
  "address": "123 rue de la Paix, 75000 Paris",
  "postal_code": "75000",
  "city": "Paris",
  "logo_url": "data:image/png;base64,iVBORw0KGgoAAAANS...",
  "stripe_customer_id": "cus_123456",
  "trial_ends_at": "2026-05-03T00:00:00Z",
  "subscription_status": "active",
  "active_loyalty_program_count": 3,
  "plan": {
    "id": "...",
    "slug": "free",
    "name": "Gratuit",
    "price_monthly": 0,
    "max_customers": 50,
    "max_programs": 1,
    "has_wallet_integration": false,
    "has_push_notifications": false,
    "has_advanced_stats": false
  }
}
```

**Champs calculés** :
- `active_loyalty_program_count` : nombre de programmes de fidélité actifs pour le commerçant connecté
- `plan` : plan actif du commerçant (null si aucun plan assigné)


### Create Merchant
```
POST /api/merchants
```

Crée un nouveau commerçant pour l'utilisateur connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "company_name": "My Store Name",
  "phone": "01 23 45 67 89",
  "address": "123 rue de la Paix, 75000 Paris",
  "postal_code": "75000",
  "city": "Paris",
  "accepted_terms": true,
  "accepted_terms_version": "2026-04-15",
  "accepted_terms_accepted_at": "2026-04-15T10:15:00Z"
}
```

**Règles :**
- `company_name` requis, string non vide
- `postal_code` requis à la création, format libre, max 10 caractères
- `city` requis à la création, format libre, max 100 caractères
- `accepted_terms` requis à la création et doit être `true`
- `accepted_terms_version` requis à la création, string non vide, max 32 caractères
- `accepted_terms_accepted_at` requis à la création, datetime ISO 8601 valide
- `phone` optionnel, `string|null`
- `address` optionnel, `string|null`
- `logo_url` est toujours `null` à la création (upload via endpoint dédié)

**Codes d'erreur de validation légale :**
- `422` : `accepted_terms must be true`
- `422` : `accepted_terms_version required`
- `422` : `accepted_terms_accepted_at must be a valid datetime`

**Réponse :**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "company_name": "My Store Name",
  "email": "store@example.com",
  "phone": "01 23 45 67 89",
  "address": "123 rue de la Paix, 75000 Paris",
  "postal_code": "75000",
  "city": "Paris",
  "logo_url": null,
  "stripe_customer_id": null,
  "trial_ends_at": "2026-05-12T12:00:00Z",
  "accepted_terms": true,
  "accepted_terms_version": "2026-04-15",
  "accepted_terms_accepted_at": "2026-04-15T10:15:00Z",
  "subscription_status": "trial",
  "active_loyalty_program_count": 0,
  "plan": {
    "id": "...",
    "slug": "free",
    "name": "Gratuit",
    "price_monthly": 0,
    "max_customers": 100,
    "max_programs": 1,
    "has_wallet_integration": false,
    "has_push_notifications": false,
    "has_advanced_stats": false
  }
}
```

### Get Plan Usage
```
GET /api/merchants/me/plan-usage
```

Retourne l'utilisation actuelle du plan du commerçant connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "plan": {
    "id": "...",
    "slug": "free",
    "name": "Gratuit",
    "price_monthly": 0,
    "max_customers": 50,
    "max_programs": 1,
    "has_wallet_integration": false,
    "has_push_notifications": false,
    "has_advanced_stats": false
  },
  "usage": {
    "customers": 12,
    "programs": 1
  },
  "limits_reached": {
    "customers": false,
    "programs": true
  }
}
```

**Note** : `max_customers` ou `max_programs` à `-1` signifie illimité.

### Update Merchant
```
PUT /api/merchants/{id}
```

Met à jour un commerçant.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "company_name": "Updated Store Name",
  "phone": "06 12 34 56 78",
  "address": "Nouvelle adresse",
  "postal_code": "69002",
  "city": "Lyon",
  "accepted_terms_version": "2026-05-01",
  "accepted_terms_accepted_at": "2026-05-01T12:00:00Z"
}
```

**Comportement :**
- Update partiel : seuls les champs présents sont modifiés
- Tous les champs du body sont optionnels
- `company_name` non vide si présent
- `phone` format libre (`string|null`)
- `address` format libre (`string|null`)
- `postal_code` format libre (`string|null`), max 10 caractères si présent
- `city` format libre (`string|null`), max 100 caractères si présent
- `accepted_terms` ne peut pas être remis à `false` (retourne `422`)
- `accepted_terms_version` et `accepted_terms_accepted_at` peuvent être mis à jour uniquement avec des valeurs valides
- Le merchant ne peut modifier que son propre profil

**Champs optionnels pour le plan** :
- `plan_id` : UUID du plan à assigner
- `plan_slug` : slug du plan à assigner (`free`, `standard`, `premium`)

### Upload Merchant Logo
```
POST /api/merchants/{id}/logo
```

Upload/remplacement du logo merchant (MVP: stockage base64 direct en DB).

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "logo": "data:image/png;base64,iVBORw0KGgoAAAANS..."
}
```

**Règles :**
- Formats acceptés: `image/png`, `image/jpeg`, `image/jpg`, `image/webp`
- Le champ doit être une data URL base64 valide
- Taille max: 5MB (taille binaire décodée)
- Le merchant ne peut uploader que son propre logo
- Le logo existant est remplacé

**Erreurs fréquentes :**
- `400` `logo is required`
- `400` `Format invalide`
- `400` `Fichier trop volumineux (max 5MB)`
- `404` `Merchant not found`

**Réponse :** objet merchant à jour (incluant `logo_url`)

### Preuve d'acceptation légale

Les informations suivantes sont persistées sur le merchant :
- `accepted_terms` (bool)
- `accepted_terms_version` (string)
- `accepted_terms_accepted_at` (datetime)

Ces champs sont retournés dans toutes les réponses merchant principales (`/api/merchant/me`, `/api/merchants/me`, réponses create/update merchant).

## Versionning des conditions légales

### Format de version recommandé

Utiliser une version lisible, stable et strictement monotone.

Recommandation MVP :
- format date de publication : `YYYY-MM-DD` (exemple `2026-04-15`)
- 1 version publiée = 1 valeur unique

Alternative plus explicite (si besoin de variantes) :
- `YYYY-MM-DD.N` (exemple `2026-04-15.1`)

### Règles opérationnelles

1. Publier une nouvelle version légale :
- incrémenter `CURRENT_TERMS_VERSION` côté front/back (même valeur)
- déployer le nouveau texte légal

2. Onboarding :
- le front envoie la version affichée au user via `accepted_terms_version`
- le back persiste cette version + la date d'acceptation

3. Re-acceptation (si texte mis à jour) :
- comparer `merchant.accepted_terms_version` à la version courante
- si différente, forcer l'écran de ré-acceptation
- appeler `PUT /api/merchants/{id}` avec la nouvelle version + nouvelle date

### Bonnes pratiques d'audit

1. Ne jamais modifier rétroactivement une version déjà publiée.
2. Conserver l'archive du texte légal pour chaque version (fichier horodaté ou stockage immutable).
3. Journaliser qui a accepté, quand, et avec quelle version (les 3 champs ci-dessus).
4. Toujours stocker/afficher les dates en UTC (`...Z`) pour éviter les ambiguïtés fuseau.

### Politique de date d'acceptation

Stratégie actuelle API :
- la date envoyée par le frontend (`accepted_terms_accepted_at`) est validée puis persistée.

Option plus stricte (future) :
- ignorer la date front et imposer la date serveur pour réduire la surface de fraude horodatage.

## Plans (Abonnements)

Les plans définissent les limites et fonctionnalités disponibles pour les commerçants. Trois plans sont disponibles : **Gratuit**, **Standard**, **Premium**.

> **Authentification** : Ces endpoints sont publics — aucun token JWT requis.

### List Plans
```
GET /api/plans
```

Retourne la liste de tous les plans actifs.

**Réponse :**
```json
[
  {
    "id": "...",
    "slug": "free",
    "name": "Gratuit",
    "price_monthly": 0,
    "max_customers": 50,
    "max_programs": 1,
    "has_wallet_integration": false,
    "has_push_notifications": false,
    "has_advanced_stats": false
  },
  {
    "id": "...",
    "slug": "standard",
    "name": "Standard",
    "price_monthly": 1900,
    "max_customers": 500,
    "max_programs": 5,
    "has_wallet_integration": false,
    "has_push_notifications": false,
    "has_advanced_stats": false
  },
  {
    "id": "...",
    "slug": "premium",
    "name": "Premium",
    "price_monthly": 2900,
    "max_customers": -1,
    "max_programs": -1,
    "has_wallet_integration": true,
    "has_push_notifications": true,
    "has_advanced_stats": true
  }
]
```

**Note** : `max_customers` ou `max_programs` à `-1` signifie illimité. `price_monthly` est en centimes (1900 = 19,00 €).

### Get Plan
```
GET /api/plans/{id}
```

Retourne un plan par son UUID.

**Réponse :** même structure que l'objet plan ci-dessus.

## Loyalty Programs (Programmes de Fidélité)

Les programmes de fidélité définissent les règles d'accumulation et les objectifs de récompenses. Deux types sont supportés :
- **POINTS** : Points cumulatifs avec objectif `points_target`
- **STAMP** : Tampons cumulatifs avec objectif `stamp_target`

### Get Loyalty Programs
```
GET /api/loyalty_programs?merchant={merchant_id}
```

Retourne tous les programmes de fidélité d'un commerçant.

**Headers :**
- `Authorization: Bearer <token>`

**Paramètres :**
- `merchant` (requis) : ID UUID du commerçant

**Réponse :**
```json
[
  {
    "id": 1,
    "name": "Coffee Rewards",
    "description": "Earn points for every coffee purchase",
    "type": "POINTS",
    "points_per_euro": 10,
    "points_target": 100,
    "stamp_target": null,
    "reward_description": "Free coffee",
    "is_active": true
  }
]
```

### Create Loyalty Program
```
POST /api/loyalty_programs
```

Crée un nouveau programme de fidélité.

**Headers :**
- `Authorization: Bearer <token>`

**Body (POINTS type) :**
```json
{
  "name": "Coffee Rewards",
  "description": "Earn points for every coffee purchase",
  "type": "POINTS",
  "points_per_euro": "0.10",
  "points_target": 100,
  "reward_description": "Free coffee",
  "is_active": true
}
```

**Body (STAMP type) :**
```json
{
  "name": "Buy 10 get 1 free",
  "description": "Collect stamps for rewards",
  "type": "STAMP",
  "stamp_target": 10,
  "reward_description": "Free item",
  "is_active": true
}
```

**Paramètres :**
- `name` (requis) : Nom du programme
- `description` (optionnel) : Description du programme
- `type` (requis) : `POINTS` ou `STAMP`
- `points_per_euro` (optionnel, si type POINTS) : Points gagnés par euro dépensé
- `points_target` (requis si type POINTS) : Points nécessaires pour obtenir une récompense
- `stamp_target` (requis si type STAMP) : Nombre de tampons nécessaires pour obtenir une récompense
- `reward_description` (optionnel) : Description de la récompense
- `is_active` (défaut: true) : Statut du programme

### Update Loyalty Program
```
PUT /api/loyalty_programs/{id}
```

Met à jour un programme de fidélité.

**Headers :**
- `Authorization: Bearer <token>`

## Loyalty Cards (Cartes de Fidélité)

### Get Loyalty Cards
```
GET /api/loyalty_cards?merchant={merchant_id}
```

Retourne toutes les cartes de fidélité d'un commerçant.

**Headers :**
- `Authorization: Bearer <token>`

**Paramètres :**
- `merchant` (requis) : ID UUID du commerçant

**Réponse :**
```json
[
  {
    "id": 1,
    "wallet_token": "550e8400-e29b-41d4-a716-446655440000",
    "current_value": 50,
    "target_value": 100,
    "is_completed": false,
    "wallet_apple_url": "http://localhost:8000/public/wallet/apple/550e8400-e29b-41d4-a716-446655440000",
    "wallet_google_url": "http://localhost:8000/public/wallet/google/550e8400-e29b-41d4-a716-446655440000",
    "loyalty_program": {
      "id": 1,
      "name": "Coffee Rewards",
      "type": "POINTS"
    },
    "customer": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com"
    },
    "merchant": {
      "id": 1,
      "company_name": "Mon Commerce",
      "logo_url": "data:image/png;base64,iVBORw0KGgoAAAANS...",
      "phone": "01 23 45 67 89",
      "address": "123 rue de la Paix, 75000 Paris",
      "postal_code": "75000",
      "city": "Paris"
    }
  }
]
```

### Get Loyalty Card by Token
```
GET /api/loyalty_cards/by-token/{walletToken}
```

Retourne une carte de fidélité par son `wallet_token` (UUID v4).

**Headers :**
- Aucun header d'authentification requis (endpoint public).

**Comportement d'accès :**
- Utilisateur non connecté : accès autorisé avec payload public (pas d'email customer).
- Merchant propriétaire connecté : payload complet.

**Payload merchant retourné (public et privé) :**
```json
{
  "merchant": {
    "id": "...",
    "company_name": "Cafe du Centre",
    "logo_url": "data:image/png;base64,iVBORw0KGgoAAAANS...",
    "phone": "01 23 45 67 89",
    "address": "123 rue de la Paix, 75000 Paris",
    "postal_code": "75000",
    "city": "Paris"
  }
}
```

### Create Loyalty Card
```
POST /api/loyalty_cards
```

Crée une nouvelle carte de fidélité.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "loyalty_program_id": 1,
  "customer_id": 1,
  "target_value": 100
}
```

**Réponse :**
```json
{
  "id": 1,
  "wallet_token": "550e8400-e29b-41d4-a716-446655440000",
  "current_value": 0,
  "target_value": 100,
  "is_completed": false,
  "wallet_apple_url": "http://localhost:8000/public/wallet/apple/550e8400-e29b-41d4-a716-446655440000",
  "wallet_google_url": "http://localhost:8000/public/wallet/google/550e8400-e29b-41d4-a716-446655440000",
  "loyalty_program": {
    "id": 1,
    "name": "Coffee Rewards",
    "type": "POINTS"
  },
  "customer": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "merchant": {
    "id": 1,
    "company_name": "Mon Commerce",
    "logo_url": "data:image/png;base64,iVBORw0KGgoAAAANS...",
    "phone": "01 23 45 67 89",
    "address": "123 rue de la Paix, 75000 Paris",
    "postal_code": "75000",
    "city": "Paris"
  }
}
```

### Update Loyalty Card
```
PATCH /api/loyalty_cards/{id}
```

Met à jour une carte de fidélité.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "current_value": 75,
  "target_value": 100,
  "is_completed": false
}
```

**Réponse :**
```json
{
  "id": 1,
  "wallet_token": "550e8400-e29b-41d4-a716-446655440000",
  "current_value": 75,
  "target_value": 100,
  "is_completed": false,
  "wallet_apple_url": "http://localhost:8000/public/wallet/apple/550e8400-e29b-41d4-a716-446655440000",
  "wallet_google_url": "http://localhost:8000/public/wallet/google/550e8400-e29b-41d4-a716-446655440000",
  "loyalty_program": {
    "id": 1,
    "name": "Coffee Rewards",
    "type": "POINTS"
  },
  "customer": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "merchant": {
    "id": 1,
    "company_name": "Mon Commerce",
    "logo_url": "data:image/png;base64,iVBORw0KGgoAAAANS...",
    "phone": "01 23 45 67 89",
    "address": "123 rue de la Paix, 75000 Paris",
    "postal_code": "75000",
    "city": "Paris"
  }
}
```

### Disable Loyalty Card
```
PATCH /api/loyalty_cards/{id}/disable
```

Désactive une carte de fidélité (soft-delete). La carte n'apparaîtra plus dans aucune liste ni recherche.

**Headers :**
- `Authorization: Bearer <token>`

**Body :** vide ou `{}`

**Réponse :** `204 No Content`

**Notes :**
- La carte n'est pas supprimée physiquement en base de données
- Le champ `visible` n'est pas exposé dans les réponses API
- Seul le merchant propriétaire peut désactiver une carte

## Wallet (Apple & Google)

Endpoints publics (sans JWT) permettant au customer d'ajouter sa carte à son wallet mobile.

### Apple Wallet
```
GET /public/wallet/apple/{walletToken}
```

Retourne le fichier `.pkpass` à ouvrir dans l'app Wallet sur iPhone.

**Paramètres :**
- `walletToken` : valeur du champ `wallet_token` retourné par les endpoints loyalty_cards

**Réponse :** Binaire `application/vnd.apple.pkpass` (téléchargement du fichier)

**Note :** Le `.pkpass` contient un `webServiceURL` et un `authenticationToken` (= `wallet_token`) permettant à Apple Wallet d'enregistrer l'appareil pour les mises à jour push.

### Google Wallet
```
GET /public/wallet/google/{walletToken}
```

Redirige vers l'URL "Ajouter à Google Wallet".

**Paramètres :**
- `walletToken` : valeur du champ `wallet_token` retourné par les endpoints loyalty_cards

**Réponse :** Redirection HTTP 302 vers `https://pay.google.com/gp/v/save/{jwt}`

**Note :** Ces deux routes ne nécessitent pas de token JWT.

---

## PassKit Web Service (Apple Wallet — mises à jour en temps réel)

Endpoints implémentant le protocole PassKit Web Service d'Apple. Ils sont appelés automatiquement par iOS — **jamais par le frontend**.

> **Base URL** : `{APP_BASE_URL}/public/wallet/apple/update/` (correspond au `webServiceURL` dans le .pkpass)
> **Auth** : Header `Authorization: ApplePass <wallet_token>` envoyé par iOS

### Enregistrement d'un appareil
```
POST /public/wallet/apple/update/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}
```
Body : `{ "pushToken": "apns_push_token" }`
Réponse : `201 Created` (nouveau) ou `200 OK` (déjà enregistré)

### Désenregistrement d'un appareil
```
DELETE /public/wallet/apple/update/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}
```
Réponse : `200 OK`

### Liste des passes à mettre à jour
```
GET /public/wallet/apple/update/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}
```
Réponse : `200 OK` avec `{ "lastUpdated": "timestamp", "serialNumbers": [...] }` ou `204 No Content`

### Téléchargement du pass mis à jour
```
GET /public/wallet/apple/update/v1/passes/{passTypeIdentifier}/{serialNumber}
```
Réponse : `.pkpass` mis à jour

### Log Apple
```
POST /public/wallet/apple/update/v1/log
```
Réponse : `200 OK`

### Activation (variables d'env requises)
```env
APPLE_WALLET_ENABLED=true
APPLE_WALLET_PASS_TYPE_IDENTIFIER=pass.com.yourcompany.loyaltycard
APPLE_WALLET_TEAM_IDENTIFIER=YOUR_TEAM_ID
APPLE_APNS_KEY_ID=YOUR_APNS_KEY_ID
APPLE_APNS_PRIVATE_KEY_PATH=/path/to/apns-key.p8
```
Par défaut `APPLE_WALLET_ENABLED=false` — les notifications ne sont pas envoyées sans les clés.

---

## Synchronisation Google Wallet (mises à jour en temps réel)

Lors de chaque `POST /api/transactions`, le backend appelle **automatiquement** l'API Google Wallet pour mettre à jour le solde de l'objet de fidélité.

### Activation (variables d'env requises)
```env
GOOGLE_WALLET_ENABLED=true
GOOGLE_WALLET_ISSUER_ID=your_issuer_id
GOOGLE_WALLET_SERVICE_ACCOUNT_KEY_PATH=/path/to/service-account-key.json
```
Par défaut `GOOGLE_WALLET_ENABLED=false`.

**Flow lors d'une transaction :**
1. Transaction créée et sauvegardée en BDD
2. Recalcul de la progression de la carte (`current_value`, `is_completed`)
3. Si la carte passe de `is_completed=false` à `is_completed=true`, création idempotente d'une `Reward`
4. `AppleWalletPushService::notifyUpdate()` → envoie une notification APNs à chaque appareil enregistré → iOS re-télécharge le `.pkpass`
5. `GoogleWalletSyncService::syncCard()` → obtient un token OAuth2 via service account → `PATCH https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/{id}` avec la nouvelle valeur

---

## Transactions

### Get Transactions
```
GET /api/transactions?merchant={merchant_id}
```

Retourne toutes les transactions d'un commerçant.

**Headers :**
- `Authorization: Bearer <token>`

**Paramètres :**
- `merchant` (requis) : ID UUID du commerçant

**Réponse :**
```json
[
  {
    "id": 1,
    "points_earned": 10,
    "points_redeemed": 0,
    "amount_added": 10,
    "notes": "Coffee purchase",
    "created_at": "2026-04-03 10:00:00",
    "loyalty_card": {
      "id": 1,
      "wallet_token": "550e8400-e29b-41d4-a716-446655440000"
    }
  }
]
```

### Create Transaction
```
POST /api/transactions
```

Crée une nouvelle transaction et met à jour les points de la carte.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "loyalty_card_id": 1,
  "amount_added": 10,
  "points_earned": 10,
  "points_redeemed": 0,
  "notes": "Coffee purchase"
}
```

---

## Rewards (Récompenses)

Cycle de vie d'une reward : `PENDING` → `CLAIMED` (ou `CANCELLED` / `EXPIRED`).

### Shape Reward

```json
{
  "id": "uuid",
  "loyalty_card_id": 1,
  "merchant_id": "uuid-merchant",
  "customer_id": 42,
  "wallet_token": "wallet-token",
  "reward_description": "1 café offert",
  "status": "PENDING",
  "generated_at": "2026-04-08T10:15:00+00:00",
  "claimed_at": null,
  "claim_qr_token": "token-unique",
  "customer": {
    "id": 42,
    "name": "Jane Doe",
    "email": "jane@example.com"
  },
  "merchant": {
    "id": "uuid-merchant",
    "company_name": "Coffee Shop"
  },
  "loyalty_program": {
    "id": 7,
    "name": "Coffee Rewards",
    "type": "STAMP",
    "reward_description": "1 café offert"
  }
}
```

### Get Rewards

```http
GET /api/rewards?merchant={id}&customer_email={email}&wallet_token={token}&status=PENDING&page=1&itemsPerPage=20
```

Filtres supportés :
- `merchant`
- `customer_email`
- `wallet_token`
- `status` (`PENDING`, `CLAIMED`, `CANCELLED`, `EXPIRED`)

Notes sécurité :
- Si authentifié merchant : retour limité au merchant connecté.
- En lookup public (`customer_email`/`wallet_token`) : payload réduit et rate-limité.

### Generate From Completion (idempotent)

```http
POST /api/rewards/from-completion
Authorization: Bearer <token>
```

Body :

```json
{
  "loyalty_card_id": 1,
  "transaction_id": 123
}
```

Comportement :
- Vérifie que la carte est complétée.
- Crée la reward si absente.
- Retourne la reward existante sinon (idempotence).

### Get Reward By Loyalty Card ID

```http
GET /api/rewards/by-card/{cardId}
Authorization: Bearer <token>
```

Comportement :
- Nécessite un JWT merchant valide.
- Vérifie que la carte appartient bien au merchant authentifié.
- Retourne la reward la plus récente liée à cette carte.

**Réponse 200 :**

```json
{
  "id": "uuid-reward",
  "loyalty_card_id": 12,
  "merchant_id": "uuid-merchant",
  "customer_id": 42,
  "wallet_token": "550e8400-e29b-41d4-a716-446655440000",
  "reward_description": "1 café offert",
  "status": "PENDING",
  "generated_at": "2026-04-12T10:15:00+00:00",
  "claimed_at": null,
  "claim_qr_token": "token-unique",
  "customer": {
    "id": 42,
    "name": "Jane Doe",
    "email": "jane@example.com"
  },
  "merchant": {
    "id": "uuid-merchant",
    "company_name": "Coffee Shop"
  },
  "loyalty_program": {
    "id": 7,
    "name": "Coffee Rewards",
    "type": "STAMP",
    "reward_description": "1 café offert"
  }
}
```

**Notes front :**
- `claimed_at` est `null` tant que la reward n'est pas claim.
- `status` est une enum parmi `PENDING`, `CLAIMED`, `CANCELLED`, `EXPIRED`.
- `claim_qr_token` est présent dans ce endpoint merchant.
- Réponses possibles :
  - `200` reward trouvée
  - `401` non authentifié
  - `404` merchant/carte/reward introuvable ou carte hors périmètre du merchant

### Claim By QR

```http
POST /api/rewards/claim-by-qr
Authorization: Bearer <token>
```

Body :

```json
{
  "qr_token": "lacarte-reward:TOKEN_OU_TOKEN_BRUT"
}
```

Comportement :
- Parse `lacarte-reward:TOKEN` ou token brut.
- Trouve la reward par `claim_qr_token`.
- Autorise uniquement `PENDING`.
- Passe à `CLAIMED`, renseigne `claimed_at` et l'acteur.
- Si déjà `CLAIMED` : `409`.

### Admin Status Update

```http
PATCH /api/rewards/{id}
Authorization: Bearer <token>
```

Body :

```json
{
  "status": "CANCELLED",
  "cancel_reason": "fraud suspected"
}
```

Statuts autorisés en PATCH : `CANCELLED`, `EXPIRED`.

### Audit

Toutes les transitions de statut sont journalisées (`reward_status_log`) avec :
- statut source / statut cible
- horodatage
- acteur merchant/user
- raison / métadonnées éventuelles

## Subscriptions (Abonnements)

Gestion des abonnements Stripe pour les commerçants. Le frontend passe un `plan_id` ou `plan_slug` — le backend résout lui-même le Stripe Price ID depuis l'entité Plan (non exposé au frontend).

### Create Checkout Session
```
POST /api/subscription/checkout
```

Crée une session Stripe Checkout pour l'abonnement, ou assigne directement le plan gratuit.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "plan_slug": "standard"
}
```

ou via UUID :
```json
{
  "plan_id": "uuid-du-plan"
}
```

**Réponse (plan payant) :**
```json
{
  "sessionId": "cs_live_xxxxx",
  "checkoutUrl": "https://checkout.stripe.com/pay/cs_live_xxxxx"
}
```

**Réponse (plan gratuit) :**
```json
{
  "plan": "free",
  "status": "active"
}
```

**Erreurs :**
- `400` : `plan_id` ou `plan_slug` manquant ou invalide
- `503` : Le plan n'a pas encore de Stripe Price ID configuré

**Notes :**
- Le Stripe Price ID est résolu côté backend depuis l'entité Plan — il n'est jamais exposé au frontend
- Le plan gratuit est assigné directement sans passer par Stripe
- Le `plan_id` est stocké dans les métadonnées de la session Stripe pour être assigné au merchant au webhook

### Configuration Stripe Required

**.env Variables :**
```env
STRIPE_PUBLIC_KEY=pk_live_xxxxx
STRIPE_SECRET_KEY=sk_live_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx
STRIPE_SUCCESS_URL=http://localhost:5173/subscription?session_id={CHECKOUT_SESSION_ID}
STRIPE_CANCEL_URL=http://localhost:5173/subscription?canceled=true
```

### Webhook Handler
```
POST /api/subscription/webhook
```

Endpoint qui reçoit les webhooks Stripe (à configurer dans Stripe Dashboard).

**Signature :** Utilise `stripe-signature` header pour valider l'authenticité

**Événements gérés :**

| Événement | Effet |
|-----------|-------|
| `checkout.session.completed` | Assigne le plan au merchant + statut `active` |
| `customer.subscription.updated` | Synchronise le statut (`active` / `inactive` / `canceling`) et backfill `current_period_start_at` / `current_period_end_at` si absents |
| `customer.subscription.deleted` | Retour au plan `free` + statut `canceled` |
| `invoice.payment_failed` | Statut `suspended` |

> À configurer dans **Stripe Dashboard → Développeurs → Webhooks → Ajouter un endpoint** en sélectionnant exactement ces 4 événements.

**Réponse :**
```json
{
  "received": true
}
```

### Flow Complet

1. Frontend appelle `GET /api/plans` pour afficher les plans disponibles
2. Utilisateur choisit un plan → frontend envoie `POST /api/subscription/checkout` avec `plan_slug`

---

## Customer Portal (Portail Client Public)

Accès temporaire et sécurisé pour un client non authentifié JWT. Basé sur un `portal_token` opaque, court TTL, hash HMAC-SHA256 en base.

### Sécurité
- Token opaque : `pt_live_` + 64 hex (256 bits d'entropie, `random_bytes(32)`)
- Stockage : HMAC-SHA256(token, APP_SECRET) — jamais en clair
- TTL : 15 min, refreshable jusqu'à 24h cumulées (fenêtre glissante)
- Rate limiting : 10 req/min bootstrap (IP), 60 req/min overview (token hash)
- Aucun accès par `customer_id` ou email seul côté public

### Entité CustomerPortalSession

| Champ | Type | Description |
|-------|------|-------------|
| `id` | UUID v4 | PK |
| `customer` | ManyToOne Customer | Customer lié |
| `token_hash` | VARCHAR(64) UNIQUE | HMAC-SHA256 du token brut |
| `issued_from_wallet_token` | VARCHAR(36) | Wallet token source (audit) |
| `issued_at` | datetime_immutable | Émission |
| `original_issued_at` | datetime_immutable nullable | Préservé sur refresh pour fenêtre 24h |
| `expires_at` | datetime_immutable | Expiration |
| `revoked_at` | datetime_immutable nullable | Révocation explicite |
| `last_used_at` | datetime_immutable nullable | Dernière activité |
| `ip` | VARCHAR(45) nullable | IPv4/v6 pour audit |
| `user_agent` | VARCHAR(512) nullable | UA pour audit |
| `scope` | VARCHAR(255) | `cards:read rewards:read` |

### Endpoints

#### POST /api/public/customer-portal/bootstrap
Émet un portal_token depuis un wallet_token valide.

**Body :**
```json
{ "wallet_token": "wallet-token-source" }
```

**Réponse 200 :**
```json
{
  "portal_token": "pt_live_xxxxxxxxx",
  "token_type": "Bearer",
  "expires_at": "2026-04-08T12:30:00+00:00",
  "customer": { "id": 42, "name": "Jane Doe" }
}
```

**Erreurs :** 400 wallet_token manquant, 404 wallet_token inconnu, 410 carte sans customer, 429 rate limit

---

#### GET /api/public/customer-portal/overview
Vue consolidée cartes + rewards du customer. Nécessite `Authorization: Bearer <portal_token>`.

**Query params :** `include=cards,rewards` `status=PENDING` `page=1` `itemsPerPage=20`

**Réponse 200 :**
```json
{
  "customer": { "id": 42, "name": "Jane Doe" },
  "cards": [...],
  "rewards": [...],
  "meta": { "cards_count": 3, "rewards_count": 2 }
}
```

**Erreurs :** 401 token invalide/révoqué/expiré, 429 rate limit

---

#### POST /api/public/customer-portal/refresh
Rotation de token : révoque l'ancien et en émet un nouveau. Nécessite `Authorization: Bearer <portal_token>`.

**Réponse 200 :**
```json
{ "portal_token": "pt_live_new_xxxxx", "token_type": "Bearer", "expires_at": "..." }
```

**Erreurs :** 401 token invalide, 403 fenêtre 24h dépassée

---

#### POST /api/public/customer-portal/revoke
Logout public — invalide la session courante. Réponse 204 No Content.

---

### Codes d'erreur JSON (format standard)

| Code | HTTP | Signification |
|------|------|---------------|
| `PORTAL_TOKEN_INVALID` | 401 | Token absent, malformé ou inconnu |
| `PORTAL_TOKEN_EXPIRED` | 401 | Session expirée |
| `PORTAL_TOKEN_REVOKED` | 401 | Session révoquée explicitement |
| `CLAIM_WALLET_TOKEN_INVALID` | 404/410 | Wallet token inconnu ou carte sans customer |
| `RATE_LIMITED` | 429 | Trop de requêtes |

### Flow côté Front

1. Depuis la page claim (wallet_token connu) → `POST /bootstrap`
2. Stocker `portal_token` en `sessionStorage` (pas `localStorage`)
3. `GET /overview` pour afficher cartes et rewards
4. Si 401 avec code `PORTAL_TOKEN_EXPIRED` ou `PORTAL_TOKEN_REVOKED` → forcer retour au flux claim
5. `POST /refresh` avant expiration si session prolongée nécessaire

### Migration
Table `customer_portal_session` créée dans `Version20260409000001`.
3. Backend résout le Stripe Price ID depuis la BDD (non exposé)
4. Backend crée/récupère le customer Stripe et crée une session Checkout
5. Frontend redirige vers `checkoutUrl`
6. Utilisateur effectue le paiement
7. Stripe redirige vers `success_url` ou `cancel_url`
8. Stripe envoie webhook `checkout.session.completed`
9. Backend assigne le plan au merchant et met à jour `subscription_status` à `active`
10. Frontend récupère le statut via `GET /api/merchant/me`

### Subscription Status
```
GET /api/subscription/status
```

Retourne le statut d'abonnement du merchant connecté avec les périodes Stripe.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "subscription_status": "active",
  "trial_ends_at": "2026-05-05T23:49:58Z",
  "current_period_start": "2026-04-05T23:49:58Z",
  "current_period_end": "2026-05-05T23:49:58Z",
  "plan": {
    "id": "a2ff47c7-b7e4-43ad-9a45-9bf87f5c6956",
    "slug": "standard",
    "name": "Standard",
    "price_monthly": 1900
  }
}
```

**Règles métier :**
- `trial_ends_at` reste toujours présent pour compatibilité future (A/B testing trial).
- `current_period_start` et `current_period_end` reflètent les timestamps Stripe convertis en UTC ISO 8601 (`...Z`).
- Source prioritaire : dates persistées sur `merchant.current_period_start_at` et `merchant.current_period_end_at`.
- Fallback : appel Stripe si une des dates persistées est absente.
- Sans subscription Stripe exploitable : `current_period_start = null` et `current_period_end = null`.
- En statut `canceling`, `current_period_end` correspond à la vraie date de fin d'accès payant Stripe.

### Permissions de la clé API Stripe

Utiliser une **clé restreinte** (`rk_live_...` / `rk_test_...`) avec uniquement ces permissions :

| Ressource | Permission |
|-----------|------------|
| Customers | Écriture |
| Checkout Sessions | Écriture |
| Subscriptions | Lecture |
| Invoices | Lecture |
| Customer portal | Écriture |
| Prices | Lecture |
| Events | Lecture |

Tout le reste → **Aucune**. Ne jamais utiliser la clé secrète complète (`sk_...`) en production.

### Test Mode

Cartes de test Stripe :

| Scénario | Numéro de carte | Date | CVC | ZIP |
|----------|----------------|------|-----|-----|
| **Paiement réussi** | `4242 4242 4242 4242` | N'importe quelle date future | N'importe lequel | N'importe lequel |
| **Authentification 3D Secure** | `4000 0025 0000 3155` | idem | idem | idem |
| **Carte refusée** | `4000 0000 0000 9995` | idem | idem | idem |

- Configurez les Stripe Price IDs dans `Plan.stripePriceId` via fixtures ou BDD

## Customers (Clients)

### List Customers
```
GET /api/customers
```

Retourne tous les clients du merchant courant (JWT merchant uniquement).

**Headers :**
- `Authorization: Bearer <token>` (requis)

**Sécurité :**
- ✅ Retourne SEULEMENT les customers appartenant au merchant du JWT
- ✅ Les customers apparaissent s'ils appartiennent directement au merchant
- ✅ Les customers historiques (via loyalty card/program du merchant) restent visibles
- ✅ Les paramètres query `?merchant=xyz` sont ignorés (force merchant du JWT)
- ✅ Pas de leakage de données multi-tenant

**Réponse :**
```json
[
  {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "+1234567890"
  }
]
```

**Erreurs :**
- `401` : Token JWT manquant ou invalide
- `404` : Utilisateur authentifié mais aucun merchant associé

### Get Customer
```
GET /api/customers/{id}
```

Retourne un client par son ID si le client appartient au merchant courant.

**Headers :**
- `Authorization: Bearer <token>` (requis)

**Paramètres :**
- `id` : ID du customer à récupérer

**Sécurité :**
- ✅ Retourne le customer SEULEMENT s'il appartient au merchant du JWT
- ✅ Retourne 404 si le customer n'appartient pas au merchant (pas de info leakage)
- ✅ Pas d'accès cross-merchant

**Réponse (succès) :**
```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+1234567890"
}
```

**Erreurs :**
- `401` : Token JWT manquant ou invalide
- `404` : Customer introuvable OU n'appartient pas au merchant courant

### Create Customer
```
POST /api/customers
```

Création manuelle désactivée. L'onboarding customer doit passer par OAuth Google customer avec `merchant_ref` (scan QR).

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "error": "manual_customer_creation_disabled",
  "message": "Customer signup is available only via Google auth with merchant_ref QR flow."
}
```

**Code HTTP :** `403`

### Customer Bootstrap (post-login customer)
```
GET /api/customers/me/bootstrap
```

Retourne le profil customer + user + merchants associés pour l'onboarding/dashboard.

**Headers :**
- `Authorization: Bearer <token>` (requis)

**Réponse :**
```json
{
  "user": {
    "id": 10,
    "email": "customer@example.com",
    "name": "Customer Name",
    "roles": ["ROLE_USER", "ROLE_CUSTOMER"]
  },
  "customer": {
    "id": 42,
    "name": "Customer Name",
    "email": "customer@example.com",
    "phone": null
  },
  "merchants": [
    {
      "id": "uuid-merchant",
      "company_name": "Shop A",
      "logo_url": null,
      "subscription_status": "active"
    }
  ]
}
```

**Erreurs :**
- `401` : `Unauthorized`
- `404` : `Customer not found for user`

### Customer Cards Multi-Merchant
```
GET /api/customers/me/cards
```

Retourne une liste **plate** des cartes du customer, chaque carte embarque son merchant.

**Headers :**
- `Authorization: Bearer <token>` (requis)

**Réponse :**
```json
[
  {
    "id": 101,
    "wallet_token": "uuid-wallet-token",
    "current_value": 12,
    "target_value": 50,
    "is_completed": false,
    "wallet_apple_url": "/public/wallet/apple/uuid-wallet-token",
    "wallet_google_url": "/public/wallet/google/uuid-wallet-token",
    "merchant": {
      "id": "uuid-merchant",
      "company_name": "Shop A",
      "logo_url": null
    },
    "loyalty_program": {
      "id": 3,
      "name": "Programme Points",
      "type": "points"
    }
  }
]
```

**Erreurs :**
- `401` : `Unauthorized`
- `404` : `Customer not found for user`

### Update Customer
```
PUT /api/customers/{id}
```

Met à jour un client si le client appartient au merchant courant.

**Headers :**
- `Authorization: Bearer <token>` (requis)

**Paramètres :**
- `id` : ID du customer à modifier

**Body :**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "phone": "+0987654321"
}
```

**Sécurité :**
- ✅ Modification autorisée SEULEMENT si le customer appartient au merchant du JWT
- ✅ Retourne 404 en cas d'accès non-autorisé (pas de info leakage)
- ✅ Pas de modification cross-merchant possible

**Réponse (succès) :**
```json
{
  "id": 1,
  "name": "Jane Doe",
  "email": "jane@example.com",
  "phone": "+0987654321"
}
```

**Erreurs :**
- `401` : Token JWT manquant ou invalide
- `404` : Customer introuvable OU n'appartient pas au merchant courant

### Delete Customer
```
DELETE /api/customers/{id}
```

Supprime un client si le client appartient au merchant courant.

**Headers :**
- `Authorization: Bearer <token>` (requis)

**Paramètres :**
- `id` : ID du customer à supprimer

**Sécurité :**
- ✅ Suppression autorisée SEULEMENT si le customer appartient au merchant du JWT
- ✅ Retourne 404 en cas d'accès non-autorisé (pas de info leakage)
- ✅ Pas de suppression cross-merchant possible

**Réponse (succès) :**
```json
{
  "success": true
}
```

**Erreurs :**
- `401` : Token JWT manquant ou invalide
- `404` : Customer introuvable OU n'appartient pas au merchant courant

## Error Handling

L'API retourne des erreurs au format JSON :

```json
{
  "error": "Description of the error"
}
```

Codes d'erreur courants :
- `400` : Bad Request (paramètres manquants ou invalides)
- `401` : Unauthorized (token manquant ou invalide)
- `404` : Not Found (ressource inexistante)
- `500` : Internal Server Error

## Security

### Authentification & Autorisation
- Toutes les requêtes (sauf authentification) nécessitent un JWT token valide
- Les ressources sont filtrées par utilisateur/commerçant pour la sécurité (multi-tenant)
- Guard `IsMerchantOwner` assure que seul le propriétaire peut accéder/modifier ses ressources

### Multi-Tenant Security (Customers)
- **GET /api/customers** : Retourne SEULEMENT les customers du merchant JWT
- **GET /api/customers/{id}** : Accès refusé (404) si customer ≠ merchant JWT
- **PUT /api/customers/{id}** : Modification refusée (404) si customer ≠ merchant JWT
- **DELETE /api/customers/{id}** : Suppression refusée (404) si customer ≠ merchant JWT
- Les paramètres query merchantId sont ignorés (force le JWT merchant)
- Réponse uniforme 404 ('not found' vs 'not authorized') pour éviter les info leaks

### CORS Configuration
- CORS est configuré pour permettre les requêtes depuis :
  - `http://localhost:5173` (développement frontend)
  - `http://coachat-bakend-loyalty-card.test` (développement)
- Refresh tokens persistés en BDD avec expiration

## Technologies Used

- **Symfony 7.4** : Framework PHP
- **API Platform** : API REST automatique
- **Doctrine ORM** : Gestion de base de données
- **Lexik JWT Authentication** : Authentification JWT
- **KnpU OAuth2 Client Bundle** : Intégration Google OAuth2
- **MySQL** : Base de données

## Database Schema

```
User (1) -- (1) Merchant
Merchant (1) -- (*) LoyaltyProgram
Merchant (1) -- (*) LoyaltyCard
Merchant (1) -- (*) Transaction
LoyaltyProgram (1) -- (*) LoyaltyCard
LoyaltyCard (1) -- (*) Transaction
Customer (1) -- (*) LoyaltyCard
User (1) -- (0..1) Customer
Customer (*) -- (*) Merchant (customer_merchants)
```

## Getting Started

1. Installer les dépendances : `composer install`
2. Configurer les variables d'environnement Google OAuth dans `.env`
3. Générer les clés JWT : `php bin/console lexik:jwt:generate-keypair`
4. Créer la base de données : `php bin/console doctrine:database:create`
5. Exécuter les migrations : `php bin/console doctrine:migrations:migrate`
6. Démarrer le serveur Symfony : `symfony server:start` ou `php -S localhost:8000 -t public`
7. L'API est accessible sur `http://localhost:8000/api` ou `http://coachat-bakend-loyalty-card.test/api`

## Entity Relationships

```
User (1) -- (1) Merchant
Merchant (1) -- (*) LoyaltyProgram
Merchant (1) -- (*) LoyaltyCard
Merchant (1) -- (*) Transaction
LoyaltyProgram (1) -- (*) LoyaltyCard
LoyaltyCard (1) -- (*) Transaction
Customer (1) -- (*) LoyaltyCard
User (1) -- (*) RefreshToken
```

## Enum Types

### LoyaltyProgram Type
- `STAMP` : Programme de timbres (cumulatif)
- `POINTS` : Programme de points

### Subscription Status
- `trial` : Période d'essai
- `active` : Actif et payant
- `canceling` : Annulation programmée à la fin de période
- `canceled` : Annulé
- `suspended` : Suspendu
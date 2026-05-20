# API Documentation - Loyalty Card System

## Overview

Cette API Symfony fournit un système complet de gestion de cartes de fidélité pour les commerçants. Elle inclut :

- Authentification via Google OAuth2 + JWT
- Gestion des commerçants, programmes de fidélité, cartes, transactions, clients
- Rewards (récompenses) réclamables via QR code
- Module Avis Google merchant/customer avec session de parcours, spin et récompense QR mono-usage
- Parcours customer authentifié (Google OAuth customer + dashboard multi-marchands)
- Dashboard customer via `GET /api/customers/me/bootstrap`, `POST /api/customers/me/merchants`, `GET /api/customers/me/cards`, `POST /api/customers/me/cards`, `GET /api/customers/me/rewards`, `GET /api/customers/me/notification-preferences`, `GET /api/customers/me/available-programs`
- Notifications client transactionnelles (email MVP, push préparé mais non implémenté)
- Audit des notifications client via `notification_log`

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
- super admin : `roles` contient `ROLE_SUPER_ADMIN`, qui hérite de `ROLE_MERCHANT`
- si un même compte cumule les deux parcours, le JWT peut contenir `ROLE_MERCHANT`, `ROLE_CUSTOMER`, `ROLE_USER`

**Consigne frontend :**
- pour router rapidement selon le rôle, décoder localement le claim `roles`
- ne pas attendre `id`, `name`, `customer` ou `merchant` dans le JWT
- après login :
  - merchant : appeler `GET /api/merchants/me`
  - customer : appeler `GET /api/customers/me/bootstrap`
  - super admin : le login passe par les mêmes endpoints merchant, puis appeler `GET /api/super-admin/me`

#### 1. Merchant Google Login
```
GET /api/auth/merchant/google/login?redirect_uri=<frontend_callback_url>
POST /api/auth/merchant/google/login/callback
```

Flow dédié à la connexion merchant existante.

**Cas super admin :** un super admin s'authentifie via les mêmes endpoints merchant, car `ROLE_SUPER_ADMIN` hérite de `ROLE_MERCHANT`. Le frontend admin doit ensuite consommer les endpoints `/api/super-admin/*`.

**Résolution du compte super admin au login :** le backend cherche d'abord un user super admin par `google_id`. Si aucun match n'existe encore, il cherche par `email`. Si un compte `ROLE_SUPER_ADMIN` est trouvé, aucun profil `Merchant` n'est requis pour autoriser la connexion.

**GET login :**
- Paramètre requis : `redirect_uri`

**Réponse GET :**
```json
{
  "redirectUrl": "https://accounts.google.com/oauth/authorize?...",
  "state": "random_state_string_for_csrf_protection"
}
```

**Body callback :**
```json
{
  "code": "authorization_code_from_google",
  "redirect_uri": "http://localhost:5173/auth/callback",
  "state": "optional_state_from_step_1"
}
```

**Réponse callback :**
```json
{
  "token": "jwt_token_here",
  "refresh_token": "refresh_token_here",
  "user": {
    "id": 1,
    "email": "merchant@example.com",
    "name": "Merchant Name"
  }
}
```

**Erreurs métier stables :**
- `400` : `redirect_uri required`
- `400` : `code and redirect_uri required`
- `403` : `merchant_not_found_for_login`
- `409` : `account_already_customer`
- `400` : `Authentication failed: ...`

#### 2. Merchant Google Register
```
GET /api/auth/merchant/google/register?redirect_uri=<frontend_callback_url>
POST /api/auth/merchant/google/register/callback
```

Flow dédié à l'authentification préalable à l'onboarding merchant. Il n'instancie pas le profil merchant métier, qui reste créé ensuite via `POST /api/merchants`.

**GET register :**
- Paramètre requis : `redirect_uri`

**Réponse GET :**
```json
{
  "redirectUrl": "https://accounts.google.com/oauth/authorize?...",
  "state": "random_state_string_for_csrf_protection"
}
```

**Body callback :**
```json
{
  "code": "authorization_code_from_google",
  "redirect_uri": "http://localhost:5173/auth/callback",
  "state": "optional_state_from_step_1"
}
```

**Réponse callback :** même format que le login merchant.

**Erreurs métier stables :**
- `400` : `redirect_uri required`
- `400` : `code and redirect_uri required`
- `409` : `account_already_customer`
- `409` : `account_already_merchant`
- `400` : `Authentication failed: ...`

#### 2.b Legacy Merchant Google Endpoints (compatibilité)
```
GET /api/auth/google?redirect_uri=<frontend_callback_url>
POST /api/auth/google/callback
```

Ces endpoints merchant historiques sont encore disponibles pour compatibilité de transition.

**Important :** privilégier les nouveaux endpoints :
- `GET /api/auth/merchant/google/login`
- `POST /api/auth/merchant/google/login/callback`
- `GET /api/auth/merchant/google/register`
- `POST /api/auth/merchant/google/register/callback`

#### 2.c Customer Google Login (QR / merchant_ref requis)
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
    "created_at": "2026-05-03T15:42:11+00:00",
    "merchant_ref": "uuid-merchant"
  }
}
```

`customer.created_at` est retourne au format ISO 8601 (`DATE_ATOM`).

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
- Crée une préférence de notification par défaut activée pour ce merchant
- En cas de nouveau rattachement customer ↔ merchant, envoie une notification de bienvenue avec lien vers le dashboard customer

#### 2.d Customer Google Login Direct (sans QR)
```
GET /api/auth/customer/google/login?redirect_uri=<frontend_callback_url>
POST /api/auth/customer/google/login/callback
```

Ce flow est dédié à la connexion d'un customer déjà existant, sans `merchant_ref`.

**Body callback :**
```json
{
  "code": "authorization_code_from_google",
  "state": "optional_state_from_step_1",
  "redirect_uri": "http://localhost:5173/auth/customer/callback"
}
```

**Réponse callback :**
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
    "created_at": "2026-05-03T15:42:11+00:00"
  }
}
```

`customer.created_at` est retourne au format ISO 8601 (`DATE_ATOM`).

**Erreurs métier stables :**
- `400` : `redirect_uri required`
- `400` : `code and redirect_uri required`
- `403` : `customer_not_found`
- `400` : `Authentication failed: ...`

#### 2.e Form-based signup & login (mot de passe)

Endpoints d'inscription et de connexion par formulaire (email + mot de passe). Le login form est **unifié** depuis mai 2026 : il accepte tous les rôles (merchant, customer, super-admin) sur le **même endpoint**. L'ancien `POST /api/auth/customer/login-form` est supprimé.

##### Inscription merchant
```
POST /api/auth/merchant/register-form
```

**Body :**
```json
{
  "name": "Acme SARL",
  "email": "contact@acme.fr",
  "password": "********"
}
```

**Réponse** : même format que le login merchant (token JWT + user). Le compte est créé avec `email_verified=false` et un email de vérification dédié est envoyé.

**Erreurs stables :**
- `422` : `missing_required_fields`, `email_invalid`, `password_too_short`
- `409` : `account_already_exists`, `account_already_customer`, `account_exists_with_google`

##### Inscription customer
```
POST /api/auth/customer/register-form
```

**Body :**
```json
{
  "name": "Léa Côté",
  "email": "lea@example.com",
  "password": "********",
  "merchant_ref": "<merchant_uuid>",
  "accepted_terms": true,
  "accepted_terms_version": "v1",
  "accepted_terms_accepted_at": "2026-05-19T12:00:00+00:00"
}
```

**Réponse** : même format que le callback customer Google (token + user + customer). Le compte est créé avec `email_verified=false`. **Un seul email** est envoyé : le mail de bienvenue qui embarque un lien de confirmation d'adresse (CTA "Confirmer mon adresse email").

**Erreurs stables :**
- `422` : `missing_required_fields`, `email_invalid`, `password_too_short`, `accepted_terms_required`, `merchant_ref_invalid`, `merchant_ref_inactive`
- `409` : `account_already_exists`, `account_already_merchant`, `account_already_customer`, `account_exists_with_google`, `customer_limit_reached`

##### Login unifié (merchant + customer + super-admin)
```
POST /api/auth/merchant/login-form
```

Endpoint **central** pour tous les logins par mot de passe. Le routage rôle se fait côté serveur en fonction du profil lié au user. L'ancien `POST /api/auth/customer/login-form` a été **supprimé** ; les anciens clients qui scannent un QR avec un compte existant peuvent passer ici en transmettant un `merchant_ref` optionnel.

**Body :**
```json
{
  "email": "user@example.com",
  "password": "********",
  "merchant_ref": "<merchant_uuid>"
}
```

- `merchant_ref` est **optionnel**. S'il est fourni et que le user est résolu comme un customer, le customer est lié au merchant (équivalent à un signup par QR pour un compte existant).
- `merchant_ref` est **ignoré** si le user est un merchant ou un super-admin.

**Comportement :**
1. Si le user est super-admin ou merchant linked → réponse merchant standard (`buildAuthSuccessResponse`).
2. Sinon, si le user a un `Customer` accessible → ajoute `ROLE_CUSTOMER`, applique le linking `merchant_ref` si fourni, renvoie la réponse customer (`buildCustomerAuthSuccessResponse`) avec un payload `customer.{id,email,name,merchant_ref}`.
3. Sinon → `403 account_not_linked`.

**Réponse merchant :**
```json
{
  "token": "jwt_token_here",
  "refresh_token": "refresh_token_here",
  "user": { "id": 1, "email": "...", "name": "...", "email_verified": true },
  "merchant_context": { "id": "...", "company_name": "..." }
}
```

**Réponse customer :** identique au callback Google customer (cf. §2.c), avec un objet `customer` au lieu de `merchant_context`.

**Erreurs stables :**
- `422` : `missing_required_fields`, `merchant_ref_invalid`, `merchant_ref_inactive`
- `401` : `invalid_credentials`
- `403` : `account_not_linked` (nouvelle erreur ; remplace l'ancien `account_already_customer` côté merchant login et `customer_not_found` côté customer login)
- `409` : `use_google_login`, `customer_limit_reached` (si linking `merchant_ref` dépasse le plafond du merchant)

**Notes de migration :**
- Tous les anciens consommateurs de `POST /api/auth/customer/login-form` doivent passer à `POST /api/auth/merchant/login-form` en transmettant `merchant_ref` si nécessaire.
- Les QR codes en production encodent toujours `/?customer_signup=1&merchant_ref=<id>` et redirigent vers `/customer/signup` côté frontend ; aucune régression QR.

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

## Super Admin

Contexte : première brique du dashboard d'administration du site.

Le super admin s'authentifie via les mêmes endpoints Google merchant que les merchants classiques :
- `GET /api/auth/merchant/google/login`
- `POST /api/auth/merchant/google/login/callback`

La différence se fait ensuite via le rôle `ROLE_SUPER_ADMIN`, qui hérite de `ROLE_MERCHANT`.

Un super admin n'a pas besoin d'être lié à une entité `Merchant`. Il peut être créé directement en base avec `ROLE_SUPER_ADMIN`, puis être rattaché à son compte Google au premier login via l'email. Le champ `google_id` peut donc être vide avant la première connexion Google.

En V1, les endpoints super admin sont strictement en lecture seule.

### Get Current Super Admin
```
GET /api/super-admin/me
```

Retourne le profil du super admin authentifié. Le champ `merchant` peut être `null` si le super admin n'est lié à aucun merchant, ce qui est le comportement nominal pour l'administration du site.

**Headers :**
- `Authorization: Bearer <token>`

**Accès :**
- `ROLE_SUPER_ADMIN`

**Réponse :**
```json
{
  "id": 12,
  "email": "admin@example.com",
  "name": "Admin Site",
  "google_id": null,
  "roles": ["ROLE_USER", "ROLE_SUPER_ADMIN"],
  "is_super_admin": true,
  "merchant": null
}
```

Exemple si le super admin est aussi rattaché à un merchant :
```json
{
  "id": 12,
  "email": "admin@example.com",
  "name": "Admin Site",
  "google_id": "google-user-id",
  "roles": ["ROLE_USER", "ROLE_SUPER_ADMIN"],
  "is_super_admin": true,
  "merchant": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "company_name": "Admin Merchant",
    "email": "admin@example.com",
    "phone": null,
    "address": null,
    "postal_code": "75000",
    "city": "Paris",
    "logo_url": null,
    "stripe_customer_id": null,
    "trial_ends_at": "2026-05-15T10:15:00Z",
    "current_period_start_at": null,
    "current_period_end_at": null,
    "accepted_terms": true,
    "accepted_terms_version": "2026-04-15",
    "accepted_terms_accepted_at": "2026-04-15T10:15:00Z",
    "subscription_status": "trial",
    "active_loyalty_program_count": 0,
    "loyalty_program_count": 0,
    "loyalty_card_count": 0,
    "transaction_count": 0,
    "reward_count": 0,
    "plan": null,
    "user": {
      "id": 12,
      "email": "admin@example.com",
      "name": "Admin Site",
      "roles": ["ROLE_USER", "ROLE_SUPER_ADMIN"]
    }
  }
}
```

**Erreurs :**
- `403` si l'utilisateur n'a pas `ROLE_SUPER_ADMIN`

### List All Merchants For Super Admin
```
GET /api/super-admin/merchants
```

Retourne tous les merchants et leurs données principales en lecture seule.

**Headers :**
- `Authorization: Bearer <token>`

**Accès :**
- `ROLE_SUPER_ADMIN`

**Réponse :**
```json
{
  "items": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "company_name": "Alpha Shop",
      "email": "alpha@example.com",
      "phone": null,
      "address": null,
      "postal_code": "75000",
      "city": "Paris",
      "logo_url": null,
      "stripe_customer_id": null,
      "trial_ends_at": "2026-05-15T10:15:00Z",
      "current_period_start_at": null,
      "current_period_end_at": null,
      "accepted_terms": true,
      "accepted_terms_version": "2026-04-15",
      "accepted_terms_accepted_at": "2026-04-15T10:15:00Z",
      "subscription_status": "trial",
      "active_loyalty_program_count": 0,
      "loyalty_program_count": 0,
      "loyalty_card_count": 0,
      "transaction_count": 0,
      "reward_count": 0,
      "plan": null,
      "user": {
        "id": 18,
        "email": "alpha@example.com",
        "name": "Owner Alpha",
        "roles": ["ROLE_USER", "ROLE_MERCHANT"]
      }
    }
  ],
  "total": 1
}
```

**Notes V1 :**
- pas de pagination en première version
- pas d'écriture ni d'édition via ces routes
- la liste est ordonnée par `company_name ASC`

### Get Merchant Loyalty Programs For Super Admin
```
GET /api/super-admin/merchants/{merchantId}/loyalty-programs
```

Retourne les programmes de fidélité d'un merchant tiers pour le dashboard admin, avec les informations utiles au présentoir fidélité.

**Headers :**
- `Authorization: Bearer <token>`

**Accès :**
- `ROLE_SUPER_ADMIN`

**Réponse :**
```json
{
  "merchant": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "company_name": "Alpha Shop",
    "logo_url": null,
    "city": "Paris",
    "subscription_status": "trial",
    "merchant_ref": "550e8400-e29b-41d4-a716-446655440000",
    "customer_signup_qr_value": "550e8400-e29b-41d4-a716-446655440000"
  },
  "items": [
    {
      "id": 1,
      "name": "Coffee Program",
      "description": null,
      "type": "STAMP",
      "points_per_euro": null,
      "points_target": null,
      "stamp_target": 10,
      "reward_description": "1 cafe offert",
      "is_active": true,
      "loyalty_card_count": 0,
      "reward_count": 0
    }
  ],
  "total": 1
}
```

**Notes V1 :**
- `merchant_ref` et `customer_signup_qr_value` correspondent à l'UUID merchant à encoder dans le QR du parcours customer
- pas de pagination en première version
- liste ordonnée par `name ASC`

**Erreurs :**
- `404` : `merchant_not_found`
- `403` si l'utilisateur n'a pas `ROLE_SUPER_ADMIN`

### Get Merchant Google Review Module For Super Admin
```
GET /api/super-admin/merchants/{merchantId}/google-review-module
```

Retourne la configuration du module Avis Google d'un merchant tiers pour le dashboard admin.

**Headers :**
- `Authorization: Bearer <token>`

**Accès :**
- `ROLE_SUPER_ADMIN`

**Réponse :**
```json
{
  "id": "uuid-module",
  "merchant_id": "uuid-merchant",
  "merchant_name": "Review Shop",
  "merchant_logo_url": null,
  "is_enabled": true,
  "display_name": "Avis Google",
  "google_review_url": "https://g.page/r/review-shop/review",
  "show_in_customer_dashboard": true,
  "show_qr_code": true,
  "reward_options": [
    {
      "id": "uuid-option",
      "label": "Cafe offert",
      "description": null,
      "active": true,
      "order": 1
    }
  ],
  "is_configuration_complete": true,
  "created_at": "2026-04-19T12:00:00+00:00",
  "updated_at": "2026-04-19T12:00:00+00:00"
}
```

**Erreurs :**
- `404` : `merchant_not_found`
- `404` : `google_review_module_not_found`
- `403` si l'utilisateur n'a pas `ROLE_SUPER_ADMIN`


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

### List Merchant Staff (Equipiers)
```
GET /api/merchants/me/staff
```

Retourne la liste des clients équipiers rattachés au commerçant connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
[
  {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "name": "Jean Dupont",
    "email": "jean.dupont@example.com",
    "phone": "06 11 22 33 44",
    "is_equipier": true,
    "is_merchant_admin": false,
    "is_owner": false,
    "owner_user_id": 12,
    "equipier_merchant_id": "550e8400-e29b-41d4-a716-446655440000",
    "equipier_assigned_at": "2026-05-02T09:15:00+00:00",
    "roles": ["ROLE_CUSTOMER", "ROLE_EQUIPIER"]
  }
]
```

**Erreurs :**
- `404 Merchant not found` si aucun merchant n'est résolu pour l'utilisateur

### Assign Merchant Staff (Equipier)
```
POST /api/merchants/me/staff/{customerId}
```

Assigne un client du merchant connecté comme équipier.

**Headers :**
- `Authorization: Bearer <token>`

**Path params :**
- `customerId` : identifiant du client à promouvoir équipier

**Réponse :**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440001",
  "name": "Jean Dupont",
  "email": "jean.dupont@example.com",
  "phone": "06 11 22 33 44",
  "is_equipier": true,
  "is_merchant_admin": false,
  "is_owner": false,
  "owner_user_id": 12,
  "equipier_merchant_id": "550e8400-e29b-41d4-a716-446655440000",
  "equipier_assigned_at": "2026-05-02T09:15:00+00:00",
  "roles": ["ROLE_CUSTOMER", "ROLE_EQUIPIER"]
}
```

**Comportement :**
- Ajoute le rôle `ROLE_EQUIPIER` à l'utilisateur du client (et conserve `ROLE_CUSTOMER`)
- Envoie une notification d'assignation équipier

**Erreurs :**
- `403 Forbidden` si l'utilisateur n'est pas admin merchant (`ROLE_MERCHANT`) pour ce merchant
- `404 Customer not found` si le client n'existe pas dans le merchant courant
- `409 customer_user_required` si le client n'est lié à aucun compte utilisateur
- `409 customer_already_staff_for_other_merchant` si le client est déjà équipier d'un autre merchant

### Unassign Merchant Staff (Equipier)
```
DELETE /api/merchants/me/staff/{customerId}
```

Retire le statut équipier d'un client du merchant connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Path params :**
- `customerId` : identifiant du client équipier à retirer

**Réponse :**
```json
{
  "success": true
}
```

**Comportement :**
- Retire le rôle `ROLE_EQUIPIER` de l'utilisateur lié au client
- Retire également le rôle `ROLE_MERCHANT` si présent
- Envoie une notification de retrait équipier

**Erreurs :**
- `403 Forbidden` si l'utilisateur n'est pas admin merchant (`ROLE_MERCHANT`) pour ce merchant
- `404 Staff customer not found` si le client n'est pas équipier de ce merchant
- `409 owner_role_change_forbidden` si la cible est l'owner courant
- `409 merchant_admin_minimum_required` si le retrait ferait tomber le merchant à 0 admin

### List Merchant Admin Staff (hors utilisateur courant)
```
GET /api/merchants/me/staff/merchant-admins
```

Retourne la liste des customers staff ayant le rôle `ROLE_MERCHANT`, en excluant l'utilisateur connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
[
  {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "name": "Jean Dupont",
    "email": "jean.dupont@example.com",
    "phone": "06 11 22 33 44",
    "is_equipier": true,
    "is_merchant_admin": true,
    "is_owner": false,
    "owner_user_id": 12,
    "equipier_merchant_id": "550e8400-e29b-41d4-a716-446655440000",
    "equipier_assigned_at": "2026-05-02T09:15:00+00:00",
    "roles": ["ROLE_CUSTOMER", "ROLE_MERCHANT"]
  }
]
```

**Erreurs :**
- `403 Forbidden` si l'utilisateur n'est pas admin merchant (`ROLE_MERCHANT`) pour ce merchant

### Promote Equipier To Merchant Role
```
POST /api/merchants/me/staff/{customerId}/merchant-role
```

Promeut un équipier en admin merchant.

**Headers :**
- `Authorization: Bearer <token>`

**Path params :**
- `customerId` : identifiant du customer staff à promouvoir

**Réponse :**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440001",
  "name": "Jean Dupont",
  "email": "jean.dupont@example.com",
  "phone": "06 11 22 33 44",
  "is_equipier": true,
  "is_merchant_admin": true,
  "is_owner": false,
  "owner_user_id": 12,
  "equipier_merchant_id": "550e8400-e29b-41d4-a716-446655440000",
  "equipier_assigned_at": "2026-05-02T09:15:00+00:00",
  "roles": ["ROLE_CUSTOMER", "ROLE_MERCHANT"]
}
```

**Comportement :**
- L'utilisateur ciblé doit déjà être équipier du merchant
- Remplace `ROLE_EQUIPIER` par `ROLE_MERCHANT`

**Erreurs :**
- `403 Forbidden` si l'utilisateur n'est pas admin merchant (`ROLE_MERCHANT`) pour ce merchant
- `404 Customer not found` si le client n'existe pas dans le merchant courant
- `409 customer_not_equipier_for_merchant` si le customer n'est pas staff du merchant
- `409 customer_user_required` si le client n'est lié à aucun compte utilisateur
- `409 customer_not_equipier_role` si l'utilisateur n'a pas `ROLE_EQUIPIER`

### Demote Merchant Role To Equipier
```
POST /api/merchants/me/staff/{customerId}/equipier-role
```

Rétrograde un admin merchant staff vers équipier.

**Headers :**
- `Authorization: Bearer <token>`

**Path params :**
- `customerId` : identifiant du customer staff à rétrograder

**Réponse :**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440001",
  "name": "Jean Dupont",
  "email": "jean.dupont@example.com",
  "phone": "06 11 22 33 44",
  "is_equipier": true,
  "is_merchant_admin": false,
  "is_owner": false,
  "owner_user_id": 12,
  "equipier_merchant_id": "550e8400-e29b-41d4-a716-446655440000",
  "equipier_assigned_at": "2026-05-02T09:15:00+00:00",
  "roles": ["ROLE_CUSTOMER", "ROLE_EQUIPIER"]
}
```

**Comportement :**
- Remplace `ROLE_MERCHANT` par `ROLE_EQUIPIER`
- Empêche toute opération qui ferait tomber le merchant à 0 admin

**Erreurs :**
- `403 Forbidden` si l'utilisateur n'est pas admin merchant (`ROLE_MERCHANT`) pour ce merchant
- `404 Staff customer not found` si le client n'est pas équipier de ce merchant
- `409 customer_user_required` si le client n'est lié à aucun compte utilisateur
- `409 customer_not_merchant_admin` si l'utilisateur ciblé n'a pas `ROLE_MERCHANT`
- `409 owner_role_change_forbidden` si la cible est l'owner courant
- `409 merchant_admin_self_downgrade_forbidden` si tentative de downgrade sur soi-même
- `409 merchant_admin_minimum_required` si le downgrade ferait tomber le merchant à 0 admin

### Transfer Merchant Ownership
```
POST /api/merchants/me/staff/{customerId}/transfer-ownership
```

Transfère la propriété du merchant courant vers un admin merchant staff existant.

**Headers :**
- `Authorization: Bearer <token>`

**Path params :**
- `customerId` : identifiant du customer staff admin à promouvoir owner

**Comportement :**
- Action réservée à l'owner courant uniquement
- La cible doit déjà être staff du merchant et déjà `ROLE_MERCHANT`
- Le lien owner direct `merchant.user` est transféré vers la cible
- La cible est retirée de la relation staff (plus équipier)
- L'ancien owner perd `ROLE_MERCHANT` (sauf super admin)

**Réponse :**
```json
{
  "success": true,
  "merchant_id": "550e8400-e29b-41d4-a716-446655440000",
  "previous_owner_user_id": 12,
  "new_owner": {
    "id": 45,
    "name": "Jean Dupont",
    "email": "jean.dupont@example.com",
    "phone": "06 11 22 33 44",
    "is_equipier": false,
    "is_merchant_admin": true,
    "is_owner": true,
    "owner_user_id": 99,
    "equipier_merchant_id": null,
    "equipier_assigned_at": null,
    "roles": ["ROLE_CUSTOMER", "ROLE_MERCHANT"]
  }
}
```

**Erreurs :**
- `403 owner_only_action` si l'utilisateur courant n'est pas owner
- `404 Staff customer not found` si la cible n'est pas staff du merchant
- `409 customer_user_required` si la cible n'est liée à aucun compte
- `409 customer_not_merchant_admin` si la cible n'a pas `ROLE_MERCHANT`
- `409 owner_already_current` si la cible est déjà l'owner
- `409 target_already_owner_for_other_merchant` si la cible possède déjà un autre merchant

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

**Règles métier de mise à jour (anti-régression cartes existantes) :**
- Champs autorisés : `name`, `description`, `reward_description`
- Champs ignorés même s'ils sont envoyés : `type`, `stamp_target`, `points_target`, `points_per_euro`, `is_active`

**Exemple body autorisé :**
```json
{
  "name": "Programme Café Matin",
  "description": "Valable du lundi au vendredi",
  "reward_description": "1 boisson offerte"
}
```

**Note :** cette restriction protège la cohérence des cartes déjà en cours ou complétées.

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

### Get One Loyalty Card
```
GET /api/loyalty_cards/{id}
```

Retourne une carte de fidélité unique du merchant connecté.

**Headers :**
- `Authorization: Bearer <token>`

**Paramètres :**
- `id` : identifiant integer de la carte

**Réponse :** même format qu'un item de `GET /api/loyalty_cards?merchant=...`

**Erreurs :**
- `401` : `Unauthorized`
- `404` : `Card not found`

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

**Comportement reward (important) :**
- Si `is_completed` passe de `false` à `true` et qu'un customer est rattaché à la carte, une reward est créée automatiquement.
- Le comportement est idempotent (pas de duplication de reward pour une même carte).

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

### Get Transactions By Card
```
GET /api/transactions/by-card?merchant_id={merchant_id}&card_id={card_id}
```

Retourne les transactions d'une carte pour un commerçant donne.

**Headers :**
- `Authorization: Bearer <token>`

**Parametres :**
- `merchant_id` (requis) : ID UUID du commerçant
- `card_id` (requis) : ID numerique de la carte

**Reponse :**
```json
[
  {
    "id": 1,
    "points_earned": 10,
    "points_redeemed": 0,
    "created_at": "2026-04-03 10:00:00",
    "loyalty_card": {
      "id": 19,
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

**Notifications liées aux rewards :**
- `card_completed` : envoyée quand une carte passe à l'état complété et qu'une reward est générée
- `reward_claimed` : envoyée après un claim réussi par QR

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
- JWT merchant requis (pas de lookup public).
- Retour strictement limité au merchant connecté.
- Si `merchant` est fourni et ne correspond pas au merchant du JWT : `403 Forbidden`.
- Sans JWT valide : `401 Unauthorized`.

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

**Note produit :** cet endpoint est technique. La notification customer `card_completed` est déclenchée sur les flows qui complètent réellement la carte (`POST /api/transactions` et `PATCH /api/loyalty_cards/{id}`), pas sur cet endpoint appelé isolément.

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
- Déclenche ensuite une notification `reward_claimed` au customer si ses notifications sont activées pour ce merchant.

**Contenu fonctionnel de la notification `reward_claimed` :**
- message de félicitations pour la récompense récupérée
- invitation à créer une nouvelle carte depuis le dashboard customer
- alternative explicite : retourner directement chez le commerçant partenaire

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

## Decommission Legacy Claim/Public Portal

Le module public legacy de claim customer a été retiré du backend.

**Supprimé :**
- `GET /api/loyalty_cards/by-token/{walletToken}`
- `POST /api/public/customer-portal/bootstrap`
- `GET /api/public/customer-portal/overview`
- `POST /api/public/customer-portal/refresh`
- `POST /api/public/customer-portal/revoke`
- les codes métier `PORTAL_TOKEN_*` et `CLAIM_WALLET_TOKEN_INVALID`

**Parcours supporté :**
- customer authentifié uniquement via OAuth Google customer
- bootstrap dashboard via `GET /api/customers/me/bootstrap`
- cartes customer via `GET /api/customers/me/cards`
- rewards customer via `GET /api/customers/me/rewards`

---

## Carte publique – Découverte des marchands (sans auth)

Ces endpoints sont **publics** (aucun JWT requis). Ils alimentent la landing page statique (`index.html`) et la carte interactive customer (`/app/map`).

> **Note suppression showcase** : L'endpoint `GET /api/public/merchants/showcase` a été **supprimé**. La découverte se fait exclusivement via la carte interactive et la recherche.

### Map publique – Chargement des pins
```
GET /api/public/merchants/map
```

Retourne les marchands visibles dans une zone géographique (bounds).

**Query params :**
- `north`, `south`, `east`, `west` (float) — bounding box de la carte
- `zoom` (int, optionnel) — niveau de zoom courant

**Réponse :**
```json
{
  "merchants": [
    {
      "id": "uuid",
      "company_name": "Ma Boulangerie",
      "latitude": 47.902,
      "longitude": 1.909,
      "has_active_promotional_offers": true,
      "has_loyalty_programs": true
    }
  ]
}
```

### Recherche publique de marchands
```
GET /api/public/merchants/search?q=&limit=&lat=&lng=
```

Recherche texte sur les marchands. Côté frontend, ce call est combiné avec un appel à `api-adresse.data.gouv.fr` (BAN – Base Adresse Nationale) pour les résultats d'adresse. **Ne pas utiliser Nominatim/OpenStreetMap** pour le géocodage.

**Query params :**
- `q` (string, requis, min 3 chars) — terme de recherche
- `limit` (int, défaut 5) — nombre max de résultats
- `lat`, `lng` (float, optionnel) — position de l'utilisateur pour trier par proximité

**Rate limiting :** `429 Too Many Requests` si le seuil est dépassé. Le frontend affiche un message "Trop de requêtes, réessayez dans une minute." et ne relance pas automatiquement.

**Réponse :**
```json
{
  "merchants": [
    {
      "id": "uuid",
      "company_name": "Coachat Test",
      "address": "12 rue de la Paix",
      "postal_code": "45000",
      "city": "Orléans",
      "latitude": 47.902,
      "longitude": 1.909,
      "has_active_promotional_offers": false,
      "has_loyalty_programs": true
    }
  ]
}
```

### Détail d'un marchand public
```
GET /api/public/merchants/{id}
```

Retourne le profil complet d'un marchand pour le bottom sheet de la carte.

**Réponse :**
```json
{
  "id": "uuid",
  "company_name": "Coachat Test",
  "address": "12 rue de la Paix",
  "postal_code": "45000",
  "city": "Orléans",
  "phone": "...",
  "website": "...",
  "latitude": 47.902,
  "longitude": 1.909,
  "loyalty_programs": [...],
  "active_promotional_offers": [...]
}
```

### Comportement de la carte publique (index.html)

- **Centrage par défaut** : Orléans (`lat: 47.9029, lng: 1.9092`, zoom 13) — indépendamment de la géolocalisation.
- **Géolocalisation** : utilisée uniquement pour enrichir les appels search (`lat`/`lng` params) pour le tri par proximité. Elle ne recentre **pas** la carte.
- **Recherche** : double appel parallèle — marchands (backend) + adresses (BAN `api-adresse.data.gouv.fr`). Résultats fusionnés, max 8.
- **Clic résultat de recherche** : `flyTo` + surlignage du pin uniquement. Le bottom sheet marchand s'ouvre **uniquement au clic sur un pin**.
- **Aucun résultat** : affiche un CTA "Vous êtes commerçant ? Inscrivez votre établissement ici" pointant vers `/register`.

---

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
    "phone": "+1234567890",
    "created_at": "2026-05-03T15:42:11+00:00"
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
  "phone": "+1234567890",
  "created_at": "2026-05-03T15:42:11+00:00"
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
    "phone": null,
    "created_at": "2026-05-03T15:42:11+00:00"
  },
  "merchants": [
    {
      "id": "uuid-merchant",
      "company_name": "Shop A",
      "logo_url": null,
      "subscription_status": "active",
      "notifications": {
        "enabled": true,
        "available_channels": {
          "email": true,
          "push": false
        },
        "updated_at": "2026-04-16T10:15:00+00:00"
      }
    }
  ]
}
```

`customer.created_at` est retourne au format ISO 8601 (`DATE_ATOM`).

**Erreurs :**
- `401` : `Unauthorized`
- `404` : `Customer not found for user`

### Customer Auto-Link Merchant (QR post-login)
```
POST /api/customers/me/merchants
```

Permet a un customer deja authentifie de se lier a un nouveau merchant sans repasser par OAuth Google.

**Headers :**
- `Authorization: Bearer <token>` (requis, token customer)
- `Content-Type: application/json`

**Body :**
```json
{
  "merchant_ref": "merchant-uuid"
}
```

**Reponse 200 :**
```json
{
  "success": true,
  "merchant": {
    "id": "merchant-uuid",
    "company_name": "Shop A"
  }
}
```

**Comportement :**
- endpoint idempotent: si le customer est deja lie au merchant, retourne quand meme `200`
- reutilise la meme logique de liaison customer ↔ merchant que `POST /api/auth/customer/google/callback`
- active/garantit la relation customer ↔ merchant (table `customer_merchants`)
- rend les programmes du merchant visibles immediatement dans `GET /api/customers/me/available-programs`
- ne cree pas automatiquement de loyalty card

**Erreurs metier stables :**
- `400` : `merchant_ref_missing`
- `401` : `Unauthorized`
- `401` : `Customer not found for user`
- `404` : `merchant_not_found`

### Customer Notification Preferences
```
GET /api/customers/me/notification-preferences
PATCH /api/customers/me/notification-preferences/{merchantId}
```

Permet au customer de gérer l'opt-in de notifications merchant par merchant.

**Règles :**
- préférence globale par merchant
- préférence spécifique bons plans par merchant
- par défaut `enabled = true` à l'inscription customer via QR
- par défaut `promotional_offers_enabled = true` à l'inscription customer via QR
- `email` est toujours disponible
- `push` dépend du plan merchant (`plan.has_push_notifications`)
- pas encore de granularité séparée email/push côté customer

**Types de notifications actuellement déclenchés côté customer :**
- `customer_signup` : bienvenue après inscription / premier rattachement au merchant
- `card_created` : carte créée et prête à être scannée
- `card_completed` : carte complétée, récompense prête à être récupérée
- `points_added` : points ou tampons ajoutés
- `reward_claimed` : récompense récupérée
- `promotional_offer_starts` : bon plan qui démarre aujourd'hui
- `promotional_offer_ending_soon` : rappel à J-2 avant fin du bon plan

**Canal effectif MVP :**
- si `plan.has_push_notifications = false` : email
- si `plan.has_push_notifications = true` : canal push sélectionné
- le push est préparé mais non implémenté dans ce MVP, donc l'envoi est journalisé en échec tant qu'aucun provider n'est branché

**Audit des envois :**
- chaque tentative crée une entrée dans `notification_log`
- statuts : `pending`, `sent`, `failed`
- données clés : `merchant_id`, `recipient_email`, `type`, `subject`, `error_message`, `created_at`, `sent_at`

**Réponse GET :**
```json
[
  {
    "merchant": {
      "id": "uuid-merchant",
      "company_name": "Shop A",
      "logo_url": null,
      "subscription_status": "active"
    },
    "notifications": {
      "enabled": true,
      "promotional_offers_enabled": true,
      "available_channels": {
        "email": true,
        "push": false
      },
      "updated_at": "2026-04-16T10:15:00+00:00"
    }
  }
]
```

**Body PATCH :**
```json
{
  "enabled": false,
  "promotional_offers_enabled": true
}
```

**Réponse PATCH :**
```json
{
  "merchant": {
    "id": "uuid-merchant",
    "company_name": "Shop A",
    "logo_url": null,
    "subscription_status": "active"
  },
  "notifications": {
    "enabled": false,
    "promotional_offers_enabled": true,
    "available_channels": {
      "email": true,
      "push": false
    },
    "updated_at": "2026-04-16T10:20:00+00:00"
  }
}
```

**Erreurs :**
- `401` : `Unauthorized`
- `404` : `Customer not found for user`
- `404` : `Merchant not found for customer`
- `400` : `enabled must be a boolean`
- `400` : `promotional_offers_enabled must be a boolean`
- `400` : `at_least_one_preference_required`

### Merchant Promotional Offers (Bons plans)
```
GET /api/merchants/me/promotional-offers
POST /api/merchants/me/promotional-offers
PUT /api/merchants/me/promotional-offers/{id}
```

Permet au merchant owner de créer des bons plans datés (titre + description + période), de les lister (en cours / à venir / historique) et de les modifier uniquement avant la date de début.

**Règles métier :**
- `starts_on` et `ends_on` format `YYYY-MM-DD`
- `ends_on >= starts_on`
- modification autorisée uniquement si la date du jour est strictement avant `starts_on`

**Réponse GET :**
```json
{
  "items": [
    {
      "id": 12,
      "merchant_id": "uuid-merchant",
      "title": "Bon plan du mois",
      "description": "-20% sur la gamme cafe",
      "starts_on": "2026-05-10",
      "ends_on": "2026-05-31",
      "status": "upcoming",
      "is_editable": true,
      "start_notification_sent_at": null,
      "ending_soon_notification_sent_at": null,
      "created_at": "2026-05-09T09:02:10+00:00",
      "updated_at": "2026-05-09T09:02:10+00:00"
    }
  ],
  "summary": {
    "has_any": true,
    "has_active": false,
    "has_upcoming": true
  }
}
```

**Body POST / PUT :**
```json
{
  "title": "Bon plan de la semaine",
  "description": "2 menus achetés = 1 dessert offert",
  "starts_on": "2026-05-12",
  "ends_on": "2026-05-19"
}
```

**Erreurs métier stables :**
- `422` : `title_required`
- `422` : `title_too_long`
- `422` : `description_required`
- `422` : `starts_on_invalid`
- `422` : `ends_on_invalid`
- `422` : `date_range_invalid`
- `409` : `promotional_offer_not_editable_after_start_date`

### Promotional Offers Daily Dispatch
```
GET /api/promotional-offers/daily-dispatch
POST /api/promotional-offers/daily-dispatch
Header: X-Cron-Token: <PROMOTIONAL_OFFERS_CRON_TOKEN>
```

Endpoint prévu pour un appel quotidien (ex: 08:00) quand l'environnement ne permet pas de lancer une commande CLI.

Si votre provider cron ne supporte pas POST, utilisez GET avec `?token=<PROMOTIONAL_OFFERS_CRON_TOKEN>`.

#### Authentification supportée (configurable par variables d'environnement)

- couche 1 (obligatoire): token applicatif
  - env: `PROMOTIONAL_OFFERS_CRON_TOKEN`
  - transport: header `X-Cron-Token`, ou query `token`, ou body JSON `{ "token": "..." }`

- couche 2 (optionnelle): Basic Auth HTTP
  - env: `PROMOTIONAL_OFFERS_CRON_BASIC_USER`
  - env: `PROMOTIONAL_OFFERS_CRON_BASIC_PASSWORD`
  - activée seulement si user/password sont définis

- couche 3 (optionnelle): en-tête personnalisé
  - env: `PROMOTIONAL_OFFERS_CRON_HEADER_NAME`
  - env: `PROMOTIONAL_OFFERS_CRON_HEADER_VALUE`
  - activée seulement si nom + valeur sont définis

**Codes d'erreur sécurité :**
- `401` : `unauthorized` (token invalide/absent)
- `401` : `unauthorized_basic_auth` (Basic Auth invalide/absente alors qu'activée)
- `401` : `unauthorized_custom_header` (en-tête invalide/absent alors qu'activé)
- `503` : `promotional_offer_cron_token_not_configured`

#### Exemple `.env` (recommandé)
```dotenv
PROMOTIONAL_OFFERS_CRON_TOKEN=change-me-very-long-random-token
PROMOTIONAL_OFFERS_CRON_BASIC_USER=cron_dispatch
PROMOTIONAL_OFFERS_CRON_BASIC_PASSWORD=change-me-strong-password
PROMOTIONAL_OFFERS_CRON_HEADER_NAME=X-Dispatch-Secret
PROMOTIONAL_OFFERS_CRON_HEADER_VALUE=change-me-second-secret
```

#### Configuration cronjob.org recommandée (POST)

- method: `POST`
- URL: `https://votre-domaine/api/promotional-offers/daily-dispatch`
- schedule: tous les jours à 08:00 (`Europe/Paris`)
- timeout: 30s ou 60s
- retry on failure: activé (1-2 retries)

**Headers :**
- `Content-Type: application/json`
- `X-Cron-Token: <PROMOTIONAL_OFFERS_CRON_TOKEN>`
- `X-Dispatch-Secret: <PROMOTIONAL_OFFERS_CRON_HEADER_VALUE>` (si couche 3 activée)

**HTTP Authentication :**
- username: `<PROMOTIONAL_OFFERS_CRON_BASIC_USER>`
- password: `<PROMOTIONAL_OFFERS_CRON_BASIC_PASSWORD>`

**Body JSON (optionnel si token déjà dans header) :**
```json
{
  "token": "<PROMOTIONAL_OFFERS_CRON_TOKEN>"
}
```

#### Vérification manuelle attendue

Réponse 200 :
```json
{
  "date": "2026-05-09",
  "start_notifications_sent_for_offers": 1,
  "ending_soon_notifications_sent_for_offers": 2
}
```

**Comportement :**
- notifie les customers d'un merchant le premier jour d'un bon plan
- notifie les customers à J-2 de la fin d'un bon plan
- respecte les préférences customer : `enabled` ET `promotional_offers_enabled`
- évite les doublons en marquant chaque bon plan après dispatch (`start_notification_sent_at`, `ending_soon_notification_sent_at`)

**Réponse :**
```json
{
  "date": "2026-05-09",
  "start_notifications_sent_for_offers": 3,
  "ending_soon_notifications_sent_for_offers": 2
}
```

**Erreurs :**
- `401` : `unauthorized`
- `503` : `promotional_offer_cron_token_not_configured`

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

### Customer Available Programs
```
GET /api/customers/me/available-programs
```

Retourne, pour chaque merchant auquel le customer est rattaché, la liste des programmes actifs avec l'indicateur `already_has_active_card`.

**Headers :**
- `Authorization: Bearer <token>` (requis, token customer)

**Règles métier :**
- seuls les marchands rattachés au customer apparaissent
- rattachement accepté via inscription merchant ou carte existante
- seuls les programmes `is_active = true` apparaissent
- un customer ne peut avoir qu'une seule carte active (`is_completed = false`) par programme

**Réponse :**
```json
[
  {
    "merchant": {
      "id": "merchant-uuid",
      "company_name": "Coffee Shop",
      "logo_url": "https://..."
    },
    "programs": [
      {
        "id": 12,
        "name": "Programme tampons cafe",
        "type": "STAMP",
        "stamp_target": 10,
        "points_per_euro": null,
        "points_target": null,
        "reward_description": "1 cafe offert",
        "is_active": true,
        "already_has_active_card": false
      }
    ]
  }
]
```

**Erreurs :**
- `401` : `Unauthorized`
- `404` : `Customer not found for user`

### Customer Self-Enrollment Card Creation
```
POST /api/customers/me/cards
```

Crée une carte de fidélité pour le customer authentifié dans le programme demandé.

**Headers :**
- `Authorization: Bearer <token>` (requis, token customer)

**Body :**
```json
{
  "loyalty_program_id": 12
}
```

**Réponse (201) :** même format qu'un item de `GET /api/customers/me/cards`

**Comportement complémentaire :**
- crée la carte active pour le programme demandé
- envoie une notification `card_created` si les notifications sont activées pour ce merchant
- le message indique que la carte est prête à être scannée chez le merchant partenaire

**Erreurs métier stables :**
- `400` : `loyalty_program_id_required`
- `404` : `program_not_found`
- `403` : `not_customer_of_merchant`
- `409` : `active_card_already_exists`

### Customer Rewards Multi-Merchant
```
GET /api/customers/me/rewards
```

Retourne les rewards du customer authentifié, triées par `generated_at` décroissant.

**Headers :**
- `Authorization: Bearer <token>` (requis, token customer)

**Réponse :**
```json
[
  {
    "id": "uuid-reward",
    "loyalty_card_id": 101,
    "merchant_id": "uuid-merchant",
    "wallet_token": "uuid-wallet-token",
    "reward_description": "1 café offert",
    "status": "PENDING",
    "generated_at": "2026-04-16T09:15:00+00:00",
    "claimed_at": null,
    "claim_qr_token": "token-unique",
    "merchant": {
      "id": "uuid-merchant",
      "company_name": "Shop A",
      "logo_url": null
    },
    "loyalty_program": {
      "id": 3,
      "name": "Programme Points",
      "type": "POINTS"
    }
  }
]
```

**Erreurs :**
- `401` : `Unauthorized`
- `404` : `Customer not found for user` (ex: token merchant utilisé sur cet endpoint)

### Module Avis Google

Ce module permet de configurer un parcours customer lié à un merchant.
Le coeur du flow est désormais l'obtention d'une récompense via la roue, puis la proposition de laisser un avis Google qui reste optionnelle.

**Compat V1/V2 :** le backend conserve temporairement les signaux historiques de session (`launch` puis `return`) pour compatibilité de transition.

**Important :** le backend ne vérifie jamais qu'un avis Google a réellement été posté et ne vérifie jamais une note `5/5`.

#### Règles produit

- Un module n'est visible côté customer que s'il est complet.
- Un module complet signifie : `is_enabled = true`, `google_review_url` valide, au moins une reward option active.
- Un customer peut récupérer plusieurs modules s'il est lié à plusieurs merchants.
- En V1, une seule session active est attendue par couple customer x merchant.
- Une session ne peut produire qu'une seule reward.
- Un QR de reward ne peut être redeem qu'une seule fois.

#### Domaines Google autorisés

- `google.com`
- `www.google.com`
- `maps.google.com`
- `g.page`

HTTPS est obligatoire.

#### Statuts session

- `READY_TO_LAUNCH`
- `OUTBOUND_OPENED`
- `RETURNED_TO_APP`
- `REWARD_READY`
- `REDEEMED`
- `EXPIRED`

#### Statuts reward

- `ACTIVE`
- `REDEEMED`
- `EXPIRED`
- `CANCELLED`

#### GET /api/merchants/me/google-review-module

```http
GET /api/merchants/me/google-review-module
Authorization: Bearer <token>
```

Retourne la configuration du merchant courant. Si elle n'existe pas encore, le backend crée une configuration par défaut persistée.

**Accès :** `ROLE_MERCHANT`

**Réponse 200 :**

```json
{
  "id": "uuid-module",
  "merchant_id": "uuid-merchant",
  "merchant_name": "Boulangerie Martin",
  "is_enabled": false,
  "display_name": "Avis Google",
  "google_review_url": null,
  "show_in_customer_dashboard": true,
  "show_qr_code": true,
  "reward_options": [],
  "merchant_logo_url": null,
  "is_configuration_complete": false,
  "created_at": "2026-04-19T12:00:00+00:00",
  "updated_at": "2026-04-19T12:00:00+00:00"
}
```

**Erreurs :**
- `404` : `merchant_not_found`

#### PUT /api/merchants/me/google-review-module

```http

## Public API

Cette section décrit les endpoints publics accessibles sans authentification JWT, destinés au frontend public (landing page, map de découverte).

### Get Merchants Map
```
GET /api/public/merchants/map
```

Endpoint public pour découvrir les marchands avec géolocalisation, filtrage, et pagination.

**Query Parameters :**
- `latitude` (float, optional) : latitude du point de recherche
- `longitude` (float, optional) : longitude du point de recherche
- `radius_km` (float, optional, défaut 10) : rayon de recherche en kilomètres
- `limit` (int, optional, défaut 20) : nombre de résultats max
- `offset` (int, optional, défaut 0) : pagination offset
- `search` (string, optional) : recherche textuelle sur `company_name`

**Réponse 200 :**
```json
{
  "merchants": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "company_name": "Boulangerie Martin",
      "address": "123 rue de la Paix, 75000 Paris",
      "postal_code": "75000",
      "city": "Paris",
      "phone": "01 23 45 67 89",
      "logo_url": "data:image/png;base64,iVBORw0KGgoAAAANS...",
      "latitude": 48.8566,
      "longitude": 2.3522,
      "distance_km": 2.5,
      "subscriber_count": 42,
      "has_active_content": true,
      "is_customer_linked": false,
      "loyalty_programs": [
        {
          "id": 1,
          "name": "Fidélité Pains",
          "type": "STAMP",
          "stamp_target": 10,
          "reward_description": "1 pain offert",
          "is_active": true
        }
      ],
      "active_promotional_offers": [
        {
          "id": 2,
          "name": "Promo Croissants",
          "description": "30% de réduction",
          "discount_percentage": 30,
          "valid_from": "2026-05-13T00:00:00Z",
          "valid_until": "2026-05-20T23:59:59Z",
          "is_active": true
        }
      ],
      "google_review": {
        "id": "uuid-module",
        "merchant_id": "550e8400-e29b-41d4-a716-446655440000",
        "is_enabled": true,
        "display_name": "Avis Google",
        "google_review_url": "https://g.page/r/bakery-martin/review",
        "show_in_customer_dashboard": true,
        "show_qr_code": true,
        "is_configuration_complete": true,
        "created_at": "2026-04-19T12:00:00+00:00"
      }
    }
  ],
  "total": 145
}
```

**Structure détaillée :**

**MapMerchant :**
- `id` (string UUID) : identifiant unique du merchant
- `company_name` (string) : nom de la boutique/entreprise
- `address` (string | null) : adresse complète
- `postal_code` (string | null) : code postal
- `city` (string | null) : ville
- `phone` (string | null) : téléphone du merchant
- `logo_url` (string | null) : URL data:image base64 du logo (optionnel)
- `latitude` (number) : latitude (calculée depuis address si present)
- `longitude` (number) : longitude (calculée depuis address si present)
- `distance_km` (number | null) : distance en km depuis le point de recherche (null si pas de géolocalisation)
- `subscriber_count` (number | null) : nombre d'abonnés/clients du merchant (social proof)
- `has_active_content` (boolean) : merchant a au moins un programme de fidélité actif ou une offre promo active
- `is_customer_linked` (boolean) : utilisateur courant est lié à ce merchant (nécessite token customer)
- `loyalty_programs` (array MapLoyaltyProgram[]) : programs de fidélité actifs
- `active_promotional_offers` (array MapActiveOffer[]) : offres promotionnelles actives
- `google_review` (MapGoogleReview | null) : configuration module Avis Google si complète, null sinon

**MapLoyaltyProgram :**
- `id` (integer) : identifiant
- `name` (string) : nom du program
- `type` (string enum) : `STAMP` ou `POINTS`
- `stamp_target` (integer | null) : nombre de stamps cible pour reward (si STAMP)
- `reward_description` (string) : description de la récompense
- `is_active` (boolean) : program actif

**MapActiveOffer :**
- `id` (integer) : identifiant
- `name` (string) : nom de l'offre
- `description` (string | null) : description courte
- `discount_percentage` (integer | null) : pourcentage de réduction si applicable
- `valid_from` (datetime ISO 8601) : début validité
- `valid_until` (datetime ISO 8601) : fin validité
- `is_active` (boolean) : offre active

**MapGoogleReview :**
- `id` (string UUID) : identifiant du module
- `merchant_id` (string UUID) : référence au merchant
- `is_enabled` (boolean) : module activé
- `display_name` (string) : label d'affichage (ex: "Avis Google")
- `google_review_url` (string) : URL Google complète du merchant
- `show_in_customer_dashboard` (boolean) : afficher dans dashboard customer
- `show_qr_code` (boolean) : afficher QR code de reward
- `is_configuration_complete` (boolean) : module complètement configuré
- `created_at` (datetime ISO 8601) : date de création

**Comportements :**
- La réponse est paginée : utiliser `offset` + `limit` pour naviguer
- `distance_km` est calculé via haversine depuis lat/lon si les paramètres de géolocalisation sont fournis
- `subscriber_count` affiche le nombre d'abonnés du merchant (pour la preuve sociale)
- `loyalty_programs` et `active_promotional_offers` ne contiennent que les éléments actifs
- `google_review` n'est inclus que si le module est complètement configuré
- `logo_url` est stocké en base64 pour faciliter l'intégration frontend

**Erreurs possibles :**
- `400` : paramètres géolocalisation invalides (latitude/longitude non numérique)
- `400` : `radius_km` négatif ou invalide
- `200` : résultat vide si aucun merchant ne correspond (tableau `merchants` vide)

**Performance :**
- Résultats limités par défaut à 20 pour éviter surcharge
- Géolocalisation peut être omise (recherche sur tous les merchants)
- `search` effectue une recherche case-insensitive sur `company_name`
PUT /api/merchants/me/google-review-module
Authorization: Bearer <token>
```

**Body :**

```json
{
  "is_enabled": true,
  "display_name": "Avis Google",
  "google_review_url": "https://g.page/r/demo/review",
  "show_in_customer_dashboard": true,
  "show_qr_code": true,
  "reward_options": [
    {
      "label": "Cafe offert",
      "description": null,
      "active": true,
      "order": 1
    },
    {
      "label": "Cookie offert",
      "description": null,
      "active": true,
      "order": 2
    }
  ]
}
```

**Comportement :**
- normalise et persiste la configuration
- génère un `id` par reward option si absent
- refuse l'activation sans URL Google valide
- refuse l'activation sans reward option active
- ignore silencieusement `google_place_id` et `google_place_name` s'ils sont encore envoyés par un ancien client

**Erreurs métier stables :**
- `400` : `google_review_payload_invalid`
- `404` : `merchant_not_found`
- `422` : `google_review_url_missing`
- `422` : `google_review_url_invalid`
- `422` : `google_review_rewards_missing`
- `422` : `google_review_rewards_invalid`

#### GET /api/merchants/me/google-review-rewards

```http
GET /api/merchants/me/google-review-rewards?status=ACTIVE&search=jean&page=1&itemsPerPage=15
Authorization: Bearer <token>
```

Liste paginée des récompenses Avis Google du merchant connecté.

**Accès :** `ROLE_MERCHANT`

**Query params :**
- `status` optionnel : `ACTIVE` | `REDEEMED` | `EXPIRED` | `CANCELLED`
- `search` optionnel : recherche libre sur `customer_name` et `customer_email`
- `page` optionnel : entier >= 1, défaut `1`
- `itemsPerPage` optionnel : entier >= 1, défaut `15`, max `100`

**Comportement :**
- scope strict sur le merchant connecté
- tri par défaut `created_at DESC`
- si `status=ACTIVE`, seules les récompenses non récupérées sont retournées

**Réponse 200 :**

```json
{
  "items": [
    {
      "id": "3f18c7c0-7e4c-4f3f-80a2-6a8eb31f8f0f",
      "merchant_id": "b12f8c4f-1d65-4d7a-b2df-7ac87eb0f83a",
      "customer_id": 42,
      "customer_name": "Jean Dupont",
      "customer_email": "jean@exemple.fr",
      "reward_label": "Cafe offert",
      "reward_description": null,
      "status": "ACTIVE",
      "qr_token": "857f2f...",
      "qr_payload": "coachat-google-review-reward://857f2f...",
      "created_at": "2026-04-19T10:43:11+00:00",
      "redeemed_at": null
    }
  ],
  "total": 42,
  "page": 1,
  "itemsPerPage": 15
}
```

**Erreurs métier stables :**
- `404` : `merchant_not_found`
- `422` : `google_review_reward_status_invalid`

#### POST /api/merchants/me/google-review-rewards/{rewardId}/redeem

```http
POST /api/merchants/me/google-review-rewards/{rewardId}/redeem
Authorization: Bearer <token>
```

Fallback de validation manuelle si le scan QR ne fonctionne pas.

**Accès :** `ROLE_MERCHANT`

**Comportement :**
- vérifie que la récompense appartient au merchant connecté
- utilise la même logique métier que le redeem par scan
- autorise uniquement le statut `ACTIVE`
- passe la reward à `REDEEMED`
- passe la session liée à `REDEEMED`
- journalise `REWARD_REDEEMED`

**Réponse 200 :**

```json
{
  "id": "3f18c7c0-7e4c-4f3f-80a2-6a8eb31f8f0f",
  "status": "REDEEMED",
  "redeemed_at": "2026-04-19T11:02:10+00:00"
}
```

**Erreurs métier stables :**
- `404` : `merchant_not_found`
- `404` : `google_review_reward_not_found`
- `409` : `google_review_reward_already_redeemed`
- `409` : `google_review_reward_not_redeemable`

#### GET /api/customer/me/google-review-modules

```http
GET /api/customer/me/google-review-modules
Authorization: Bearer <token>
```

Retourne tous les modules actifs et complets pour les merchants liés au customer courant.
Chaque item inclut `is_active` pour indiquer si le module Google Review est activé côté merchant.

**Accès :** `ROLE_CUSTOMER`

**Réponse 200 :**

```json
[
  {
    "merchant_id": "uuid-merchant",
    "merchant_name": "Boulangerie Martin",
    "display_name": "Avis Google",
    "merchant_logo_url": null,
    "google_review_url": "https://g.page/r/demo/review",
    "is_active": true,
    "status": "ready_to_launch",
    "detail_route": "/customer/google-review/uuid-merchant",
    "active_session_id": null,
    "active_reward_id": null,
    "show_qr_code": true
  }
]
```

#### GET /api/customer/me/google-review-modules/{merchantId}

```http
GET /api/customer/me/google-review-modules/{merchantId}
Authorization: Bearer <token>
```

Retourne la vue customer-facing du module pour un merchant donné, avec session et reward courantes si elles existent.

**Réponse 200 :**

```json
{
  "merchant_id": "uuid-merchant",
  "merchant_name": "Boulangerie Martin",
  "display_name": "Avis Google",
  "merchant_logo_url": null,
  "google_review_url": "https://g.page/r/demo/review",
  "status": "ready_to_spin",
  "active_session": {
    "id": "uuid-session",
    "status": "RETURNED_TO_APP"
  },
  "active_reward": null,
  "show_qr_code": true
}
```

**Erreurs :**
- `404` : `merchant_not_found`
- `404` : `google_review_module_not_found`

#### GET /api/customer/me/google-review-modules/{merchantId}/reward-options

```http
GET /api/customer/me/google-review-modules/{merchantId}/reward-options
Authorization: Bearer <token>
```

Retourne la liste des récompenses disponibles pour le module Avis Google d'un merchant donné. Seules les `reward_options` actives sont exposées au customer.

**Réponse 200 :**

```json
{
  "merchant_id": "uuid-merchant",
  "merchant_name": "Boulangerie Martin",
  "merchant_logo_url": null,
  "display_name": "Avis Google",
  "google_review_url": "https://g.page/r/demo/review",
  "show_qr_code": true,
  "reward_options": [
    {
      "id": "uuid-option-1",
      "label": "Cafe offert",
      "description": null,
      "active": true,
      "order": 1
    },
    {
      "id": "uuid-option-2",
      "label": "Cookie offert",
      "description": "Un cookie offert",
      "active": true,
      "order": 2
    }
  ]
}
```

**Erreurs :**
- `404` : `merchant_not_found`
- `404` : `google_review_module_not_found`

#### POST /api/customer/me/google-review-modules/{merchantId}/launch

```http
POST /api/customer/me/google-review-modules/{merchantId}/launch
Authorization: Bearer <token>
```

Crée ou réutilise une session pour le couple customer x merchant, incrémente `launch_count` et journalise `OUTBOUND_CLICKED`.

**Réponse 200 :**

```json
{
  "session_id": "uuid-session",
  "status": "OUTBOUND_OPENED",
  "redirect_url": "https://g.page/r/demo/review"
}
```

**Erreurs :**
- `404` : `merchant_not_found`
- `404` : `google_review_module_not_found`

#### POST /api/customer/me/google-review-sessions/{sessionId}/return

```http
POST /api/customer/me/google-review-sessions/{sessionId}/return
Authorization: Bearer <token>
```

Confirme le retour dans l'app, positionne la session en `RETURNED_TO_APP` et expose `can_spin`.

**Réponse 200 :**

```json
{
  "id": "uuid-session",
  "status": "RETURNED_TO_APP",
  "launch_count": 1,
  "launched_at": "2026-04-19T12:05:00+00:00",
  "returned_at": "2026-04-19T12:06:00+00:00",
  "spun_at": null,
  "google_review_url": "https://g.page/r/demo/review",
  "can_spin": true,
  "reward": null
}
```

**Erreurs :**
- `404` : `google_review_session_not_found`
- `409` : `google_review_session_invalid_state`

#### GET /api/customer/me/google-review-sessions/{sessionId}

```http
GET /api/customer/me/google-review-sessions/{sessionId}
Authorization: Bearer <token>
```

Retourne l'état courant de la session et la reward associée si présente.

**Déprécié :** oui (phase A). Endpoint conservé temporairement pour compatibilité, suppression prévue en phase B.

#### POST /api/customer/me/google-review-sessions/{sessionId}/spin

```http
POST /api/customer/me/google-review-sessions/{sessionId}/spin
Authorization: Bearer <token>
```

Attribue une reward unique à la session. Le résultat est idempotent : si une reward existe déjà pour la session, elle est retournée telle quelle.

**Comportement :**
- verrou pessimiste sur la session en transaction
- refuse le spin si la session n'est pas en `RETURNED_TO_APP`
- refuse le spin si une autre reward `ACTIVE` existe déjà pour ce customer et ce merchant
- sélection uniforme parmi les reward options actives
- crée un QR token unitaire et un `qr_payload` de forme `coachat-google-review-reward://<token>`
- journalise `WHEEL_SPUN` puis `REWARD_REVEALED`

**Réponse 200 :**

```json
{
  "session_id": "uuid-session",
  "status": "REWARD_READY",
  "google_review_url": "https://g.page/r/demo/review",
  "reward": {
    "id": "uuid-reward",
    "reward_label": "Cafe offert",
    "reward_description": null,
    "status": "ACTIVE",
    "qr_token": "abc123",
    "qr_payload": "coachat-google-review-reward://abc123"
  }
}
```

**Erreurs :**
- `404` : `google_review_session_not_found`
- `409` : `google_review_session_invalid_state`
- `409` : `google_review_reward_already_active`
- `409` : `google_review_module_incomplete`
- `422` : `google_review_rewards_missing`

#### GET /api/customer/me/google-review-rewards/{rewardId}

```http
GET /api/customer/me/google-review-rewards/{rewardId}
Authorization: Bearer <token>
```

Retourne la reward customer-facing et conserve `google_review_url` visible pour le frontend.

**Déprécié :** oui (phase A). Endpoint conservé temporairement pour compatibilité, suppression prévue en phase B.

**Réponse 200 :**

```json
{
  "id": "uuid-reward",
  "reward_label": "Cafe offert",
  "reward_description": null,
  "status": "ACTIVE",
  "qr_token": "abc123",
  "qr_payload": "coachat-google-review-reward://abc123",
  "google_review_url": "https://g.page/r/demo/review",
  "redeemed_at": null
}
```

**Erreurs :**
- `404` : `google_review_reward_not_found`

#### POST /api/google-review-rewards/{qrToken}/redeem

```http
POST /api/google-review-rewards/{qrToken}/redeem
Authorization: Bearer <token>
```

Redeem merchant d'une reward QR mono-usage.

**Accès :** `ROLE_MERCHANT`

**Comportement :**
- vérifie que la reward appartient au merchant connecté
- partage la même logique métier que `POST /api/merchants/me/google-review-rewards/{rewardId}/redeem`
- autorise uniquement le statut `ACTIVE`
- passe la reward à `REDEEMED`
- passe la session à `REDEEMED`
- journalise `REWARD_REDEEMED`

**Réponse 200 :**

```json
{
  "id": "uuid-reward",
  "status": "REDEEMED",
  "reward_label": "Cafe offert",
  "redeemed_at": "2026-04-19T12:10:00+00:00",
  "session_id": "uuid-session"
}
```

**Erreurs :**
- `404` : `merchant_not_found`
- `404` : `google_review_reward_not_found`
- `409` : `google_review_reward_already_redeemed`
- `409` : `google_review_reward_not_redeemable`

#### POST /api/google-review-events

```http
POST /api/google-review-events
Authorization: Bearer <token>
```

Endpoint optionnel pour journaliser des événements complémentaires depuis l'app customer.

**Body :**

```json
{
  "merchant_id": "uuid-merchant",
  "session_id": "uuid-session",
  "event_type": "MODULE_VIEWED",
  "source": "customer_app",
  "metadata": {
    "screen": "google-review-detail"
  }
}
```

**Types d'événements acceptés :**
- `MODULE_VIEWED`
- `DETAIL_VIEWED`
- `OUTBOUND_CLICKED`
- `RETURN_CONFIRMED`
- `WHEEL_SPUN`
- `REWARD_REVEALED`
- `QR_VIEWED`
- `REWARD_REDEEMED`

**Réponse 201 :**

```json
{
  "id": "uuid-event",
  "event_type": "MODULE_VIEWED",
  "created_at": "2026-04-19T12:11:00+00:00"
}
```

**Erreurs :**
- `400` : `google_review_payload_invalid`
- `404` : `customer_not_found`
- `404` : `merchant_not_found`
- `404` : `google_review_module_not_found`
- `404` : `google_review_session_not_found`
- `422` : `google_review_event_invalid`

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
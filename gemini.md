# API Documentation - Loyalty Card System

## Overview

Cette API Symfony fournit un système complet de gestion de cartes de fidélité pour les commerçants. Elle inclut l'authentification via Google OAuth2, la gestion des commerçants, programmes de fidélité, cartes de fidélité, transactions et clients.

L'API utilise JWT pour l'authentification et API Platform pour la gestion des ressources.

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

### Get Current Merchant
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
  "email": "store@example.com"
}
```

**Réponse :**
```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "company_name": "My Store Name",
  "email": "store@example.com"
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
  "email": "updated@example.com",
  "stripe_customer_id": "cus_updated",
  "trial_ends_at": "2026-06-03T00:00:00Z",
  "subscription_status": "active",
  "plan_slug": "standard"
}
```

**Champs optionnels pour le plan** :
- `plan_id` : UUID du plan à assigner
- `plan_slug` : slug du plan à assigner (`free`, `standard`, `premium`)

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
    "qr_code": "card_123456789",
    "current_value": 50,
    "target_value": 100,
    "is_completed": false,
    "wallet_token": "550e8400-e29b-41d4-a716-446655440000",
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
    }
  }
]
```

### Get Loyalty Card by QR Code
```
GET /api/loyalty_cards/by-qr/{qr_code}
```

Retourne une carte de fidélité par son code QR.

**Headers :**
- `Authorization: Bearer <token>`

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
  "qr_code": "generated_qr_code",
  "current_value": 0,
  "target_value": 100,
  "is_completed": false,
  "wallet_token": "550e8400-e29b-41d4-a716-446655440000",
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
  "qr_code": "card_123456789",
  "current_value": 75,
  "target_value": 100,
  "is_completed": false,
  "wallet_token": "550e8400-e29b-41d4-a716-446655440000",
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
  }
}
```

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

### Google Wallet
```
GET /public/wallet/google/{walletToken}
```

Redirige vers l'URL "Ajouter à Google Wallet".

**Paramètres :**
- `walletToken` : valeur du champ `wallet_token` retourné par les endpoints loyalty_cards

**Réponse :** Redirection HTTP 302 vers `https://pay.google.com/gp/v/save/{jwt}`

**Note :** Ces deux routes ne nécessitent pas de token JWT.

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
      "qr_code": "card_123456789"
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
- `checkout.session.completed` : Assigne le plan au merchant + statut `active`
- `customer.subscription.updated` : Synchronisation du statut
- `customer.subscription.deleted` : Retour au plan `free` + statut `canceled`
- `invoice.payment_failed` : Statut `suspended`

**Réponse :**
```json
{
  "received": true
}
```

### Flow Complet

1. Frontend appelle `GET /api/plans` pour afficher les plans disponibles
2. Utilisateur choisit un plan → frontend envoie `POST /api/subscription/checkout` avec `plan_slug`
3. Backend résout le Stripe Price ID depuis la BDD (non exposé)
4. Backend crée/récupère le customer Stripe et crée une session Checkout
5. Frontend redirige vers `checkoutUrl`
6. Utilisateur effectue le paiement
7. Stripe redirige vers `success_url` ou `cancel_url`
8. Stripe envoie webhook `checkout.session.completed`
9. Backend assigne le plan au merchant et met à jour `subscription_status` à `active`
10. Frontend récupère le statut via `GET /api/merchant/me`

### Test Mode

Utilisez les données de test Stripe :
- **Card Number** : 4242 4242 4242 4242
- **Expiration** : Date future (ex: 12/25)
- **CVC** : Code à 3 chiffres quelconque
- Configurez les Stripe Price IDs dans `Plan.stripePriceId` via fixtures ou BDD

## Customers (Clients)

### List Customers
```
GET /api/customers
```

Retourne tous les clients.

**Headers :**
- `Authorization: Bearer <token>`

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

### Get Customer
```
GET /api/customers/{id}
```

Retourne un client par son ID.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+1234567890"
}
```

### Create Customer
```
POST /api/customers
```

Crée un nouveau client.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+1234567890"
}
```

**Paramètres :**
- `name` (requis) : Nom du client
- `email` (requis) : Email du client
- `phone` (optionnel) : Numéro de téléphone

**Réponse :**
```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "phone": "+1234567890"
}
```

### Update Customer
```
PUT /api/customers/{id}
```

Met à jour un client.

**Headers :**
- `Authorization: Bearer <token>`

**Body :**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "phone": "+0987654321"
}
```

**Réponse :**
```json
{
  "id": 1,
  "name": "Jane Doe",
  "email": "jane@example.com",
  "phone": "+0987654321"
}
```

### Delete Customer
```
DELETE /api/customers/{id}
```

Supprime un client.

**Headers :**
- `Authorization: Bearer <token>`

**Réponse :**
```json
{
  "success": true
}
```

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
_card
- Toutes les requêtes (sauf authentification) nécessitent un JWT token valide
- Les ressources sont filtrées par utilisateur/commerçant pour la sécurité
- CORS est configuré pour permettre les requêtes depuis :
  - `http://localhost:5173` (développement frontend)
  - `http://coachat-bakend-loyalty-card.test` (développement)
- Guard `IsMerchantOwner` assure que seul le propriétaire peut accéder/modifier ses ressources
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
- `canceled` : Annulé
- `suspended` : Suspendu
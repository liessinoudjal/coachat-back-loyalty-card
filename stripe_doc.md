# Stripe — Documentation d'intégration

## Variables d'environnement

```env
STRIPE_PUBLIC_KEY=pk_live_xxxxx           # Clé publique (frontend)
STRIPE_SECRET_KEY=rk_live_xxxxx          # Clé restreinte backend (ne jamais utiliser sk_live_)
STRIPE_WEBHOOK_SECRET=whsec_xxxxx        # Secret de signature du webhook
STRIPE_SUCCESS_URL=http://localhost:5173/subscription?session_id={CHECKOUT_SESSION_ID}
STRIPE_CANCEL_URL=http://localhost:5173/subscription?canceled=true
```

> En développement, surcharger dans `.env.local` (jamais commité).

---

## Permissions de la clé API

Utiliser une **clé restreinte** (`rk_live_...` / `rk_test_...`), jamais la clé secrète complète.

| Ressource | Permission |
|-----------|------------|
| Customers | Écriture |
| Checkout Sessions | Écriture |
| Subscriptions | Lecture |
| Invoices | Lecture |
| Customer portal | Écriture |
| Prices | Lecture |
| Events | Lecture |

Tout le reste → **Aucune**.

---

## Produits & Price IDs

| Plan | Slug | Prix | Stripe Price ID (test) | Stripe Price ID (prod) |
|------|------|------|------------------------|------------------------|
| Gratuit | `free` | 0 € | — | — |
| Standard | `standard` | 19 €/mois | `price_1TIpbqCOlIXBpVLDLspvPmb6` | *(à renseigner en BDD)* |
| Premium | `premium` | 29 €/mois | `price_1TIpeOCOlIXBpVLDVLjZ0RCd` | *(à renseigner en BDD)* |

Les Price IDs sont stockés dans l'entité `Plan.stripePriceId` — jamais exposés au frontend.

---

## Endpoints

### Plans (public, sans auth)

| Méthode | URL | Description |
|---------|-----|-------------|
| `GET` | `/api/plans` | Liste tous les plans actifs |
| `GET` | `/api/plans/{id}` | Détail d'un plan |

### Abonnements (JWT requis sauf webhook)

| Méthode | URL | Description |
|---------|-----|-------------|
| `GET` | `/api/subscription/status` | Statut d'abonnement + plan actif du merchant |
| `POST` | `/api/subscription/checkout` | Crée une session Checkout, retourne `checkoutUrl` |
| `POST` | `/api/subscription/portal` | Crée une session Customer Portal, retourne `url` |
| `POST` | `/api/subscription/webhook` | Reçoit les événements Stripe (**PUBLIC**) |

#### POST /api/subscription/checkout

**Body :**
```json
{ "plan_slug": "standard" }
```
ou
```json
{ "plan_id": "uuid-du-plan" }
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
{ "plan": "free", "status": "active" }
```

#### POST /api/subscription/portal

**Body (optionnel) :**
```json
{ "return_url": "http://localhost:5173/subscription" }
```

**Réponse :**
```json
{ "url": "https://billing.stripe.com/session/xxxxx" }
```

#### GET /api/subscription/status

**Réponse :**
```json
{
  "subscription_status": "active",
  "trial_ends_at": "2026-05-05T00:00:00Z",
  "plan": {
    "id": "...",
    "slug": "standard",
    "name": "Standard",
    "price_monthly": 1900
  }
}
```

---

## Webhook

**URL à configurer dans Stripe Dashboard → Développeurs → Webhooks :**
```
https://ton-domaine.com/api/subscription/webhook
```

**Événements à sélectionner :**

| Événement | Effet côté backend |
|-----------|-------------------|
| `checkout.session.completed` | Assigne le plan au merchant + statut `active` |
| `customer.subscription.updated` | Synchronise le statut (`active` / `inactive`) |
| `customer.subscription.deleted` | Retour au plan `free` + statut `canceled` |
| `invoice.payment_failed` | Statut `suspended` |

**Test en local :**
```bash
stripe listen --forward-to localhost:8000/api/subscription/webhook
```
La CLI génère un `whsec_` temporaire à mettre dans `.env.local`.

---

## Flow complet d'abonnement

1. Frontend appelle `GET /api/plans` → affiche les plans
2. Utilisateur choisit un plan → `POST /api/subscription/checkout` avec `plan_slug`
3. Backend crée/récupère le customer Stripe et génère une session Checkout
4. Frontend redirige vers `checkoutUrl`
5. Utilisateur paie sur la page Stripe
6. Stripe redirige vers `success_url` ou `cancel_url`
7. Stripe envoie `checkout.session.completed` → backend assigne le plan + statut `active`
8. Frontend appelle `GET /api/subscription/status` pour confirmer

---

## Cartes de test

| Scénario | Numéro de carte | Date | CVC | ZIP |
|----------|----------------|------|-----|-----|
| **Paiement réussi** | `4242 4242 4242 4242` | N'importe quelle date future | N'importe lequel | N'importe lequel |
| **Authentification 3D Secure** | `4000 0025 0000 3155` | idem | idem | idem |
| **Carte refusée** | `4000 0000 0000 9995` | idem | idem | idem |

---

## Statuts d'abonnement possibles

| Statut | Signification |
|--------|--------------|
| `trial` | Période d'essai (30 jours à la création du merchant) |
| `active` | Abonnement actif ou plan gratuit activé |
| `inactive` | Abonnement suspendu par Stripe |
| `canceled` | Abonnement annulé → retour au plan gratuit |
| `suspended` | Échec de paiement |

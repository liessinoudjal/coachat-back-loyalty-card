# Contrat Front - Fonctionnalite Equipier

## Objectif
Permettre a un user ayant les roles `ROLE_CUSTOMER` + `ROLE_EQUIPIER` de:
- se connecter via le flow merchant
- acceder a un dashboard merchant limite (scan/operations de terrain)
- switcher vers son dashboard customer

Le front ne doit pas inferer les roles via heritage. Il doit utiliser les roles explicites fournis.

---

## 1) Claims JWT a consommer
Le JWT inclut maintenant les claims suivants:

- `roles`: liste des roles explicites (inclut reachable roles)
- `all_roles`: meme contenu que `roles` (pour compat)
- `is_equipier`: bool
- `merchant_id`: merchant owner id (nullable)
- `equipier_merchant_id`: merchant de rattachement equipier (nullable)
- `customer_id`: id customer (nullable)

Regle front:
- Source de verite = `roles` (ou `all_roles` si necessaire).
- Mode equipier actif si `roles` contient `ROLE_EQUIPIER`.
- Possibilite switch customer si `roles` contient `ROLE_CUSTOMER`.

---

## 2) Reponses auth a consommer

### Merchant login / register callbacks
Les payloads auth merchant incluent:

```json
{
  "token": "...",
  "refresh_token": "...",
  "user": {
    "id": 123,
    "email": "user@x.com",
    "name": "User",
    "roles": ["ROLE_USER", "ROLE_CUSTOMER", "ROLE_EQUIPIER"]
  },
  "merchant_context": {
    "id": "uuid",
    "company_name": "Shop",
    "is_owner": false,
    "is_equipier": true
  }
}
```

### Customer callbacks
Les payloads customer incluent en plus:

```json
{
  "customer": {
    "id": 45,
    "email": "c@x.com",
    "name": "Customer",
    "is_equipier": true,
    "equipier_merchant_id": "uuid"
  }
}
```

---

## 3) Endpoints equipier (gestion par merchant owner)

### GET /api/merchants/me/staff
Liste les customers equipiers du merchant courant.

Response 200:
```json
[
  {
    "id": 45,
    "name": "Alice",
    "email": "alice@x.com",
    "phone": null,
    "is_equipier": true,
    "equipier_merchant_id": "uuid",
    "equipier_assigned_at": "2026-04-29T10:00:00+00:00",
    "roles": ["ROLE_USER", "ROLE_CUSTOMER", "ROLE_EQUIPIER"]
  }
]
```

### POST /api/merchants/me/staff/{customerId}
Promouvoir un customer en equipier.

Response 200: meme shape qu un item de liste.

Erreurs:
- 404 `Customer not found`
- 409 `customer_user_required`
- 409 `customer_already_staff_for_other_merchant`

### DELETE /api/merchants/me/staff/{customerId}
Retirer un equipier.

Response 200:
```json
{ "success": true }
```

Erreurs:
- 404 `Staff customer not found`

---

## 4) Endpoints existants avec infos equipier ajoutees

### GET /api/customers
### GET /api/customers/{id}
### PUT /api/customers/{id}
### GET /api/customers/me/bootstrap

Les payloads customer incluent maintenant:
- `is_equipier`
- `equipier_merchant_id`
- `equipier_assigned_at`
- `roles`

Exemple item:
```json
{
  "id": 45,
  "name": "Alice",
  "email": "alice@x.com",
  "phone": null,
  "is_equipier": true,
  "equipier_merchant_id": "uuid",
  "equipier_assigned_at": "2026-04-29T10:00:00+00:00",
  "roles": ["ROLE_USER", "ROLE_CUSTOMER", "ROLE_EQUIPIER"]
}
```

---

## 5) Matrice de droits front (important)

### Merchant owner (`ROLE_MERCHANT` avec merchant owner)
Autoriser toutes les actions merchant existantes.

### Equipier (`ROLE_EQUIPIER`)
Autoriser:
- lecture contexte merchant: `/api/merchant/me`, `/api/merchants/me/plan-usage`
- lecture cards merchant: `GET /api/loyalty_cards?merchant=...`
- scan/increment points: `POST /api/transactions`
- lecture transactions: `GET /api/transactions?merchant=...`
- lecture rewards + claim qr:
  - `GET /api/rewards`
  - `GET /api/rewards/by-card/{cardId}`
  - `POST /api/rewards/claim-by-qr`

Interdire (UI masquee/disabled):
- creation/update/delete loyalty cards
- mutation admin des rewards:
  - `POST /api/rewards/from-completion`
  - `PATCH /api/rewards/{id}`
- gestion equipe equipier (owner only)

---

## 6) UX de switch dashboard
Afficher le switch si:
- `roles` contient `ROLE_CUSTOMER`
- et (`roles` contient `ROLE_MERCHANT` ou `ROLE_EQUIPIER`)

Regles de routage:
- Onglet Customer -> routes customer existantes
- Onglet Merchant ->
  - owner: dashboard complet
  - equipier: dashboard merchant limite (scanner + rewards operationnel)

---

## 7) Codes erreurs a gerer cote front

- `Unauthorized` (401)
- `Merchant not found` (404)
- `Customer not found` (404)
- `Staff customer not found` (404)
- `customer_user_required` (409)
- `customer_already_staff_for_other_merchant` (409)
- `Forbidden` (403)

Recommandation UX:
- Mapper les codes metier vers messages orientes action.
- Exemple `customer_user_required`: "Ce client doit d abord avoir un compte utilisateur pour devenir equipier."

---

## 8) Checklist implementation front

1. Decoder JWT et stocker `roles`, `is_equipier`, `merchant_id`, `equipier_merchant_id`, `customer_id`.
2. Adapter guard/routes pour accepter le mode equipier sur login merchant.
3. Ajouter switch dashboard customer/merchant.
4. Ajouter ecran gestion equipiers (owner only): list + add + remove.
5. Adapter permissions UI bouton par bouton selon matrice.
6. Verifier que les appels owner only sont bloques en mode equipier.
7. Ajouter tests front:
   - affichage switch
   - equipier login merchant
   - masquage actions owner only
   - promotion/retrait equipier

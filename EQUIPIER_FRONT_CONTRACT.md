# Contrat Front - Fonctionnalite Équipier

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
    "owner_user_id": 12,
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

## 3) Endpoints equipier/admin merchant (gestion par ROLE_MERCHANT)

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
    "is_merchant_admin": false,
    "is_owner": false,
    "owner_user_id": 12,
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
- 409 `owner_role_change_forbidden`
- 409 `merchant_admin_minimum_required` (si la suppression retirerait le dernier admin merchant)

### GET /api/merchants/me/staff/merchant-admins
Lister les staffs ayant le role `ROLE_MERCHANT` (exclut l'utilisateur courant).

Response 200:
```json
[
  {
    "id": 45,
    "name": "Alice",
    "email": "alice@x.com",
    "phone": null,
    "is_equipier": true,
    "is_merchant_admin": true,
    "is_owner": false,
    "owner_user_id": 12,
    "equipier_merchant_id": "uuid",
    "equipier_assigned_at": "2026-04-29T10:00:00+00:00",
    "roles": ["ROLE_USER", "ROLE_CUSTOMER", "ROLE_MERCHANT"]
  }
]
```

### POST /api/merchants/me/staff/{customerId}/merchant-role
Upgrade equipier -> merchant admin.

Regle metier critique:
- Cible autorisee uniquement si elle est deja equipier (role `ROLE_EQUIPIER` + staff du merchant).

Effet role:
- Retire `ROLE_EQUIPIER`
- Ajoute `ROLE_MERCHANT`

Response 200: meme shape qu un item de liste staff.

Erreurs:
- 404 `Customer not found`
- 409 `customer_not_equipier_for_merchant`
- 409 `customer_not_equipier_role`
- 409 `customer_user_required`

### POST /api/merchants/me/staff/{customerId}/equipier-role
Downgrade merchant admin -> equipier.

Effet role:
- Retire `ROLE_MERCHANT`
- Ajoute `ROLE_EQUIPIER`

Regle securite:
- Interdit si cela laisse le merchant sans admin (`merchant_admin_minimum_required`).

Erreurs:
- 404 `Staff customer not found`
- 409 `customer_not_merchant_admin`
- 409 `owner_role_change_forbidden`
- 409 `merchant_admin_self_downgrade_forbidden`
- 409 `merchant_admin_minimum_required`

### POST /api/merchants/me/staff/{customerId}/transfer-ownership
Transferer le owner du merchant vers un admin merchant staff.

Regles metier:
- Endpoint owner-only: seul l'owner courant peut l'appeler.
- Cible obligatoire: customer staff du merchant + role `ROLE_MERCHANT`.
- Lien owner direct transfere vers la cible.

Response 200:
```json
{
  "success": true,
  "merchant_id": "uuid",
  "previous_owner_user_id": 12,
  "new_owner": {
    "id": 45,
    "name": "Alice",
    "email": "alice@x.com",
    "phone": null,
    "is_equipier": false,
    "is_merchant_admin": true,
    "is_owner": true,
    "owner_user_id": 45,
    "equipier_merchant_id": null,
    "equipier_assigned_at": null,
    "roles": ["ROLE_USER", "ROLE_CUSTOMER", "ROLE_MERCHANT"]
  }
}
```

Erreurs:
- 403 `owner_only_action`
- 404 `Staff customer not found`
- 409 `customer_user_required`
- 409 `customer_not_merchant_admin`
- 409 `owner_already_current`
- 409 `target_already_owner_for_other_merchant`

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

### Admin merchant (`ROLE_MERCHANT` owner ou staff admin)
Autoriser toutes les actions merchant existantes.

### Équipier (`ROLE_EQUIPIER`)
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
- gestion équipe equipier/admin (ROLE_MERCHANT)

### Merchant admin staff promu (`ROLE_MERCHANT` sur un customer staff)
Autoriser les memes vues/sections admin merchant cote front que le owner, selon ta matrice UI.

Important:
- L action "upgrade to merchant" doit etre visible uniquement pour les profils staff qui ont deja `ROLE_EQUIPIER`.
- Ne jamais afficher l action upgrade pour un profil qui n est pas equipier.
- Ne jamais afficher l action downgrade sur l utilisateur courant.
- Ne jamais afficher downgrade/unassign pour un profil marque `is_owner = true`.
- Afficher l action "Transfer ownership" uniquement si `merchant_context.is_owner = true`.
- Bloquer UX si la liste admin restante serait vide (defense en profondeur en plus du backend).

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
- `customer_not_equipier_for_merchant` (409)
- `customer_not_equipier_role` (409)
- `customer_not_merchant_admin` (409)
- `owner_role_change_forbidden` (409)
- `merchant_admin_self_downgrade_forbidden` (409)
- `merchant_admin_minimum_required` (409)
- `owner_only_action` (403)
- `owner_already_current` (409)
- `target_already_owner_for_other_merchant` (409)
- `Forbidden` (403)

Recommandation UX:
- Mapper les codes metier vers messages orientes action.
- Exemple `customer_user_required`: "Ce client doit d abord avoir un compte utilisateur pour devenir equipier."

---

## 8) Checklist implementation front

1. Decoder JWT et stocker `roles`, `is_equipier`, `merchant_id`, `equipier_merchant_id`, `customer_id`.
2. Adapter guard/routes pour accepter le mode equipier sur login merchant.
3. Ajouter switch dashboard customer/merchant.
4. Ajouter ecran gestion equipiers/admins (ROLE_MERCHANT):
  - list equipiers
  - list admins staff (endpoint dedie)
  - add/remove equipier
  - upgrade equipier -> merchant
  - downgrade merchant -> equipier
  - transfer ownership (owner only)
  - garde-fou front: bouton upgrade visible uniquement si target est deja equipier
5. Adapter permissions UI bouton par bouton selon matrice.
6. Verifier que les appels ROLE_MERCHANT sont bloques en mode equipier.
7. Ajouter tests front:
   - affichage switch
   - equipier login merchant
  - masquage actions ROLE_MERCHANT
   - promotion/retrait equipier

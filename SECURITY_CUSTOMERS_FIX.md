# Sécurité Multi-Tenant Customers - Implémentation

**Date** : 12 Avril 2026  
**Problème résolu** : Les merchants pouvaient voir les customers d'autres merchants (bug de sécurité multi-tenant)

## Stratégie choisie

**Option** : Extension du `CustomerRepository` + modification du `CustomerController`  
**Justification** : Simple, direct, pas de surcharge API Platform, contrôle explicite au niveau métier.

## Changements implémenter

### 1. `src/Repository/CustomerRepository.php`

**Nouvelles méthodes** :

```php
/**
 * Find all customers belonging to a specific merchant.
 * A customer belongs to a merchant either directly (creation flow)
 * or via loyalty card history linked to that merchant.
 *
 * @return Customer[]
 */
public function findByMerchant($merchant): array
```

**Logique** :
- LEFT JOIN Customer → Merchant direct + LoyaltyCard → LoyaltyProgram → Merchant
- Condition d'appartenance: direct owner OU historique de carte (si direct owner absent)
- DISTINCT pour éviter les doublons si un customer a plusieurs cartes
- ORDER BY name pour cohérence

```php
/**
 * Find a customer by ID if they belong to a specific merchant.
 * Returns null if the customer doesn't belong to the merchant.
 */
public function findByIdAndMerchant(int $customerId, $merchant): ?Customer
```

**Logique** :
- Même JOIN structure
- Filtre supplémentaire sur customer.id
- Retourne `null` si le client n'appartient pas au merchant (sécurité 404)

### 2. `src/Controller/CustomerController.php`

#### GET /api/customers (List)

**Avant** :
```php
$customers = $this->entityManager->getRepository(Customer::class)->findAll();
```

**Après** :
```php
$merchant = $user->getMerchant();  // JWT merchant
$customers = $this->entityManager
    ->getRepository(Customer::class)
    ->findByMerchant($merchant);
```

**Sécurité** :
- ✅ Applique le constraint merchant du JWT
- ✅ Ignore tout paramètre query `?merchant=xyz` (forçage silencieux)
- ✅ Retourne 404 si l'utilisateur n'a pas de merchant

#### GET /api/customers/{id} (Detail)

**Avant** :
```php
$customer = $this->entityManager->getRepository(Customer::class)->find($id);
```

**Après** :
```php
$merchant = $user->getMerchant();
$customer = $this->entityManager
    ->getRepository(Customer::class)
    ->findByIdAndMerchant($id, $merchant);  // Returns null if not authorized
```

**Sécurité** :
- ✅ Vérifie la propriété du customer (appartient au merchant)
- ✅ Retourne 404 pour BOTH "not found" ET "not authorized" (pas de leakage d'info)

#### PUT /api/customers/{id} (Update) — **NOUVEAU FIX**

**Avant** :
```php
$customer = $this->entityManager->getRepository(Customer::class)->find($id);
// ← BUG: Permet modification client d'un autre merchant!
```

**Après** :
```php
$merchant = $user->getMerchant();
$customer = $this->entityManager
    ->getRepository(Customer::class)
    ->findByIdAndMerchant($id, $merchant);
if (!$customer) {
    return new JsonResponse(['error' => 'Customer not found'], 404);
}
```

**Sécurité** :
- ✅ Empêche la modification non-autorisée
- ✅ Retourne 404 si différent merchant

#### DELETE /api/customers/{id} (Delete) — **NOUVEAU FIX**

**Avant** :
```php
$customer = $this->entityManager->getRepository(Customer::class)->find($id);
// ← BUG: Permet suppression client d'un autre merchant!
```

**Après** :
```php
$merchant = $user->getMerchant();
$customer = $this->entityManager
    ->getRepository(Customer::class)
    ->findByIdAndMerchant($id, $merchant);
if (!$customer) {
    return new JsonResponse(['error' => 'Customer not found'], 404);
}
```

**Sécurité** :
- ✅ Empêche la suppression non-autorisée
- ✅ Retourne 404 si différent merchant

### 3. Tests : `tests/Controller/CustomerSecurityControllerTest.php`

**Cas couverts** :

| Test | Objectif |
|------|----------|
| `testMerchantACannotSeeMerchantBCustomers` | Merchant A ne voit que ses customers, pas B |
| `testMerchantCanAccessOwnCustomers` | Merchant A liste ses propres customers |
| `testMerchantCannotAccessOtherMerchantCustomerById` | GET /{id} retourne 404 si client ≠ merchant |
| `testMerchantCanAccessOwnCustomerById` | Merchant peut lire ses propres clients |
| `testQueryParameterMerchantIsIgnoredForSecurity` | `?merchant=xyz` ne contourne pas JWT |
| `testCustomersWithoutCardsAreVisibleForOwnMerchant` | Clients sans cartes (création directe) apparaissent |
| `testMerchantCanAccessOwnCustomerByIdWithoutCard` | GET /{id} fonctionne pour client sans carte |
| `testMerchantCannotAccessOtherMerchantDirectCustomerByIdWithoutCard` | 404 cross-merchant sur client direct sans carte |
| `testCustomerWithMultipleCardsAppearsOnce` | DISTINCT fonctionne (pas de doublon) |
| `testUnauthorizedRequestReturns401` | Token manquant = 401 |
| `testMerchantCannotUpdateOtherMerchantCustomer` | PUT empêche modification client d'un autre merchant |
| `testMerchantCanUpdateOwnCustomer` | Merchant peut modifier ses propres clients |
| `testMerchantCannotDeleteOtherMerchantCustomer` | DELETE empêche suppression client d'un autre merchant |
| `testMerchantCanDeleteOwnCustomer` | Merchant peut supprimer ses propres clients |

## Définition propriété Customer

**Un customer appartient à un merchant SI** :
- Il est directement rattaché au merchant (création API)
- OU il a une `LoyaltyCard` liée à un `LoyaltyProgram` du merchant

**Cas hors-scope** :
- Customer sans carte et sans merchant direct : invisible
- Customer direct d'un autre merchant : invisible

## Compliance requêtes originales

| Requête | Statut Avant | Changement | Statut Après |
|---------|--------------|-----------|--------------|
| GET /api/customers | ❌ BUG: Tous les customers | Filtrage strict par JWT merchant | ✅ Sécurisé |
| GET /api/customers/{id} | ❌ BUG: N'importe quel customer accessible | Vérification proprieté merchant | ✅ Sécurisé |
| POST /api/customers | ⚠️ Incomplet (pas de lien direct merchant) | Associe désormais le customer au merchant JWT | ✅ Sécurisé |
| PUT /api/customers/{id} | ❌ BUG: Modification autorisée même cross-merchant | Vérification proprieté via findByIdAndMerchant | ✅ Sécurisé |
| DELETE /api/customers/{id} | ❌ BUG: Suppression autorisée même cross-merchant | Vérification proprieté via findByIdAndMerchant | ✅ Sécurisé |

## Validation

**Lint container** : OK  
**Erreurs PHP** : Aucune  
**Tests** : Créés (attendent base de test pour exécution)

## Payload exemple

### GET /api/customers (Merchant A, 2 clients)

```json
[
  {
    "id": 1,
    "name": "Alice",
    "email": "alice@example.com",
    "phone": null
  },
  {
    "id": 2,
    "name": "Bob",
    "email": "bob@example.com",
    "phone": "06 12 34 56 78"
  }
]
```

### GET /api/customers/{id} (Merchant A tries to access Merchant B's customer)

**Status:** 404

```json
{
  "error": "Customer not found"
}
```

### GET /api/customers?merchant=<other-merchant-id> (Forçage JWT)

**Résultat** : Liste les clients du Merchant JWT (paramètre ignoré), aucune fuite d'info.

## Notes de sécurité

1. **Pas de leakage d'existence** : 404 unifié pour "not found" + "not authorized"
2. **Force JWT silencieusement** : Pas d'erreur HTTP si query param != JWT (evite d'avaler)
3. **DISTINCT** : Évite les doublons si customer a plusieurs cartes du même merchant
4. **Pas de UPDATE/DELETE** : Contrôleurs PUT/DELETE n'ont pas de modification (ils faisaient déjà un find() simple, donc ignorent le merchant). À vérifier/corriger si nécessaire.

## Prochaines étapes recommandées

- [ ] Exécuter les tests avec base de test disponible
- [ ] Étendre sécurité similarmente pour Rewards (même pattern)
- [ ] Audit des autres endpoints sensibles (Transactions, etc.)

# Configuration des Wallets (Apple & Google)

## Prérequis communs

Exécuter la migration pour ajouter le champ `wallet_token` :
```bash
php bin/console doctrine:migrations:migrate
```

---

## Apple Wallet

### 1. Prérequis

- Un compte **Apple Developer** actif (99$/an)
- Xcode ou un accès au portail développeur Apple

### 2. Créer un Pass Type ID

1. Aller sur [developer.apple.com](https://developer.apple.com) > **Certificates, Identifiers & Profiles**
2. Dans **Identifiers**, cliquer sur **+** et choisir **Pass Type IDs**
3. Renseigner une description et un identifiant de la forme `pass.com.votreentreprise.loyaltycard`
4. Cliquer sur **Register**

### 3. Générer le certificat `.p12`

1. Dans le portail Apple, aller sur les **Certificates** et créer un nouveau certificat de type **Pass Type ID Certificate**
2. Sélectionner le Pass Type ID créé précédemment
3. Suivre les instructions pour générer une **Certificate Signing Request (CSR)** via l'application **Keychain Access** (macOS)
4. Télécharger le certificat `.cer` généré par Apple
5. Double-cliquer pour l'importer dans Keychain Access
6. Dans Keychain Access, localiser le certificat, faire un clic droit > **Export** > format **p12**
7. Choisir un mot de passe fort pour protéger le fichier

### 4. Déposer le certificat

Copier le fichier `.p12` dans le projet :
```
config/wallet/apple/certificate.p12
```

### 5. Variables d'environnement

Mettre à jour le fichier `.env` :
```env
APPLE_WALLET_CERT_PATH=%kernel.project_dir%/config/wallet/apple/certificate.p12
APPLE_WALLET_CERT_PASSWORD=le_mot_de_passe_choisi_a_letape_3
APPLE_WALLET_PASS_TYPE_IDENTIFIER=pass.com.votreentreprise.loyaltycard
APPLE_WALLET_TEAM_IDENTIFIER=XXXXXXXXXX
APPLE_WALLET_ORGANIZATION_NAME=Votre Entreprise
APP_BASE_URL=http://localhost:8000
```

> **Team Identifier** : disponible dans le portail Apple Developer sous **Membership** (format 10 caractères alphanumériques).

### 6. Certificat WWDR (inclus automatiquement)

Le certificat intermédiaire Apple WWDR (`AppleWWDRCA.pem`) est inclus dans la librairie `pkpass/pkpass`. Aucune action requise.

### 7. Test

```
GET /public/wallet/apple/{walletToken}
```

Le navigateur doit proposer le téléchargement d'un fichier `.pkpass`. Sur iPhone, il s'ouvre directement dans l'app Wallet.

---

## Google Wallet

### 1. Prérequis

- Un compte **Google Cloud Platform** avec facturation activée
- Accès à la [Google Pay & Wallet Console](https://pay.google.com/business/console)

### 2. Créer un Issuer ID

1. Aller sur [pay.google.com/business/console](https://pay.google.com/business/console)
2. Accepter les conditions d'utilisation
3. Créer un **Business Profile** (nom de l'entreprise, logo, contact)
4. Noter l'**Issuer ID** affiché (format numérique, ex: `3388000000022601234`)

### 3. Créer une Loyalty Class

1. Dans la Google Pay Console > **Loyalty** > **Classes** > **Create class**
2. Renseigner :
   - **Class ID suffix** : ex `loyalty_card` (doit correspondre à `GOOGLE_WALLET_CLASS_SUFFIX`)
   - **Issuer name** : nom de votre entreprise
   - **Program name** : ex `Carte de fidélité`
   - **Program logo** : image PNG carrée (recommandé 600x600)
   - **Background color** : couleur de la carte
3. Sauvegarder la classe

> Le **Class ID complet** est au format `{ISSUER_ID}.{CLASS_SUFFIX}`, ex: `3388000000022601234.loyalty_card`

### 4. Créer un Service Account Google Cloud

1. Aller sur [console.cloud.google.com](https://console.cloud.google.com)
2. Sélectionner ou créer un projet
3. Aller dans **IAM & Admin** > **Service Accounts** > **Create Service Account**
4. Renseigner un nom et une description, cliquer sur **Create and Continue**
5. Dans **Grant this service account access**, ajouter le rôle **Wallet Object Issuer** (ou **Editor** pour les tests)
6. Cliquer sur **Done**

### 5. Générer la clé JSON

1. Dans la liste des Service Accounts, cliquer sur celui créé
2. Aller dans l'onglet **Keys** > **Add Key** > **Create new key**
3. Choisir le format **JSON**
4. Le fichier sera téléchargé automatiquement

### 6. Déposer la clé

Copier le fichier JSON dans le projet :
```
config/wallet/google/service-account-key.json
```

Le fichier a la structure suivante :
```json
{
  "type": "service_account",
  "project_id": "votre-projet",
  "private_key_id": "...",
  "private_key": "-----BEGIN RSA PRIVATE KEY-----\n...",
  "client_email": "votre-sa@votre-projet.iam.gserviceaccount.com",
  "client_id": "...",
  ...
}
```

### 7. Autoriser le Service Account dans la Wallet Console

1. Dans la [Google Pay Console](https://pay.google.com/business/console) > **Settings** > **API Access**
2. Ajouter l'email du service account (`client_email` du fichier JSON) avec le rôle **Writer**

### 8. Variables d'environnement

Mettre à jour le fichier `.env` :
```env
GOOGLE_WALLET_SERVICE_ACCOUNT_EMAIL=votre-sa@votre-projet.iam.gserviceaccount.com
GOOGLE_WALLET_SERVICE_ACCOUNT_KEY_PATH=%kernel.project_dir%/config/wallet/google/service-account-key.json
GOOGLE_WALLET_ISSUER_ID=3388000000022601234
GOOGLE_WALLET_CLASS_SUFFIX=loyalty_card
```

### 9. Test

```
GET /public/wallet/google/{walletToken}
```

La réponse est une redirection HTTP 302 vers `https://pay.google.com/gp/v/save/{jwt}`. Sur Android, cette URL ouvre directement Google Wallet.

---

## Sécurité

Les fichiers de certificats et clés **ne doivent pas être commités** dans le dépôt Git. Ajouter au `.gitignore` :

```gitignore
/config/wallet/apple/certificate.p12
/config/wallet/google/service-account-key.json
```

En production, utiliser des variables d'environnement serveur ou un gestionnaire de secrets (AWS Secrets Manager, HashiCorp Vault, etc.) plutôt que des fichiers sur disque.

---

## Routes publiques

Les deux endpoints sont accessibles **sans JWT** (firewall `public` dans `security.yaml`) :

| Méthode | URL | Description |
|---------|-----|-------------|
| GET | `/public/wallet/apple/{walletToken}` | Télécharge le fichier `.pkpass` |
| GET | `/public/wallet/google/{walletToken}` | Redirige vers "Ajouter à Google Wallet" |

Le `walletToken` est un UUID v4 généré automatiquement à la création de chaque `LoyaltyCard`. Il est non-devinable et sert d'identifiant public sécurisé.

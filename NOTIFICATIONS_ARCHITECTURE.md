# Architecture Système de Notifications

## 1. Vue d'ensemble

```
NotificationService (service centralisé)
    ↓
Stratégie (plan-based)
    ├─ EmailNotificationStrategy (free, standard, premium)
    └─ PushNotificationStrategy (premium only)
        ↓
Dispatcher (queue async ou sync)
    ├─ Symfony Messenger (recommandé)
    └─ Direct send (sync basique)
```

## 2. Schéma BDD

Ajouter une table audit/logs des notifications :

```sql
CREATE TABLE notification_log (
    id CHAR(36) PRIMARY KEY,
    merchant_id CHAR(36) NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    type ENUM('merchant_signup', 'customer_signup', 'card_created', 'points_added', 'card_completed', 'reward_claimed') NOT NULL,
    subject VARCHAR(255),
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME,
    FOREIGN KEY (merchant_id) REFERENCES merchant(id),
    INDEX (status, created_at)
);
```

## 3. Enums à créer

Fichier : `src/Enum/NotificationType.php`

```php
<?php
namespace App\Enum;

enum NotificationType: string
{
    case MERCHANT_SIGNUP = 'merchant_signup';
    case CUSTOMER_SIGNUP = 'customer_signup';
    case CARD_CREATED = 'card_created';
    case POINTS_ADDED = 'points_added';
    case CARD_COMPLETED = 'card_completed';
    case REWARD_CLAIMED = 'reward_claimed';
}
```

Fichier : `src/Enum/NotificationChannel.php`

```php
<?php
namespace App\Enum;

enum NotificationChannel: string
{
    case EMAIL = 'email';
    case PUSH = 'push';
}
```

## 4. Configuration d'envoi

Ajouter aux dépendances composer :

```bash
composer require symfony/mailer symfony/mime
```

Fichier : `config/packages/mailer.yaml`

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'

when@test:
    framework:
        mailer:
            dsn: 'null://null'
```

Fichier : `.env.local` (exemple)

```
# Mailer
MAILER_DSN=smtp://user:pass@smtp.gmail.com:587?encryption=tls
NOTIFICATIONS_FROM_EMAIL=noreply@coachat.fr
NOTIFICATIONS_FROM_NAME="Co-Achat"

# Messenger (optional, for async)
MESSENGER_TRANSPORT_DSN=doctrine://default
```

## 5. Structure des services

### Service Principal : `NotificationService`

Fichier : `src/Service/NotificationService.php`

```php
<?php
namespace App\Service;

use App\Entity\Merchant;
use App\Enum\NotificationType;
use App\Enum\NotificationChannel;
use Psr\Log\LoggerInterface;

class NotificationService
{
    public function __construct(
        private readonly EmailNotificationStrategy $emailStrategy,
        private readonly PushNotificationStrategy $pushStrategy,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Détermine les canaux à utiliser en fonction du plan
     */
    public function send(
        Merchant $merchant,
        NotificationType $type,
        array $context = []
    ): void {
        $plan = $merchant->getPlan();
        $channels = $this->getChannelsForPlan($plan, $type);

        foreach ($channels as $channel) {
            try {
                match ($channel) {
                    NotificationChannel::EMAIL => $this->emailStrategy->send($merchant, $type, $context),
                    NotificationChannel::PUSH => $this->pushStrategy->send($merchant, $type, $context),
                };
            } catch (\Exception $e) {
                $this->logger->error("Notification failed", [
                    'merchant_id' => $merchant->getId(),
                    'type' => $type->value,
                    'channel' => $channel->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Retourne les canaux actifs selon le plan et le type
     */
    private function getChannelsForPlan($plan, NotificationType $type): array
    {
        $channels = [NotificationChannel::EMAIL]; // Tous les plans ont email

        // Push uniquement en plan premium
        if ($plan && $plan->getSlug() === 'premium') {
            $channels[] = NotificationChannel::PUSH;
        }

        return $channels;
    }
}
```

### Strategy EMAIL

Fichier : `src/Service/EmailNotificationStrategy.php`

```php
<?php
namespace App\Service;

use App\Entity\Merchant;
use App\Enum\NotificationType;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class EmailNotificationStrategy implements NotificationStrategyInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {}

    public function send(Merchant $merchant, NotificationType $type, array $context): void
    {
        $emailData = $this->buildEmailData($merchant, $type, $context);

        $email = (new Email())
            ->from("{$this->fromName} <{$this->fromEmail}>")
            ->to($emailData['to'])
            ->subject($emailData['subject'])
            ->html($emailData['body']);

        $this->mailer->send($email);
    }

    private function buildEmailData(Merchant $merchant, NotificationType $type, array $context): array
    {
        return match ($type) {
            NotificationType::MERCHANT_SIGNUP => [
                'to' => $merchant->getEmail(),
                'subject' => 'Bienvenue sur Co-Achat !',
                'body' => $this->renderTemplate('merchant_signup', ['merchant' => $merchant]),
            ],
            NotificationType::CUSTOMER_SIGNUP => [
                'to' => $context['customer_email'],
                'subject' => 'Inscription réussie chez ' . $merchant->getCompanyName(),
                'body' => $this->renderTemplate('customer_signup', [
                    'customer' => $context['customer'],
                    'merchant' => $merchant,
                ]),
            ],
            NotificationType::CARD_CREATED => [
                'to' => $context['customer_email'],
                'subject' => 'Votre carte fidélité est prête !',
                'body' => $this->renderTemplate('card_created', [
                    'customer' => $context['customer'],
                    'card' => $context['card'],
                    'merchant' => $merchant,
                ]),
            ],
            NotificationType::POINTS_ADDED => [
                'to' => $context['customer_email'],
                'subject' => 'Vous avez gagné ' . $context['points'] . ' points !',
                'body' => $this->renderTemplate('points_added', [
                    'points' => $context['points'],
                    'card' => $context['card'],
                    'merchant' => $merchant,
                ]),
            ],
            NotificationType::CARD_COMPLETED => [
                'to' => $context['customer_email'],
                'subject' => 'Carte complétée ! Une récompense vous attend 🎉',
                'body' => $this->renderTemplate('card_completed', [
                    'card' => $context['card'],
                    'reward' => $context['reward'],
                    'merchant' => $merchant,
                ]),
            ],
            NotificationType::REWARD_CLAIMED => [
                'to' => $context['customer_email'],
                'subject' => 'Récompense récupérée avec succès !',
                'body' => $this->renderTemplate('reward_claimed', [
                    'reward' => $context['reward'],
                    'merchant' => $merchant,
                ]),
            ],
        };
    }

    private function renderTemplate(string $name, array $context): string
    {
        // Implémentation simple : tu peux utiliser Twig
        return "Template: $name"; // TODO: utiliser templating
    }
}
```

### Strategy PUSH (stub pour MVP)

Fichier : `src/Service/PushNotificationStrategy.php`

```php
<?php
namespace App\Service;

use App\Entity\Merchant;
use App\Enum\NotificationType;

class PushNotificationStrategy implements NotificationStrategyInterface
{
    public function send(Merchant $merchant, NotificationType $type, array $context): void
    {
        // TODO: implémentation future (Firebase, OneSignal, etc.)
        // Pour l'instant : no-op
    }
}
```

### Interface

Fichier : `src/Service/NotificationStrategyInterface.php`

```php
<?php
namespace App\Service;

use App\Entity\Merchant;
use App\Enum\NotificationType;

interface NotificationStrategyInterface
{
    public function send(Merchant $merchant, NotificationType $type, array $context): void;
}
```

## 6. Intégration dans les entités

### Exemple : Après création d'un customer

Fichier : `src/Controller/CustomerController.php`

```php
#[Route('/api/customers', name: 'create_customer', methods: ['POST'])]
public function create(Request $request, NotificationService $notificationService): JsonResponse
{
    // ... création customer ...

    $customer = new Customer();
    // ... populate ...
    
    $this->entityManager->persist($customer);
    $this->entityManager->flush();

    // Envoyer notification
    $notificationService->send(
        $merchant,
        NotificationType::CUSTOMER_SIGNUP,
        ['customer' => $customer, 'customer_email' => $customer->getEmail()]
    );

    return new JsonResponse([...], 201);
}
```

### Exemple : Après ajout de points

Fichier : `src/Controller/TransactionController.php`

```php
#[Route('/api/transactions', name: 'create_transaction', methods: ['POST'])]
public function create(Request $request, NotificationService $notificationService): JsonResponse
{
    // ... création transaction ...

    $this->entityManager->persist($transaction);
    $this->entityManager->flush();

    // Envoyer notification si points ajoutés
    if ($transaction->getPointsEarned() > 0) {
        $notificationService->send(
            $merchant,
            NotificationType::POINTS_ADDED,
            [
                'card' => $transaction->getLoyaltyCard(),
                'customer_email' => $transaction->getLoyaltyCard()->getCustomer()->getEmail(),
                'points' => $transaction->getPointsEarned(),
            ]
        );
    }

    return new JsonResponse([...], 201);
}
```

## 7. Templates d'email

Dossier : `templates/emails/`

Fichier : `templates/emails/merchant_signup.html.twig`

```html
<h1>Bienvenue sur Co-Achat, {{ merchant.companyName }} !</h1>
<p>Nous sommes heureux de vous accueillir.</p>
<p>Your account is ready to use. Log in here: <a href="{{ loginUrl }}">{{ loginUrl }}</a></p>
<p>Best regards,<br/>Co-Achat Team</p>
```

Fichier : `templates/emails/card_created.html.twig`

```html
<h1>Votre carte fidélité chez {{ merchant.companyName }} est prête !</h1>
<p>Bonjour {{ customer.name }},</p>
<p>Vous êtes inscrit à leur programme fidélité.</p>
<p><a href="{{ cardLink }}">Voir ma carte</a></p>
```

Fichier : `templates/emails/card_completed.html.twig`

```html
<h1>🎉 Carte complétée ! Une récompense vous attend !</h1>
<p>Bravo {{ customer.name }} !</p>
<p>Vous avez complété votre carte et pouvez maintenant récupérer :</p>
<p><strong>{{ reward.description }}</strong></p>
<p><a href="{{ claimLink }}">Récupérer ma récompense</a></p>
```

## 8. Configuration de service (services.yaml)

Fichier : `config/services.yaml` (ajouter les mappings)

```yaml
services:
    App\Service\NotificationService:
        arguments:
            $fromEmail: '%env(NOTIFICATIONS_FROM_EMAIL)%'
            $fromName: '%env(NOTIFICATIONS_FROM_NAME)%'

    App\Service\EmailNotificationStrategy:
        arguments:
            $fromEmail: '%env(NOTIFICATIONS_FROM_EMAIL)%'
            $fromName: '%env(NOTIFICATIONS_FROM_NAME)%'
```

## 9. Plan d'implémentation MVP (Free + Standard)

### Phase 1 : Setup (1-2h)
- [ ] Installer Symfony Mailer
- [ ] Créer enums NotificationType et NotificationChannel
- [ ] Créer les trois services (NotificationService, EmailStrategy, PushStrategy stub)

### Phase 2 : Templates (2-3h)
- [ ] Créer templates Twig pour les 6 types de notifications
- [ ] Tester rendu en mode test

### Phase 3 : Intégration (3-4h)
- [ ] Intégrer dans CustomerController
- [ ] Intégrer dans TransactionController
- [ ] Intégrer dans RewardController
- [ ] Tests unitaires

### Phase 4 : Maintenance (1h)
- [ ] Ajouter table notification_log pour audit
- [ ] Dashboard simple pour voir les envois

## 10. Cas futur : Premium + Push

Quand tu implémenteras premium + push :

```php
// Dans NotificationService::getChannelsForPlan()
if ($plan && $plan->getSlug() === 'premium') {
    $channels[] = NotificationChannel::PUSH;
}

// Implémentation Firebase/OneSignal dans PushNotificationStrategy
class PushNotificationStrategy implements NotificationStrategyInterface
{
    public function __construct(private readonly OneSignalClient $oneSignal) {}

    public function send(Merchant $merchant, NotificationType $type, array $context): void
    {
        $pushData = $this->buildPushData($merchant, $type, $context);
        $this->oneSignal->notify($context['device_tokens'], $pushData);
    }
}
```

## 11. Checklist avant prod

- [ ] Configurer MAILER_DSN pour production
- [ ] Mettre NOTIFICATIONS_FROM_EMAIL correcte
- [ ] Tester un email réel en staging
- [ ] Vérifier pas de double send
- [ ] Implémenter rate limiting (max notifications par client/jour)
- [ ] Logger tous les envois échoués
- [ ] Créer CRONs de retry si async

## 12. Exemple complet : "Carte complétée"

```php
// Dans RewardController ou TransactionService
if ($loyaltyCard->isCompleted()) {
    $reward = $loyaltyCard->getLoyaltyProgram()->getRewardDescription();
    
    $notificationService->send(
        $merchant,
        NotificationType::CARD_COMPLETED,
        [
            'card' => $loyaltyCard,
            'reward' => $reward,
            'customer_email' => $loyaltyCard->getCustomer()->getEmail(),
        ]
    );
}
```

---

## Points clés

✅ **Centralisé** : Un seul service pour tout  
✅ **Plan-aware** : Logique de canal selon le plan  
✅ **Extensible** : Facile d'ajouter email/SMS/push  
✅ **Testable** : Strategies découplées  
✅ **Observable** : Logs et audit trail  
✅ **Production-ready** : Gestion erreur, replay, rate-limit possible

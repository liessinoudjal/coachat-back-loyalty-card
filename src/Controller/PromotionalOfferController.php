<?php

namespace App\Controller;

use App\Entity\Merchant;
use App\Entity\PromotionalOffer;
use App\Repository\PromotionalOfferRepository;
use App\Service\PromotionalOfferNotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class PromotionalOfferController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PromotionalOfferRepository $offerRepository,
        private readonly PromotionalOfferNotificationDispatcher $notificationDispatcher,
        private readonly LoggerInterface $logger,
        private readonly string $promotionalOffersCronToken,
        private readonly string $promotionalOffersCronBasicUser,
        private readonly string $promotionalOffersCronBasicPassword,
        private readonly string $promotionalOffersCronHeaderName,
        private readonly string $promotionalOffersCronHeaderValue,
    ) {
    }

    #[Route('/api/merchants/me/promotional-offers', name: 'merchant_promotional_offer_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $user = $this->getUser();
        $this->logger->info('promotional_offer.list.request_received', [
            'user_id' => method_exists($user, 'getId') ? (string) $user->getId() : null,
            'user_email' => method_exists($user, 'getEmail') ? (string) $user->getEmail() : null,
            'user_roles' => method_exists($user, 'getRoles') ? $user->getRoles() : [],
        ]);

        $merchant = $this->resolveMerchantOwner();
        if (!$merchant instanceof Merchant) {
            $actorMerchant = $this->resolveActorMerchant();
            $this->logger->warning('promotional_offer.list.access_denied_owner_required', [
                'user_id' => method_exists($user, 'getId') ? (string) $user->getId() : null,
                'user_email' => method_exists($user, 'getEmail') ? (string) $user->getEmail() : null,
                'resolved_actor_merchant_id' => $actorMerchant?->getId()?->toRfc4122(),
            ]);

            return new JsonResponse(['error' => 'promotional_offers_restricted_to_owner'], 403);
        }

        $today = new \DateTimeImmutable('today');
        $offers = $this->offerRepository->findByMerchantOrdered($merchant);

        $hasActive = false;
        $hasUpcoming = false;
        $statusBreakdown = [
            'active' => 0,
            'upcoming' => 0,
            'past' => 0,
            'unknown' => 0,
        ];
        $offersDebug = [];

        foreach ($offers as $offer) {
            $status = $this->resolveStatus($offer, $today);

            if (!array_key_exists($status, $statusBreakdown)) {
                $statusBreakdown['unknown']++;
            } else {
                $statusBreakdown[$status]++;
            }

            $offersDebug[] = [
                'id' => $offer->getId(),
                'starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
                'ends_on' => $offer->getEndsOn()?->format('Y-m-d'),
                'status' => $status,
                'is_editable' => $this->isEditable($offer, $today),
            ];

            if ($status === 'active') {
                $hasActive = true;
            }

            if ($status === 'upcoming') {
                $hasUpcoming = true;
            }
        }

        $this->logger->info('promotional_offer.list.loaded', [
            'merchant_id' => $merchant->getId()?->toRfc4122(),
            'today' => $today->format('Y-m-d'),
            'total_offers' => count($offers),
            'status_breakdown' => $statusBreakdown,
            'offers' => $offersDebug,
        ]);

        return new JsonResponse([
            'items' => array_map(fn (PromotionalOffer $offer) => $this->formatOffer($offer, $today), $offers),
            'summary' => [
                'has_any' => count($offers) > 0,
                'has_active' => $hasActive,
                'has_upcoming' => $hasUpcoming,
            ],
        ]);
    }

    #[Route('/api/merchants/me/promotional-offers', name: 'merchant_promotional_offer_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveMerchantOwner();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'promotional_offers_restricted_to_owner'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'payload_invalid'], 400);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $startsOn = $this->parseDate($payload['starts_on'] ?? null);
        $endsOn = $this->parseDate($payload['ends_on'] ?? null);

        $validationError = $this->validatePayload($title, $description, $startsOn, $endsOn);
        if ($validationError !== null) {
            return new JsonResponse(['error' => $validationError], 422);
        }

        $offer = (new PromotionalOffer())
            ->setMerchant($merchant)
            ->setTitle($title)
            ->setDescription($description)
            ->setStartsOn($startsOn)
            ->setEndsOn($endsOn);

        $this->entityManager->persist($offer);
        $this->entityManager->flush();

        return new JsonResponse($this->formatOffer($offer, new \DateTimeImmutable('today')), 201);
    }

    #[Route('/api/merchants/me/promotional-offers/{id}', name: 'merchant_promotional_offer_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveMerchantOwner();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'promotional_offers_restricted_to_owner'], 403);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer instanceof PromotionalOffer || $offer->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'promotional_offer_not_found'], 404);
        }

        $today = new \DateTimeImmutable('today');
        if (!$this->isEditable($offer, $today)) {
            return new JsonResponse(['error' => 'promotional_offer_not_editable_after_start_date'], 409);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'payload_invalid'], 400);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $startsOn = $this->parseDate($payload['starts_on'] ?? null);
        $endsOn = $this->parseDate($payload['ends_on'] ?? null);

        $validationError = $this->validatePayload($title, $description, $startsOn, $endsOn);
        if ($validationError !== null) {
            return new JsonResponse(['error' => $validationError], 422);
        }

        $offer
            ->setTitle($title)
            ->setDescription($description)
            ->setStartsOn($startsOn)
            ->setEndsOn($endsOn);

        $this->entityManager->flush();

        return new JsonResponse($this->formatOffer($offer, $today));
    }

    #[Route('/api/merchants/me/promotional-offers/{id}', name: 'merchant_promotional_offer_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_MERCHANT');

        $merchant = $this->resolveMerchantOwner();
        if (!$merchant instanceof Merchant) {
            return new JsonResponse(['error' => 'promotional_offers_restricted_to_owner'], 403);
        }

        $offer = $this->offerRepository->find($id);
        if (!$offer instanceof PromotionalOffer || $offer->getMerchant() !== $merchant) {
            return new JsonResponse(['error' => 'promotional_offer_not_found'], 404);
        }

        $today = new \DateTimeImmutable('today');
        if (!$this->isEditable($offer, $today)) {
            return new JsonResponse(['error' => 'promotional_offer_not_deletable_after_start_date'], 409);
        }

        $this->entityManager->remove($offer);
        $this->entityManager->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/api/promotional-offers/daily-dispatch', name: 'promotional_offer_daily_dispatch', methods: ['GET', 'POST'])]
    public function dailyDispatch(Request $request): JsonResponse
    {
        $this->logger->info('promotional_offer.daily_dispatch.request_received', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'has_cron_token_header' => $request->headers->has('X-Cron-Token'),
            'has_authorization_header' => $request->headers->has('Authorization'),
            'custom_header_name' => $this->promotionalOffersCronHeaderName,
            'has_custom_header' => $this->promotionalOffersCronHeaderName !== ''
                ? $request->headers->has($this->promotionalOffersCronHeaderName)
                : false,
        ]);

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            $body = [];
        }

        $providedToken = trim((string) (
            $request->headers->get('X-Cron-Token')
            ?? $request->query->get('token')
            ?? ($body['token'] ?? '')
        ));
        $expectedToken = trim($this->promotionalOffersCronToken);

        if ($expectedToken === '') {
            $this->logger->error('promotional_offer.daily_dispatch.misconfigured_token');

            return new JsonResponse(['error' => 'promotional_offer_cron_token_not_configured'], 503);
        }

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $this->logger->warning('promotional_offer.daily_dispatch.unauthorized_token', [
                'provided_token_length' => strlen($providedToken),
            ]);

            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        if (!$this->isBasicAuthValid($request)) {
            $this->logger->warning('promotional_offer.daily_dispatch.unauthorized_basic_auth', [
                'provided_basic_user' => $request->getUser(),
            ]);

            return new JsonResponse(['error' => 'unauthorized_basic_auth'], 401);
        }

        if (!$this->isCustomHeaderValid($request)) {
            $this->logger->warning('promotional_offer.daily_dispatch.unauthorized_custom_header', [
                'header_name' => $this->promotionalOffersCronHeaderName,
                'header_present' => $this->promotionalOffersCronHeaderName !== ''
                    ? $request->headers->has($this->promotionalOffersCronHeaderName)
                    : false,
            ]);

            return new JsonResponse(['error' => 'unauthorized_custom_header'], 401);
        }

        $today = new \DateTimeImmutable('today');
        $this->logger->info('promotional_offer.daily_dispatch.auth_passed', [
            'date' => $today->format('Y-m-d'),
        ]);

        $result = $this->notificationDispatcher->dispatch($today);

        $this->logger->info('promotional_offer.daily_dispatch.completed', [
            'date' => $today->format('Y-m-d'),
            'result' => $result,
        ]);

        return new JsonResponse([
            'date' => $today->format('Y-m-d'),
            ...$result,
        ]);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        if (!$date instanceof \DateTimeImmutable) {
            return null;
        }

        return $date->setTime(0, 0, 0);
    }

    private function validatePayload(
        string $title,
        string $description,
        ?\DateTimeImmutable $startsOn,
        ?\DateTimeImmutable $endsOn,
    ): ?string {
        if ($title === '') {
            return 'title_required';
        }

        if (mb_strlen($title) > 160) {
            return 'title_too_long';
        }

        if ($description === '') {
            return 'description_required';
        }

        if ($startsOn === null) {
            return 'starts_on_invalid';
        }

        if ($endsOn === null) {
            return 'ends_on_invalid';
        }

        $minimumStartDate = (new \DateTimeImmutable('today'))->modify('+1 day');
        if ($startsOn < $minimumStartDate) {
            return 'starts_on_must_be_j_plus_1';
        }

        if ($endsOn < $minimumStartDate) {
            return 'ends_on_must_be_j_plus_1';
        }

        if ($endsOn < $startsOn) {
            return 'date_range_invalid';
        }

        return null;
    }

    private function formatOffer(PromotionalOffer $offer, \DateTimeImmutable $today): array
    {
        return [
            'id' => $offer->getId(),
            'merchant_id' => $offer->getMerchant()?->getId()?->toRfc4122(),
            'title' => $offer->getTitle(),
            'description' => $offer->getDescription(),
            'starts_on' => $offer->getStartsOn()?->format('Y-m-d'),
            'ends_on' => $offer->getEndsOn()?->format('Y-m-d'),
            'status' => $this->resolveStatus($offer, $today),
            'is_editable' => $this->isEditable($offer, $today),
            'start_notification_sent_at' => $offer->getStartNotificationSentAt()?->format(DATE_ATOM),
            'ending_soon_notification_sent_at' => $offer->getEndingSoonNotificationSentAt()?->format(DATE_ATOM),
            'created_at' => $offer->getCreatedAt()?->format(DATE_ATOM),
            'updated_at' => $offer->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }

    private function resolveStatus(PromotionalOffer $offer, \DateTimeImmutable $today): string
    {
        $startsOn = $offer->getStartsOn();
        $endsOn = $offer->getEndsOn();

        if (!$startsOn instanceof \DateTimeImmutable || !$endsOn instanceof \DateTimeImmutable) {
            return 'unknown';
        }

        $todayDate = $today->format('Y-m-d');
        $startsOnDate = $startsOn->format('Y-m-d');
        $endsOnDate = $endsOn->format('Y-m-d');

        if ($todayDate < $startsOnDate) {
            return 'upcoming';
        }

        if ($todayDate > $endsOnDate) {
            return 'past';
        }

        return 'active';
    }

    private function isEditable(PromotionalOffer $offer, \DateTimeImmutable $today): bool
    {
        $startsOn = $offer->getStartsOn();
        if (!$startsOn instanceof \DateTimeImmutable) {
            return false;
        }

        return $today->format('Y-m-d') < $startsOn->format('Y-m-d');
    }

    private function isBasicAuthValid(Request $request): bool
    {
        $expectedUser = trim($this->promotionalOffersCronBasicUser);
        $expectedPassword = trim($this->promotionalOffersCronBasicPassword);

        if ($expectedUser === '' && $expectedPassword === '') {
            return true;
        }

        $providedUser = (string) ($request->getUser() ?? '');
        $providedPassword = (string) ($request->getPassword() ?? '');

        if ($providedUser === '' || $providedPassword === '') {
            return false;
        }

        return hash_equals($expectedUser, $providedUser) && hash_equals($expectedPassword, $providedPassword);
    }

    private function isCustomHeaderValid(Request $request): bool
    {
        $headerName = trim($this->promotionalOffersCronHeaderName);
        $headerValue = trim($this->promotionalOffersCronHeaderValue);

        if ($headerName === '' || $headerValue === '') {
            return true;
        }

        $providedValue = (string) ($request->headers->get($headerName) ?? '');
        if ($providedValue === '') {
            return false;
        }

        return hash_equals($headerValue, $providedValue);
    }

    private function resolveMerchantOwner(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        // Direct merchant owner: user.merchant exists
        $merchant = $user->getMerchant();
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        // Merchant admin: staff member with ROLE_MERCHANT and staff_merchant relation
        if (in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            $customer = $user->getCustomer();
            if ($customer !== null) {
                $staffMerchant = $customer->getStaffMerchant();
                if ($staffMerchant instanceof Merchant) {
                    return $staffMerchant;
                }
            }
        }

        return null;
    }

    private function resolveActorMerchant(): ?Merchant
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }

        $merchant = $user->getMerchant();
        if ($merchant instanceof Merchant) {
            return $merchant;
        }

        if (!in_array('ROLE_MERCHANT', $user->getRoles(), true)) {
            return null;
        }

        return $user->getCustomer()?->getStaffMerchant();
    }
}
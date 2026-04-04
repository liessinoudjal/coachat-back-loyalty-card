<?php

namespace App\Controller;

use App\Entity\DeviceRegistration;
use App\Repository\DeviceRegistrationRepository;
use App\Repository\LoyaltyCardRepository;
use App\Service\ApplePassService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Implements the Apple PassKit Web Service protocol.
 * Routes are prefixed with /public/wallet/apple/update/ which matches the
 * webServiceURL set in the .pkpass file.
 *
 * Authentication: Apple sends "Authorization: ApplePass <authenticationToken>"
 * where authenticationToken == card's walletToken.
 */
#[Route('/public/wallet/apple/update')]
class ApplePassKitController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DeviceRegistrationRepository $deviceRegistrationRepository,
        private readonly LoyaltyCardRepository $loyaltyCardRepository,
        private readonly ApplePassService $applePassService,
    ) {}

    /**
     * Register a device to receive push notifications for a pass.
     * POST /v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}
     * Body: { "pushToken": "<token>" }
     */
    #[Route(
        '/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}',
        name: 'passkit_register_device',
        methods: ['POST'],
    )]
    public function registerDevice(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
        Request $request,
    ): Response {
        if (!$this->isAuthorized($request, $serialNumber)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $pushToken = $data['pushToken'] ?? null;
        if (!$pushToken) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->deviceRegistrationRepository->findOneByDeviceAndSerial(
            $deviceLibraryIdentifier,
            $serialNumber,
        );

        if ($existing) {
            // Update push token if it changed
            $existing->setPushToken($pushToken);
            $this->entityManager->flush();
            return new Response('', Response::HTTP_OK);
        }

        $registration = new DeviceRegistration(
            $deviceLibraryIdentifier,
            $pushToken,
            $passTypeIdentifier,
            $serialNumber,
        );
        $this->entityManager->persist($registration);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_CREATED);
    }

    /**
     * Unregister a device from receiving push notifications for a pass.
     * DELETE /v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}
     */
    #[Route(
        '/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}/{serialNumber}',
        name: 'passkit_unregister_device',
        methods: ['DELETE'],
    )]
    public function unregisterDevice(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
        string $serialNumber,
        Request $request,
    ): Response {
        if (!$this->isAuthorized($request, $serialNumber)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->deviceRegistrationRepository->findOneByDeviceAndSerial(
            $deviceLibraryIdentifier,
            $serialNumber,
        );

        if ($existing) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        return new Response('', Response::HTTP_OK);
    }

    /**
     * Get serial numbers of passes that need updating for a device.
     * GET /v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}?passesUpdatedSince={tag}
     */
    #[Route(
        '/v1/devices/{deviceLibraryIdentifier}/registrations/{passTypeIdentifier}',
        name: 'passkit_get_serials',
        methods: ['GET'],
    )]
    public function getSerialNumbers(
        string $deviceLibraryIdentifier,
        string $passTypeIdentifier,
    ): Response {
        $registrations = $this->deviceRegistrationRepository->findByDevice(
            $deviceLibraryIdentifier,
            $passTypeIdentifier,
        );

        if (empty($registrations)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $serials = array_map(
            fn(DeviceRegistration $r) => $r->getSerialNumber(),
            $registrations,
        );

        return new JsonResponse([
            'lastUpdated' => (string) time(),
            'serialNumbers' => $serials,
        ]);
    }

    /**
     * Return the latest version of a pass.
     * GET /v1/passes/{passTypeIdentifier}/{serialNumber}
     */
    #[Route(
        '/v1/passes/{passTypeIdentifier}/{serialNumber}',
        name: 'passkit_get_pass',
        methods: ['GET'],
    )]
    public function getPass(
        string $passTypeIdentifier,
        string $serialNumber,
        Request $request,
    ): Response {
        if (!$this->isAuthorized($request, $serialNumber)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $card = $this->loyaltyCardRepository->findOneBy(['walletToken' => $serialNumber]);
        if (!$card) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $pkpassBinary = $this->applePassService->generatePass($card);

        return new Response($pkpassBinary, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Last-Modified' => gmdate('D, d M Y H:i:s T'),
        ]);
    }

    /**
     * Receive log entries from Apple Wallet.
     * POST /v1/log
     */
    #[Route('/v1/log', name: 'passkit_log', methods: ['POST'])]
    public function log(): Response
    {
        return new Response('', Response::HTTP_OK);
    }

    /**
     * Validate the ApplePass authorization token against the card's walletToken.
     */
    private function isAuthorized(Request $request, string $serialNumber): bool
    {
        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'ApplePass ')) {
            return false;
        }

        $token = substr($authHeader, strlen('ApplePass '));

        return hash_equals($serialNumber, $token);
    }
}

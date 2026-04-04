<?php

namespace App\Controller;

use App\Entity\LoyaltyCard;
use App\Repository\LoyaltyCardRepository;
use App\Service\ApplePassService;
use App\Service\GooglePassService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/public/wallet')]
class WalletController extends AbstractController
{
    public function __construct(
        private readonly LoyaltyCardRepository $loyaltyCardRepository,
        private readonly ApplePassService $applePassService,
        private readonly GooglePassService $googlePassService,
    ) {}

    #[Route('/apple/{walletToken}', name: 'wallet_apple', methods: ['GET'])]
    public function apple(string $walletToken): Response
    {
        $card = $this->loyaltyCardRepository->findOneBy(['walletToken' => $walletToken]);
        if (!$card) {
            return new Response('Card not found', Response::HTTP_NOT_FOUND);
        }

        $pkpassBinary = $this->applePassService->generatePass($card);

        return new Response($pkpassBinary, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="loyalty-card.pkpass"',
        ]);
    }

    #[Route('/google/{walletToken}', name: 'wallet_google', methods: ['GET'])]
    public function google(string $walletToken): RedirectResponse
    {
        $card = $this->loyaltyCardRepository->findOneBy(['walletToken' => $walletToken]);
        if (!$card) {
            return new RedirectResponse('/', Response::HTTP_NOT_FOUND);
        }

        $saveUrl = $this->googlePassService->generateSaveUrl($card);

        return new RedirectResponse($saveUrl, Response::HTTP_FOUND);
    }
}

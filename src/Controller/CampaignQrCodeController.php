<?php

namespace App\Controller;

use App\Entity\CampaignQrCode;
use App\Entity\CampaignQrScanEvent;
use App\Repository\CampaignQrCodeRepository;
use App\Repository\CampaignQrScanEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CampaignQrCodeController extends AbstractController
{
    private const TEMPLATE_KEYS = [
        'partner-light',
        'partner-dark',
        'minimal',
        'sticker-round-light',
        'sticker-round-dark',
        'sticker-round-cyan',
    ];
    private const SLUG_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public function __construct(
        private readonly CampaignQrCodeRepository $campaignRepository,
        private readonly CampaignQrScanEventRepository $scanRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/super-admin/campaign-qr-codes', name: 'super_admin_campaign_qr_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $campaigns = $this->campaignRepository->findBy([], ['createdAt' => 'DESC']);

        return new JsonResponse([
            'items' => array_map(fn (CampaignQrCode $c): array => $this->formatCampaign($c, true), $campaigns),
            'total' => count($campaigns),
        ]);
    }

    #[Route('/api/super-admin/campaign-qr-codes', name: 'super_admin_campaign_qr_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'name_required'], 422);
        }

        $templateKey = (string) ($data['template_key'] ?? 'partner-light');
        if (!in_array($templateKey, self::TEMPLATE_KEYS, true)) {
            return new JsonResponse(['error' => 'invalid_template_key'], 422);
        }

        $targetPath = trim((string) ($data['target_path'] ?? '/'));
        if ($targetPath === '' || $targetPath[0] !== '/') {
            $targetPath = '/';
        }

        $utmCampaign = trim((string) ($data['utm_campaign'] ?? ''));
        if ($utmCampaign === '') {
            $utmCampaign = $this->slugifyForUtm($name);
        }

        $campaign = (new CampaignQrCode())
            ->setSlug($this->generateUniqueSlug())
            ->setName($name)
            ->setTemplateKey($templateKey)
            ->setTargetPath($targetPath)
            ->setUtmSource('qr')
            ->setUtmMedium('print')
            ->setUtmCampaign($utmCampaign);

        $this->em->persist($campaign);
        $this->em->flush();

        return new JsonResponse($this->formatCampaign($campaign), 201);
    }

    #[Route('/api/super-admin/campaign-qr-codes/{id}', name: 'super_admin_campaign_qr_update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $campaign = $this->campaignRepository->find($id);
        if (!$campaign instanceof CampaignQrCode) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'name_required'], 422);
            }
            $campaign->setName($name);
        }

        if (array_key_exists('template_key', $data)) {
            $key = (string) $data['template_key'];
            if (!in_array($key, self::TEMPLATE_KEYS, true)) {
                return new JsonResponse(['error' => 'invalid_template_key'], 422);
            }
            $campaign->setTemplateKey($key);
        }

        if (array_key_exists('archived', $data)) {
            $campaign->setArchivedAt($data['archived'] ? new \DateTimeImmutable() : null);
        }

        $this->em->flush();

        return new JsonResponse($this->formatCampaign($campaign));
    }

    #[Route('/api/super-admin/campaign-qr-codes/{id}', name: 'super_admin_campaign_qr_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $campaign = $this->campaignRepository->find($id);
        if (!$campaign instanceof CampaignQrCode) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $this->em->remove($campaign);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/api/super-admin/campaign-qr-codes/{id}/stats', name: 'super_admin_campaign_qr_stats', methods: ['GET'])]
    public function stats(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $campaign = $this->campaignRepository->find($id);
        if (!$campaign instanceof CampaignQrCode) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $days = max(1, min(365, (int) $request->query->get('days', 30)));
        $from = new \DateTimeImmutable(sprintf('-%d days', $days));

        return new JsonResponse([
            'campaign' => $this->formatCampaign($campaign),
            'by_day' => $this->scanRepository->countByDaySince($campaign, $from),
            'period_days' => $days,
        ]);
    }

    /**
     * Public tracking endpoint called by the front-end /c/:slug route. Logs a scan
     * and returns the redirect URL with UTM parameters.
     */
    #[Route('/api/public/campaign-qr/{slug}/track', name: 'public_campaign_qr_track', methods: ['POST'])]
    public function track(string $slug, Request $request): JsonResponse
    {
        $campaign = $this->campaignRepository->findOneBySlug($slug);
        if (!$campaign instanceof CampaignQrCode || $campaign->getArchivedAt() !== null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $event = (new CampaignQrScanEvent())
            ->setCampaignQrCode($campaign)
            ->setUserAgent($request->headers->get('User-Agent'))
            ->setReferer($request->headers->get('Referer'))
            ->setIpHash($this->hashIp((string) $request->getClientIp()));

        $campaign->incrementScanCount();

        $this->em->persist($event);
        $this->em->flush();

        return new JsonResponse([
            'redirect_url' => $this->buildRedirectUrl($campaign),
        ]);
    }

    private function formatCampaign(CampaignQrCode $c, bool $includeMonthly = false): array
    {
        $data = [
            'id' => $c->getId(),
            'slug' => $c->getSlug(),
            'name' => $c->getName(),
            'template_key' => $c->getTemplateKey(),
            'target_path' => $c->getTargetPath(),
            'utm_source' => $c->getUtmSource(),
            'utm_medium' => $c->getUtmMedium(),
            'utm_campaign' => $c->getUtmCampaign(),
            'scan_count' => $c->getScanCount(),
            'created_at' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'archived_at' => $c->getArchivedAt()?->format(\DateTimeInterface::ATOM),
        ];

        if ($includeMonthly) {
            $data['scans_by_month'] = $this->scanRepository->countByLast12Months($c);
        }

        return $data;
    }

    private function buildRedirectUrl(CampaignQrCode $campaign): string
    {
        $path = $campaign->getTargetPath();
        $query = http_build_query([
            'utm_source' => $campaign->getUtmSource(),
            'utm_medium' => $campaign->getUtmMedium(),
            'utm_campaign' => $campaign->getUtmCampaign(),
        ]);

        $separator = str_contains($path, '?') ? '&' : '?';
        return $path . $separator . $query;
    }

    private function generateUniqueSlug(): string
    {
        do {
            $slug = $this->randomSlug(7);
        } while ($this->campaignRepository->findOneBySlug($slug) !== null);

        return $slug;
    }

    private function randomSlug(int $length): string
    {
        $alphabet = self::SLUG_ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    private function slugifyForUtm(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? '';
        $value = trim($value, '_');
        return $value !== '' ? mb_substr($value, 0, 80) : 'lakarte';
    }

    private function hashIp(string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }
        return hash('sha256', $ip . '|campaign-qr');
    }
}

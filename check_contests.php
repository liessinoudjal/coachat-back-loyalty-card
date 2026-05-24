<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/bootstrap.php';

$em = $GLOBALS['doctrine']->getManager();
$contests = $em->getRepository(\App\Entity\Contest::class)->findVisibleForMerchants(
    $em->getRepository(\App\Entity\Merchant::class)->findAll(),
    new \DateTimeImmutable('now')
);

echo "Found " . count($contests) . " visible contests:\n";
foreach ($contests as $contest) {
    echo "- {$contest->getTitle()} ({$contest->getStatus()->value}) for {$contest->getMerchant()->getCompanyName()}\n";
}

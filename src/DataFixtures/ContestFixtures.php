<?php

namespace App\DataFixtures;

use App\Entity\Contest;
use App\Entity\ContestParticipation;
use App\Entity\ContestReward;
use App\Entity\Customer;
use App\Entity\Merchant;
use App\Enum\ContestRewardType;
use App\Enum\ContestStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class ContestFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Merchant $merchant */
        $merchant = $this->getReference('merchant_main', Merchant::class);

        $now = new \DateTimeImmutable('now');

        // ── 1) Contest IN PROGRESS (active) ─────────────────────────────
        $active = new Contest();
        $active->setMerchant($merchant);
        $active->setTitle('Grand jeu de printemps');
        $active->setDescription('Participez à notre tirage au sort de printemps et tentez de remporter de superbes lots offerts par la maison.');
        $active->setStartAt($now->modify('-7 days'));
        $active->setEndAt($now->modify('+7 days'));
        $active->setDrawAt($now->modify('+8 days'));
        $active->setStatus(ContestStatus::ACTIVE);

        $this->addReward($active, 1, 'Menu signature pour 2 personnes', ContestRewardType::TEXT);
        $this->addReward($active, 2, 'Carte de fidélité 10 cafés', ContestRewardType::CARD_STAMP, 10, '1 café offert tous les 10 achats');
        $this->addReward($active, 3, 'Bon d\'achat 50€', ContestRewardType::CARD_POINT, 5000, '1€ de remise par point cumulé');

        $manager->persist($active);

        // ── 2) Contest FINISHED — ready for tirage ──────────────────────
        $finished = new Contest();
        $finished->setMerchant($merchant);
        $finished->setTitle('Jeu concours d\'hiver');
        $finished->setDescription('Le jeu concours d\'hiver est terminé. Le tirage au sort est imminent : rendez-vous pour découvrir les gagnants !');
        $finished->setStartAt($now->modify('-30 days'));
        $finished->setEndAt($now->modify('-1 day'));
        $finished->setDrawAt($now->modify('+1 hour'));
        $finished->setStatus(ContestStatus::FINISHED);

        $this->addReward($finished, 1, 'Panier gourmand premium', ContestRewardType::TEXT);
        $this->addReward($finished, 2, 'Carte de fidélité 20 tampons', ContestRewardType::CARD_STAMP, 20, '1 dessert offert tous les 20 tampons');
        $this->addReward($finished, 3, 'Carte points - bon d\'achat 30€', ContestRewardType::CARD_POINT, 3000, '1€ de remise par point cumulé');
        $this->addReward($finished, 4, 'Goodies de la maison', ContestRewardType::TEXT);

        $manager->persist($finished);

        // ── 3) Contest READY FOR DRAW — finished with participants ──────
        $readyForDraw = new Contest();
        $readyForDraw->setMerchant($merchant);
        $readyForDraw->setTitle('Tirage de la rentrée');
        $readyForDraw->setDescription('Le concours est clôturé et le tirage au sort peut être effectué. Plusieurs participants en lice !');
        $readyForDraw->setStartAt($now->modify('-15 days'));
        $readyForDraw->setEndAt($now->modify('-2 days'));
        $readyForDraw->setDrawAt($now->modify('-1 hour'));
        $readyForDraw->setStatus(ContestStatus::ACTIVE);

        $this->addReward($readyForDraw, 1, 'Bon d\'achat 50€', ContestRewardType::CARD_POINT, 5000, '50€ de remise à valoir sur votre prochaine commande');
         $this->addReward($readyForDraw, 2, '10 menu burger offert', ContestRewardType::CARD_STAMP, 10, '1 menu burger offert à chaque tampon');
        $this->addReward($readyForDraw, 3, '1 playsatation', ContestRewardType::TEXT);
       
       

        $manager->persist($readyForDraw);

        // Create 6 participants for the ready-for-draw contest.
        $participants = [
            ['Alice Martin', 'alice.martin@example.test'],
            ['Bruno Dupont', 'bruno.dupont@example.test'],
            ['Camille Lefevre', 'camille.lefevre@example.test'],
            ['David Garcia', 'david.garcia@example.test'],
            ['Elsa Petit', 'elsa.petit@example.test'],
            ['Farid Benali', 'farid.benali@example.test'],
        ];

        foreach ($participants as $i => [$name, $email]) {
            $customer = new Customer();
            $customer->setName($name);
            $customer->setEmail($email);
            $customer->setMerchant($merchant);
            $manager->persist($customer);

            $participation = new ContestParticipation();
            $participation->setContest($readyForDraw);
            $participation->setCustomer($customer);
            $manager->persist($participation);
        }

        $manager->flush();
    }

    private function addReward(
        Contest $contest,
        int $rank,
        string $title,
        ContestRewardType $type,
        ?int $targetValue = null,
        ?string $rewardDescription = null,
    ): void {
        $reward = new ContestReward();
        $reward->setContest($contest);
        $reward->setRank($rank);
        $reward->setTitle($title);
        $reward->setType($type);
        if ($targetValue !== null) {
            $reward->setTargetValue($targetValue);
        }
        if ($rewardDescription !== null) {
            $reward->setRewardDescription($rewardDescription);
        }
        $contest->addReward($reward);
    }
}

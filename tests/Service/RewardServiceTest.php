<?php

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\LoyaltyCard;
use App\Entity\LoyaltyProgram;
use App\Entity\Merchant;
use App\Entity\Reward;
use App\Entity\User;
use App\Enum\RewardStatus;
use App\Repository\RewardRepository;
use App\Service\RewardService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class RewardServiceTest extends TestCase
{
    public function testCreateRewardFromCompletionGeneratesPendingReward(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $rewardRepository = $this->createMock(RewardRepository::class);

        $rewardRepository
            ->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(function (array $criteria) {
                if (isset($criteria['loyaltyCard'])) {
                    return null;
                }

                if (isset($criteria['claimQrToken'])) {
                    return null;
                }

                return null;
            });

        $entityManager
            ->expects($this->exactly(2))
            ->method('persist');

        $service = new RewardService($entityManager, $rewardRepository);
        $card = $this->buildCompletedCard('Coffee reward');

        $reward = $service->createRewardFromCompletion($card, 123);

        $this->assertSame(RewardStatus::PENDING, $reward->getStatus());
        $this->assertSame('Coffee reward', $reward->getRewardDescription());
        $this->assertNotEmpty($reward->getClaimQrToken());
        $this->assertSame(123, $reward->getMetadata()['transaction_id']);
    }

    public function testCreateRewardFromCompletionIsIdempotent(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $rewardRepository = $this->createMock(RewardRepository::class);

        $existing = new Reward();
        $card = $this->buildCompletedCard('Existing reward');

        $rewardRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['loyaltyCard' => $card])
            ->willReturn($existing);

        $entityManager
            ->expects($this->never())
            ->method('persist');

        $service = new RewardService($entityManager, $rewardRepository);

        $reward = $service->createRewardFromCompletion($card, 456);

        $this->assertSame($existing, $reward);
    }

    public function testClaimByQrTransitionsRewardToClaimed(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $rewardRepository = $this->createMock(RewardRepository::class);

        $reward = new Reward();
        $reward->setStatus(RewardStatus::PENDING);
        $reward->setClaimQrToken('token-123');

        $rewardRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['claimQrToken' => 'token-123'])
            ->willReturn($reward);

        $entityManager
            ->expects($this->once())
            ->method('persist');

        $service = new RewardService($entityManager, $rewardRepository);
        $actor = new User();

        $claimed = $service->claimByQrToken('lacarte-reward:token-123', $actor);

        $this->assertSame(RewardStatus::CLAIMED, $claimed->getStatus());
        $this->assertNotNull($claimed->getClaimedAt());
        $this->assertSame($actor, $claimed->getClaimedByMerchantUser());
    }

    public function testClaimByQrRefusesAlreadyClaimedReward(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $rewardRepository = $this->createMock(RewardRepository::class);

        $reward = new Reward();
        $reward->setStatus(RewardStatus::CLAIMED);
        $reward->setClaimQrToken('token-claimed');

        $rewardRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['claimQrToken' => 'token-claimed'])
            ->willReturn($reward);

        $entityManager
            ->expects($this->never())
            ->method('persist');

        $service = new RewardService($entityManager, $rewardRepository);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Reward already claimed');

        $service->claimByQrToken('token-claimed', new User());
    }

    private function buildCompletedCard(?string $rewardDescription): LoyaltyCard
    {
        $merchant = new Merchant();
        $customer = (new Customer())->setName('Client')->setEmail('client@example.com');
        $program = (new LoyaltyProgram())->setName('Program')->setRewardDescription($rewardDescription);

        $card = new LoyaltyCard();
        $card->setCurrentValue(10);
        $card->setTargetValue(10);
        $card->setIsCompleted(true);
        $card->setMerchant($merchant);
        $card->setCustomer($customer);
        $card->setLoyaltyProgram($program);

        return $card;
    }
}

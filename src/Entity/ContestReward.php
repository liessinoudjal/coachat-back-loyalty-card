<?php

namespace App\Entity;

use App\Enum\ContestRewardType;
use App\Repository\ContestRewardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContestRewardRepository::class)]
#[ORM\Table(name: 'contest_reward')]
    #[ORM\UniqueConstraint(name: 'UNIQ_CONTEST_REWARD_RANK', columns: ['contest_id', '`rank`'])]
class ContestReward
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rewards')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Contest $contest = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(name: '`rank`')]
    private int $rank = 1;

    #[ORM\Column(enumType: ContestRewardType::class, length: 32, options: ['default' => 'TEXT'])]
    private ContestRewardType $type = ContestRewardType::TEXT;

    /**
     * For card_stamp rewards: number of stamps to fill (e.g. 10 menus).
     * For card_point rewards: amount (in cents) to accumulate (e.g. 5000 = 50€).
     */
    #[ORM\Column(nullable: true)]
    private ?int $targetValue = null;

    /**
     * Short description of what the reward grants (e.g. "1 menu offert", "1 euro de remise").
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rewardDescription = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContest(): ?Contest
    {
        return $this->contest;
    }

    public function setContest(?Contest $contest): self
    {
        $this->contest = $contest;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function setRank(int $rank): self
    {
        $this->rank = $rank;

        return $this;
    }

    public function getType(): ContestRewardType
    {
        return $this->type;
    }

    public function setType(ContestRewardType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getTargetValue(): ?int
    {
        return $this->targetValue;
    }

    public function setTargetValue(?int $targetValue): self
    {
        $this->targetValue = $targetValue;

        return $this;
    }

    public function getRewardDescription(): ?string
    {
        return $this->rewardDescription;
    }

    public function setRewardDescription(?string $rewardDescription): self
    {
        $this->rewardDescription = $rewardDescription;

        return $this;
    }
}

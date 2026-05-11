<?php

namespace App\Entity;

use App\Repository\ContestRewardRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContestRewardRepository::class)]
#[ORM\Table(name: 'contest_reward')]
#[ORM\UniqueConstraint(name: 'UNIQ_CONTEST_REWARD_RANK', columns: ['contest_id', 'rank'])]
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

    #[ORM\Column]
    private int $rank = 1;

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
}

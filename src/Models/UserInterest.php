<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class UserInterest
{
    public UuidInterface $id;
    public UuidInterface $userId;
    public UuidInterface $interestId;
    public int $weight;
    public ?User $user;
    public ?Interest $interest;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->weight = 5;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $ui = new self();
        $ui->id = Uuid::fromString($data['id']);
        $ui->userId = Uuid::fromString($data['user_id']);
        $ui->interestId = Uuid::fromString($data['interest_id']);
        $ui->weight = (int)($data['weight'] ?? 5);
        $ui->createdAt = new \DateTime($data['created_at']);
        $ui->updatedAt = new \DateTime($data['updated_at']);
        return $ui;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'userID' => $this->userId->toString(),
            'interestID' => $this->interestId->toString(),
            'weight' => $this->weight,
            'user' => $this->user?->toArray(),
            'interest' => $this->interest?->toArray(),
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



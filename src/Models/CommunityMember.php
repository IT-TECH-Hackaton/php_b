<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class CommunityMember
{
    public UuidInterface $id;
    public UuidInterface $userId;
    public UuidInterface $communityId;
    public ?User $user;
    public ?MicroCommunity $community;
    public \DateTime $joinedAt;
    public \DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->joinedAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $cm = new self();
        $cm->id = Uuid::fromString($data['id']);
        $cm->userId = Uuid::fromString($data['user_id']);
        $cm->communityId = Uuid::fromString($data['community_id']);
        $cm->joinedAt = new \DateTime($data['joined_at']);
        $cm->updatedAt = new \DateTime($data['updated_at']);
        return $cm;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'userID' => $this->userId->toString(),
            'communityID' => $this->communityId->toString(),
            'user' => $this->user?->toArray(),
            'community' => $this->community?->toArray(),
            'joinedAt' => $this->joinedAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



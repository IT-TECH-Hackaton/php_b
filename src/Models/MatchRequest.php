<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class MatchRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';

    public UuidInterface $id;
    public UuidInterface $fromUserId;
    public UuidInterface $toUserId;
    public UuidInterface $eventId;
    public string $status;
    public ?string $message;
    public ?User $fromUser;
    public ?User $toUser;
    public ?Event $event;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->status = self::STATUS_PENDING;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $mr = new self();
        $mr->id = Uuid::fromString($data['id']);
        $mr->fromUserId = Uuid::fromString($data['from_user_id']);
        $mr->toUserId = Uuid::fromString($data['to_user_id']);
        $mr->eventId = Uuid::fromString($data['event_id']);
        $mr->status = $data['status'] ?? self::STATUS_PENDING;
        $mr->message = $data['message'] ?? null;
        $mr->createdAt = new \DateTime($data['created_at']);
        $mr->updatedAt = new \DateTime($data['updated_at']);
        return $mr;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'fromUserID' => $this->fromUserId->toString(),
            'toUserID' => $this->toUserId->toString(),
            'eventID' => $this->eventId->toString(),
            'status' => $this->status,
            'message' => $this->message,
            'fromUser' => $this->fromUser?->toArray(),
            'toUser' => $this->toUser?->toArray(),
            'event' => $this->event?->toArray(),
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



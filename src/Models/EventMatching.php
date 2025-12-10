<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class EventMatching
{
    public const STATUS_LOOKING = 'Ищу компанию';
    public const STATUS_FOUND = 'Нашел компанию';
    public const STATUS_GOING_ALONE = 'Иду один';

    public UuidInterface $id;
    public UuidInterface $userId;
    public UuidInterface $eventId;
    public string $status;
    public ?string $preferences;
    public ?User $user;
    public ?Event $event;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->status = self::STATUS_LOOKING;
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $em = new self();
        $em->id = Uuid::fromString($data['id']);
        $em->userId = Uuid::fromString($data['user_id']);
        $em->eventId = Uuid::fromString($data['event_id']);
        $em->status = $data['status'] ?? self::STATUS_LOOKING;
        $em->preferences = $data['preferences'] ?? null;
        $em->createdAt = new \DateTime($data['created_at']);
        $em->updatedAt = new \DateTime($data['updated_at']);
        return $em;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'userID' => $this->userId->toString(),
            'eventID' => $this->eventId->toString(),
            'status' => $this->status,
            'preferences' => $this->preferences,
            'user' => $this->user?->toArray(),
            'event' => $this->event?->toArray(),
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



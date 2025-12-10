<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class EventParticipant
{
    public UuidInterface $id;
    public UuidInterface $eventId;
    public UuidInterface $userId;
    public ?Event $event;
    public ?User $user;
    public \DateTime $createdAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->createdAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $participant = new self();
        $participant->id = Uuid::fromString($data['id']);
        $participant->eventId = Uuid::fromString($data['event_id']);
        $participant->userId = Uuid::fromString($data['user_id']);
        $participant->createdAt = new \DateTime($data['created_at']);
        return $participant;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'eventID' => $this->eventId->toString(),
            'userID' => $this->userId->toString(),
            'event' => $this->event?->toArray(),
            'user' => $this->user?->toArray(),
            'createdAt' => $this->createdAt->format('c'),
        ];
    }
}



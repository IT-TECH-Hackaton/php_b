<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class EventReview
{
    public UuidInterface $id;
    public UuidInterface $eventId;
    public UuidInterface $userId;
    public int $rating;
    public ?string $comment;
    public ?Event $event;
    public ?User $user;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $review = new self();
        $review->id = Uuid::fromString($data['id']);
        $review->eventId = Uuid::fromString($data['event_id']);
        $review->userId = Uuid::fromString($data['user_id']);
        $review->rating = (int)$data['rating'];
        $review->comment = $data['comment'] ?? null;
        $review->createdAt = new \DateTime($data['created_at']);
        $review->updatedAt = new \DateTime($data['updated_at']);
        return $review;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'eventID' => $this->eventId->toString(),
            'userID' => $this->userId->toString(),
            'rating' => $this->rating,
            'comment' => $this->comment,
            'event' => $this->event?->toArray(),
            'user' => $this->user?->toArray(),
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



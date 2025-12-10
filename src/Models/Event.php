<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class Event
{
    public const STATUS_ACTIVE = 'Активное';
    public const STATUS_PAST = 'Прошедшее';
    public const STATUS_REJECTED = 'Отклоненное';

    public UuidInterface $id;
    public string $title;
    public ?string $shortDescription;
    public string $fullDescription;
    public \DateTime $startDate;
    public \DateTime $endDate;
    public ?string $imageUrl;
    public ?string $paymentInfo;
    public ?int $maxParticipants;
    public string $status;
    public UuidInterface $organizerId;
    public ?User $organizer;
    public array $participants;
    public array $categories;
    public array $tags;
    public ?string $address;
    public ?float $latitude;
    public ?float $longitude;
    public ?string $yandexMapLink;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;
    public ?\DateTime $deletedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->status = self::STATUS_ACTIVE;
        $this->participants = [];
        $this->categories = [];
        $this->tags = [];
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->deletedAt = null;
    }

    public static function fromArray(array $data): self
    {
        $event = new self();
        $event->id = Uuid::fromString($data['id']);
        $event->title = $data['title'];
        $event->shortDescription = $data['short_description'] ?? null;
        $event->fullDescription = $data['full_description'];
        $event->startDate = new \DateTime($data['start_date']);
        $event->endDate = new \DateTime($data['end_date']);
        $event->imageUrl = $data['image_url'] ?? null;
        $event->paymentInfo = $data['payment_info'] ?? null;
        $event->maxParticipants = isset($data['max_participants']) ? (int)$data['max_participants'] : null;
        $event->status = $data['status'] ?? self::STATUS_ACTIVE;
        $event->organizerId = Uuid::fromString($data['organizer_id']);
        $event->tags = $data['tags'] ?? [];
        $event->address = $data['address'] ?? null;
        $event->latitude = isset($data['latitude']) ? (float)$data['latitude'] : null;
        $event->longitude = isset($data['longitude']) ? (float)$data['longitude'] : null;
        $event->yandexMapLink = $data['yandex_map_link'] ?? null;
        $event->createdAt = new \DateTime($data['created_at']);
        $event->updatedAt = new \DateTime($data['updated_at']);
        $event->deletedAt = isset($data['deleted_at']) ? new \DateTime($data['deleted_at']) : null;
        return $event;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'title' => $this->title,
            'shortDescription' => $this->shortDescription,
            'fullDescription' => $this->fullDescription,
            'startDate' => $this->startDate->format('c'),
            'endDate' => $this->endDate->format('c'),
            'imageURL' => $this->imageUrl,
            'paymentInfo' => $this->paymentInfo,
            'maxParticipants' => $this->maxParticipants,
            'status' => $this->status,
            'organizerID' => $this->organizerId->toString(),
            'organizer' => $this->organizer?->toArray(),
            'participants' => array_map(fn($p) => $p->toArray(), $this->participants),
            'categories' => array_map(fn($c) => $c->toArray(), $this->categories),
            'tags' => $this->tags,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'yandexMapLink' => $this->yandexMapLink,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }

    public function getParticipantsCount(): int
    {
        return count($this->participants);
    }
}



<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class Interest
{
    public UuidInterface $id;
    public string $name;
    public ?string $category;
    public ?string $description;
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
        $interest = new self();
        $interest->id = Uuid::fromString($data['id']);
        $interest->name = $data['name'];
        $interest->category = $data['category'] ?? null;
        $interest->description = $data['description'] ?? null;
        $interest->createdAt = new \DateTime($data['created_at']);
        $interest->updatedAt = new \DateTime($data['updated_at']);
        return $interest;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



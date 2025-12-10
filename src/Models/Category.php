<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class Category
{
    public UuidInterface $id;
    public string $name;
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
        $category = new self();
        $category->id = Uuid::fromString($data['id']);
        $category->name = $data['name'];
        $category->description = $data['description'] ?? null;
        $category->createdAt = new \DateTime($data['created_at']);
        $category->updatedAt = new \DateTime($data['updated_at']);
        return $category;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'name' => $this->name,
            'description' => $this->description,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class MicroCommunity
{
    public UuidInterface $id;
    public string $name;
    public ?string $description;
    public UuidInterface $adminId;
    public bool $autoNotify;
    public int $membersCount;
    public ?User $admin;
    public array $members;
    public array $interests;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->autoNotify = true;
        $this->membersCount = 0;
        $this->members = [];
        $this->interests = [];
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $mc = new self();
        $mc->id = Uuid::fromString($data['id']);
        $mc->name = $data['name'];
        $mc->description = $data['description'] ?? null;
        $mc->adminId = Uuid::fromString($data['admin_id']);
        $mc->autoNotify = (bool)($data['auto_notify'] ?? true);
        $mc->membersCount = (int)($data['members_count'] ?? 0);
        $mc->createdAt = new \DateTime($data['created_at']);
        $mc->updatedAt = new \DateTime($data['updated_at']);
        return $mc;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'name' => $this->name,
            'description' => $this->description,
            'adminID' => $this->adminId->toString(),
            'autoNotify' => $this->autoNotify,
            'membersCount' => $this->membersCount,
            'admin' => $this->admin?->toArray(),
            'members' => array_map(fn($m) => $m->toArray(), $this->members),
            'interests' => array_map(fn($i) => $i->toArray(), $this->interests),
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



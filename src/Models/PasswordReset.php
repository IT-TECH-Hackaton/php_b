<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class PasswordReset
{
    public UuidInterface $id;
    public string $email;
    public string $token;
    public \DateTime $expiresAt;
    public bool $used;
    public \DateTime $createdAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->used = false;
        $this->createdAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $pr = new self();
        $pr->id = Uuid::fromString($data['id']);
        $pr->email = $data['email'];
        $pr->token = $data['token'];
        $pr->expiresAt = new \DateTime($data['expires_at']);
        $pr->used = (bool)($data['used'] ?? false);
        $pr->createdAt = new \DateTime($data['created_at']);
        return $pr;
    }
}



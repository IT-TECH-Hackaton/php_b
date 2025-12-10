<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class EmailVerification
{
    public UuidInterface $id;
    public string $email;
    public string $code;
    public \DateTime $expiresAt;
    public \DateTime $createdAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->createdAt = new \DateTime();
    }

    public static function fromArray(array $data): self
    {
        $ev = new self();
        $ev->id = Uuid::fromString($data['id']);
        $ev->email = $data['email'];
        $ev->code = $data['code'];
        $ev->expiresAt = new \DateTime($data['expires_at']);
        $ev->createdAt = new \DateTime($data['created_at']);
        return $ev;
    }
}



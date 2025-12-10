<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class RegistrationPending
{
    public UuidInterface $id;
    public string $email;
    public string $fullName;
    public string $passwordHash;
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
        $rp = new self();
        $rp->id = Uuid::fromString($data['id']);
        $rp->email = $data['email'];
        $rp->fullName = $data['full_name'];
        $rp->passwordHash = $data['password_hash'];
        $rp->code = $data['code'];
        $rp->expiresAt = new \DateTime($data['expires_at']);
        $rp->createdAt = new \DateTime($data['created_at']);
        return $rp;
    }
}



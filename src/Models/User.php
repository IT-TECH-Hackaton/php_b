<?php

namespace App\Models;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

class User
{
    public const ROLE_USER = 'Пользователь';
    public const ROLE_ADMIN = 'Администратор';

    public const STATUS_ACTIVE = 'Активен';
    public const STATUS_DELETED = 'Удален';

    public UuidInterface $id;
    public string $fullName;
    public string $email;
    public ?string $password;
    public ?string $yandexId;
    public string $telegram;
    public string $avatarUrl;
    public string $role;
    public string $status;
    public bool $emailVerified;
    public string $authProvider;
    public \DateTime $createdAt;
    public \DateTime $updatedAt;
    public ?\DateTime $deletedAt;

    public function __construct()
    {
        $this->id = Uuid::uuid4();
        $this->role = self::ROLE_USER;
        $this->status = self::STATUS_ACTIVE;
        $this->emailVerified = false;
        $this->authProvider = 'email';
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->deletedAt = null;
    }

    public static function fromArray(array $data): self
    {
        $user = new self();
        $user->id = Uuid::fromString($data['id']);
        $user->fullName = $data['full_name'];
        $user->email = $data['email'];
        $user->password = $data['password'] ?? null;
        $user->yandexId = $data['yandex_id'] ?? null;
        $user->telegram = $data['telegram'] ?? '';
        $user->avatarUrl = $data['avatar_url'] ?? '';
        $user->role = $data['role'] ?? self::ROLE_USER;
        $user->status = $data['status'] ?? self::STATUS_ACTIVE;
        $user->emailVerified = (bool)($data['email_verified'] ?? false);
        $user->authProvider = $data['auth_provider'] ?? 'email';
        $user->createdAt = new \DateTime($data['created_at']);
        $user->updatedAt = new \DateTime($data['updated_at']);
        $user->deletedAt = isset($data['deleted_at']) ? new \DateTime($data['deleted_at']) : null;
        return $user;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'fullName' => $this->fullName,
            'email' => $this->email,
            'telegram' => $this->telegram,
            'avatarURL' => $this->avatarUrl,
            'role' => $this->role,
            'status' => $this->status,
            'emailVerified' => $this->emailVerified,
            'authProvider' => $this->authProvider,
            'createdAt' => $this->createdAt->format('c'),
            'updatedAt' => $this->updatedAt->format('c'),
        ];
    }
}



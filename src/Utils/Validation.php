<?php

namespace App\Utils;

class Validation
{
    public static function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function validatePassword(string $password): bool
    {
        if (strlen($password) < 8) {
            return false;
        }

        if (!preg_match('/[a-zA-Z]/', $password)) {
            return false;
        }

        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }

        if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
            return false;
        }

        return true;
    }

    public static function validateFullName(string $fullName): bool
    {
        return preg_match('/^[А-Яа-яЁё\s]+$/u', $fullName) === 1;
    }

    public static function validateStringLength(string $str, int $min, int $max): bool
    {
        $length = mb_strlen($str, 'UTF-8');
        return $length >= $min && $length <= $max;
    }

    public static function generateVerificationCode(): string
    {
        return (string)rand(100000, 999999);
    }

    public static function generateRandomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function validateVerificationCode(string $code): bool
    {
        return preg_match('/^\d{6}$/', $code) === 1;
    }

    public static function validateUUID(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}


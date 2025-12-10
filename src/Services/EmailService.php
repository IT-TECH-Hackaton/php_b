<?php

namespace App\Services;

use App\Config\Config;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Psr\Log\LoggerInterface;

class EmailService
{
    private PHPMailer $mailer;
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->mailer = new PHPMailer(true);
        
        $this->mailer->isSMTP();
        $this->mailer->Host = Config::$emailHost;
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = Config::$emailUser;
        $this->mailer->Password = Config::$emailPassword;
        $this->mailer->SMTPSecure = Config::$emailPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $this->mailer->Port = Config::$emailPort;
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->setFrom(Config::$emailFrom);
    }

    public function sendEmail(string $to, string $subject, string $body): bool
    {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $body;
            $this->mailer->isHTML(true);
            
            $this->mailer->send();
            
            if ($this->logger) {
                $this->logger->info('Email отправлен', ['to' => $to, 'subject' => $subject]);
            }
            
            return true;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Ошибка отправки email', ['to' => $to, 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    public function sendVerificationCode(string $to, string $code): bool
    {
        $subject = 'Код подтверждения email';
        $body = "Ваш код подтверждения: <strong>{$code}</strong><br>Код действителен в течение 10 минут.";
        return $this->sendEmail($to, $subject, $body);
    }

    public function sendPasswordResetLink(string $to, string $token): bool
    {
        $url = Config::$frontendUrl . '/reset-password?token=' . $token;
        $subject = 'Восстановление пароля';
        $body = "Для восстановления пароля перейдите по ссылке: <a href=\"{$url}\">{$url}</a><br>Ссылка действительна в течение 1 часа.";
        return $this->sendEmail($to, $subject, $body);
    }

    public function sendWelcomeEmail(string $to, string $fullName): bool
    {
        $subject = 'Добро пожаловать!';
        $body = "<h2>Добро пожаловать, {$fullName}!</h2>
                <p>Ваша регистрация успешно завершена. Теперь вы можете использовать все возможности нашей системы электронной афиши.</p>
                <p>Приятного использования!</p>";
        return $this->sendEmail($to, $subject, $body);
    }

    public function sendPasswordToUser(string $to, string $fullName, string $password): bool
    {
        $subject = 'Ваш новый пароль';
        $body = "<h2>Здравствуйте, {$fullName}!</h2>
                <p>Ваш пароль был изменен администратором.</p>
                <p><strong>Новый пароль: {$password}</strong></p>
                <p>Рекомендуем изменить пароль после первого входа.</p>";
        return $this->sendEmail($to, $subject, $body);
    }

    public function sendPasswordChangedNotification(string $to): bool
    {
        $subject = 'Пароль изменен';
        $body = "<h2>Уведомление об изменении пароля</h2>
                <p>Ваш пароль был успешно изменен.</p>
                <p>Если это были не вы, пожалуйста, свяжитесь с администрацией.</p>";
        return $this->sendEmail($to, $subject, $body);
    }

    public function sendEventReminder(string $to, string $fullName, string $eventTitle, string $startDate): bool
    {
        $subject = 'Напоминание о событии';
        $body = "<h2>Здравствуйте, {$fullName}!</h2>
                <p>Напоминаем, что вы зарегистрированы на событие:</p>
                <h3>{$eventTitle}</h3>
                <p>Дата начала: {$startDate}</p>
                <p>Не забудьте посетить событие!</p>";
        return $this->sendEmail($to, $subject, $body);
    }

    public function sendEventNotification(string $to, string $eventTitle, string $message): bool
    {
        $subject = "Уведомление: {$eventTitle}";
        $body = "<div style='max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <h2>Уведомление о событии: {$eventTitle}</h2>
                <p>{$message}</p>
                </div>";
        return $this->sendEmail($to, $subject, $body);
    }
}


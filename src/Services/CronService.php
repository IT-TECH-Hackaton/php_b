<?php

namespace App\Services;

use App\Database\Database;
use App\Models\Event;
use Psr\Log\LoggerInterface;

class CronService
{
    private EmailService $emailService;
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
        $this->emailService = new EmailService($logger);
    }

    public function updateEventStatuses(): void
    {
        $db = Database::getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        
        $stmt = $db->prepare("UPDATE events SET status = ? WHERE status = ? AND end_date < ?");
        $stmt->execute([Event::STATUS_PAST, Event::STATUS_ACTIVE, $now]);
        
        if ($this->logger) {
            $this->logger->info('Статусы событий обновлены');
        }
    }

    public function sendEventReminders(): void
    {
        $db = Database::getConnection();
        $tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
        $dayAfter = (new \DateTime())->modify('+2 days')->format('Y-m-d');
        
        $stmt = $db->prepare("SELECT e.*, u.email, u.full_name 
                              FROM events e 
                              JOIN event_participants ep ON e.id = ep.event_id 
                              JOIN users u ON ep.user_id = u.id 
                              WHERE e.status = ? 
                              AND DATE(e.start_date) BETWEEN ? AND ?");
        $stmt->execute([Event::STATUS_ACTIVE, $tomorrow, $dayAfter]);
        $participants = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($participants as $participant) {
            $this->emailService->sendEventReminder(
                $participant['email'],
                $participant['full_name'],
                $participant['title'],
                $participant['start_date']
            );
        }
        
        if ($this->logger) {
            $this->logger->info('Напоминания о событиях отправлены', ['count' => count($participants)]);
        }
    }
}



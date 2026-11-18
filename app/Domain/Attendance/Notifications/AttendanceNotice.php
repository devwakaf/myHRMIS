<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued mail + database notice for Kehadiran, rendered in the recipient's
 * language from lang/{ms,en}/attendance.php `notifications.<kind>`.
 */
abstract class AttendanceNotice extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function kind(): string;

    /**
     * @return array<string, string>
     */
    abstract public function params(): array;

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->is_active ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('attendance.notifications.'.$this->kind().'.subject', $this->params()))
            ->greeting((string) __('approval.mail.greeting', ['name' => $notifiable->name]))
            ->line((string) __('attendance.notifications.'.$this->kind().'.line', $this->params()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => 'attendance.'.$this->kind(), 'params' => $this->params()];
    }
}

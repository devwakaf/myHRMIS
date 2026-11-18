<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued mail + database notice for the Payroll scheduler jobs, rendered in the
 * recipient's language from lang/{ms,en}/payroll.php `notifications.<kind>`.
 */
abstract class PayrollNotice extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function kind(): string;

    /**
     * @return array<string, string>
     */
    abstract public function params(): array;

    /** Page the notice points to (relative URL). */
    abstract public function link(): string;

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
            ->subject((string) __('payroll.notifications.'.$this->kind().'.subject', $this->params()))
            ->greeting((string) __('approval.mail.greeting', ['name' => $notifiable->name]))
            ->line((string) __('payroll.notifications.'.$this->kind().'.line', $this->params()))
            ->action((string) __('payroll.notifications.'.$this->kind().'.action'), url($this->link()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => 'payroll.'.$this->kind(), 'params' => $this->params(), 'link' => $this->link()];
    }
}

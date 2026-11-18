<?php

declare(strict_types=1);

namespace App\Domain\Core\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued mail + database notice listing lines (people digest, employment
 * reminders, upcoming holidays). Rendered in the recipient's language.
 */
final class CoreDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{key: string, params: array<string, string>}>  $lines
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $organisationName,
        public readonly array $lines,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject((string) __('core.notifications.'.$this->kind.'.subject', ['organisation' => $this->organisationName]))
            ->greeting((string) __('approval.mail.greeting', ['name' => $notifiable->name]))
            ->line((string) __('core.notifications.'.$this->kind.'.intro'));

        foreach ($this->lines as $line) {
            $mail->line('• '.__($line['key'], $line['params']));
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => $this->kind, 'organisation' => $this->organisationName, 'lines' => $this->lines];
    }
}

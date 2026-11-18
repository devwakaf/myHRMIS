<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval\Notifications;

use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Base for approval notifications (A6.10): queued, mail + database, rendered in
 * the recipient's language (User implements HasLocalePreference).
 */
abstract class ApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ApprovalRequest $request) {}

    abstract protected function subjectKey(): string;

    abstract protected function lineKey(): string;

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __($this->subjectKey(), $this->params()))
            ->greeting((string) __('approval.mail.greeting', ['name' => $notifiable->name]))
            ->line((string) __($this->lineKey(), $this->params()))
            ->line($this->summary())
            ->action((string) __('approval.mail.open'), url('/approvals?request='.$this->request->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'request_id' => $this->request->id,
            'approval_type' => $this->request->approval_type,
            'message_key' => $this->lineKey(),
            'params' => $this->params(),
            'summary' => $this->summary(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function params(): array
    {
        $registry = app(ApprovalTypeRegistry::class);
        $type = $registry->has($this->request->approval_type)
            ? $registry->get($this->request->approval_type)->label()
            : $this->request->approval_type;

        return [
            'type' => $type,
            'applicant' => (string) $this->request->applicant?->full_name,
            'number' => (string) $this->request->id,
        ];
    }

    protected function summary(): string
    {
        $doc = $this->request->approvable()->withoutGlobalScopes()->first();
        if (! $doc instanceof Approvable) {
            return '';
        }

        $summary = $doc->approvalSummary();

        return (string) __($summary['key'], array_map(fn ($v) => (string) $v, $summary['params'] ?? []));
    }
}

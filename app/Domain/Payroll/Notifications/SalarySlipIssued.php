<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Notifications;

use App\Domain\Payroll\Models\SalarySlip;
use App\Domain\Payroll\Pdf\PayrollPdf;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * "Slip Gaji {Bulan Tahun} — {Organisasi}" (spec-04 §1.4). The body never
 * contains amounts; the PDF is attached (password per setting).
 */
final class SalarySlipIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $slipId,
        public readonly string $period,
        public readonly string $organisationName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->is_active ? ['mail', 'database'] : ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function params(): array
    {
        return ['period' => $this->period, 'organisation' => $this->organisationName];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject((string) __('payroll.notifications.slip_issued.subject', $this->params()))
            ->greeting((string) __('approval.mail.greeting', ['name' => $notifiable->name]))
            ->line((string) __('payroll.notifications.slip_issued.line', $this->params()))
            ->action((string) __('payroll.notifications.slip_issued.action'), url('/me/payslips/'.$this->slipId));

        // The PDF is rendered here (in the queue worker) on first use and cached on the slip.
        $slip = SalarySlip::acrossOrganisations()->find($this->slipId);
        if ($slip !== null) {
            try {
                $path = app(PayrollPdf::class)->ensureSlip($slip);
                $mail->attach(Storage::disk('local')->path($path), ['as' => $slip->number.'.pdf', 'mime' => 'application/pdf']);
            } catch (Throwable $e) {
                report($e); // the e-mail still goes out; the employee can download the slip from Slip Gaji Saya
            }
        }

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return ['kind' => 'payroll.slip_issued', 'params' => $this->params(), 'slip_id' => $this->slipId];
    }
}

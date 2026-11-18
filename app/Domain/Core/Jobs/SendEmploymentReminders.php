<?php

declare(strict_types=1);

namespace App\Domain\Core\Jobs;

use App\Domain\Core\Enums\EmployeeStatus;
use App\Domain\Core\Models\Employee;
use App\Domain\Core\Models\EmployeeDocument;
use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Models\Organisation;
use App\Domain\Core\Notifications\CoreDigestNotification;
use App\Domain\Core\Services\HrRecipients;
use App\Domain\Shared\Models\ReminderLog;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Notification;

/**
 * 07:05 daily (B7): probation, contract, work pass, passport, document expiry
 * and retirement reminders to HR. One reminder per item per threshold
 * (reminder_logs keyed on the item's due date), so re-runs send nothing.
 */
final class SendEmploymentReminders implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(HrRecipients $hr): void
    {
        $today = today()->toImmutable();

        foreach (Organisation::query()->where('is_active', true)->get() as $organisation) {
            OrganisationContext::run($organisation, function () use ($organisation, $today, $hr): void {
                $lines = $this->collect($organisation->id, $today);
                $recipients = $hr->for($organisation->id);

                if ($lines !== [] && $recipients->isNotEmpty()) {
                    Notification::send($recipients, new CoreDigestNotification('employment_reminders', $organisation->name, $lines));
                }
            });
        }
    }

    /**
     * @return list<array{key: string, params: array<string, string>}>
     */
    public function collect(int $organisationId, CarbonImmutable $today): array
    {
        $settings = HrSettings::current();
        $lines = [];
        $employees = Employee::query()->where('status', '!=', EmployeeStatus::Left->value)->get();

        $rules = [
            'probation' => ['probation_end_date', $settings->remind_probation_days, fn (Employee $e) => $e->confirmed_on === null],
            'contract' => ['contract_end_date', $settings->remind_contract_days, null],
            'work_pass' => ['work_pass_expiry', $settings->remind_work_pass_days, null],
            'passport' => ['passport_expiry', $settings->remind_document_days, null],
            'retirement' => ['retirement_date', $settings->remind_retirement_days, null],
        ];

        foreach ($employees as $employee) {
            foreach ($rules as $kind => [$field, $days, $extra]) {
                $due = $employee->getAttribute($field);
                if (! $due instanceof CarbonImmutable || $due->lessThan($today) || $due->greaterThan($today->addDays($days))) {
                    continue;
                }
                if ($extra !== null && ! $extra($employee)) {
                    continue;
                }
                if (! ReminderLog::claim($organisationId, $employee->id, $kind, $due)) {
                    continue;
                }
                $lines[] = ['key' => 'core.notifications.employment_reminders.'.$kind, 'params' => [
                    'name' => $employee->full_name,
                    'no' => $employee->employee_no,
                    'date' => $due->format('d/m/Y'),
                ]];
            }
        }

        $documents = EmployeeDocument::query()->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', $today->toDateString())
            ->with(['employee', 'documentType'])
            ->get();
        foreach ($documents as $document) {
            $days = $document->remind_before_days ?? $settings->remind_document_days;
            if ($document->expires_on === null || $document->expires_on->greaterThan($today->addDays($days))) {
                continue;
            }
            if ($document->employee === null || $document->employee->isLeft()) {
                continue;
            }
            if (! ReminderLog::claim($organisationId, $document->employee_id, 'document', $document->expires_on, (string) $document->id)) {
                continue;
            }
            $lines[] = ['key' => 'core.notifications.employment_reminders.document', 'params' => [
                'name' => $document->employee->full_name,
                'no' => $document->employee->employee_no,
                'document' => $document->title,
                'date' => $document->expires_on->format('d/m/Y'),
            ]];
        }

        return $lines;
    }
}

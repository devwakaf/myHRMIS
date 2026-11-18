<?php

declare(strict_types=1);

namespace App\Domain\Leave\Actions;

use App\Domain\Leave\Models\LeaveAggregateCap;
use App\Domain\Leave\Models\LeavePeriod;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeaveSettings;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Shared\Organisation\OrganisationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Default Cuti setup for a new Organisasi (spec-02 §6.4): our own Akta Kerja
 * 1955-based seed (UNVERIFIED-STAT points stay configurable), one policy, the
 * sick + hospitalisation aggregate cap, the current calendar-year period and
 * Tetapan Cuti. Idempotent: existing codes are left untouched.
 */
final class SeedLeaveDefaults
{
    public function handle(int $organisationId): void
    {
        OrganisationContext::run($organisationId, function () use ($organisationId): void {
            DB::transaction(function () use ($organisationId): void {
                $types = [];
                foreach ($this->types() as $i => $definition) {
                    $types[$definition['code']] = LeaveType::query()->firstOrCreate(
                        ['code' => $definition['code']],
                        $definition + ['is_system' => true, 'sort_order' => ($i + 1) * 10],
                    );
                }

                $policy = LeavePolicy::query()->firstOrCreate(
                    ['name' => 'Akta Kerja 1955 — Asas'],
                    ['description' => 'Lalai sistem berdasarkan Akta Kerja 1955 (sila semak dengan penasihat undang-undang).', 'is_active' => true],
                );
                if ($policy->wasRecentlyCreated) {
                    $tah = $policy->rows()->create(['leave_type_id' => $types['TAH']->id, 'uses_service_tiers' => true, 'tier_basis' => 'prorata_by_anniversary']);
                    foreach ([[0, 8], [24, 12], [60, 16]] as [$months, $days]) {
                        $tah->tiers()->create(['min_service_months' => $months, 'days' => $days]);
                    }
                    $skt = $policy->rows()->create(['leave_type_id' => $types['SKT']->id, 'uses_service_tiers' => true, 'tier_basis' => 'prorata_by_anniversary']);
                    foreach ([[0, 14], [24, 18], [60, 22]] as [$months, $days]) {
                        $skt->tiers()->create(['min_service_months' => $months, 'days' => $days]);
                    }
                    $policy->rows()->create(['leave_type_id' => $types['HOS']->id, 'annual_days' => 60]);
                }

                $cap = LeaveAggregateCap::query()->firstOrCreate(['name' => 'Had Sakit & Hospitalisasi'], ['cap_days' => 60, 'is_active' => true]);
                $cap->types()->syncWithoutDetaching([$types['SKT']->id, $types['HOS']->id]);

                $year = CarbonImmutable::today()->year;
                $from = CarbonImmutable::create($year, 1, 1);
                if (! LeavePeriod::query()->whereDate('from_date', '<=', $from->endOfYear()->toDateString())->whereDate('to_date', '>=', $from->toDateString())->exists()) {
                    LeavePeriod::query()->create(['name' => (string) $year, 'from_date' => $from->toDateString(), 'to_date' => $from->endOfYear()->toDateString()]);
                }

                $settings = LeaveSettings::current($organisationId);
                if ($settings->default_leave_policy_id === null) {
                    $settings->update(['default_leave_policy_id' => $policy->id]);
                }
            });
        });
    }

    /**
     * Our own seed values (spec-02 §6.4). Disabled examples are placeholders, not statutory.
     *
     * @return list<array<string, mixed>>
     */
    private function types(): array
    {
        $paid = ['pay_mode' => 'paid', 'paid_fraction' => '1'];

        return [
            ['code' => 'TAH', 'name' => 'Cuti Tahunan', 'name_en' => 'Annual Leave', 'colour' => '#2563eb', 'entitlement_basis' => 'per_period', ...$paid,
                'proration_method' => 'completed_months', 'rounding_step' => '1', 'allow_carry_forward' => true, 'carry_forward_expiry_days' => 365,
                'prorate_on_separation' => true, 'allow_encashment' => true, 'is_active' => true],
            ['code' => 'SKT', 'name' => 'Cuti Sakit', 'name_en' => 'Sick Leave', 'colour' => '#dc2626', 'entitlement_basis' => 'per_period', ...$paid,
                'proration_method' => 'none', 'requires_attachment' => 'always', 'max_backdate_days' => 7, 'is_active' => true],
            ['code' => 'HOS', 'name' => 'Cuti Hospitalisasi', 'name_en' => 'Hospitalisation Leave', 'colour' => '#9333ea', 'entitlement_basis' => 'per_period', ...$paid,
                'requires_attachment' => 'always', 'is_active' => true],
            ['code' => 'BSL', 'name' => 'Cuti Bersalin', 'name_en' => 'Maternity Leave', 'colour' => '#db2777', 'entitlement_basis' => 'per_event', ...$paid,
                'per_event_max_days' => 98, 'counts_holidays' => true, 'allow_half_day' => false, 'eligible_gender' => 'female', 'requires_event_date' => true, 'is_active' => true],
            ['code' => 'PTR', 'name' => 'Cuti Paterniti', 'name_en' => 'Paternity Leave', 'colour' => '#0891b2', 'entitlement_basis' => 'per_event', ...$paid,
                'per_event_max_days' => 7, 'counts_holidays' => true, 'allow_half_day' => false, 'eligible_gender' => 'male', 'eligible_marital_statuses' => ['married'],
                'min_service_days_to_apply' => 365, 'max_events_per_service' => 5, 'min_notice_days' => 30, 'notice_enforcement' => 'warn', 'is_active' => true],
            ['code' => 'CTG', 'name' => 'Cuti Tanpa Gaji', 'name_en' => 'Unpaid Leave', 'colour' => '#6b7280', 'entitlement_basis' => 'unlimited',
                'pay_mode' => 'unpaid', 'paid_fraction' => '0', 'requires_reason' => true, 'is_active' => true],
            ['code' => 'CGN', 'name' => 'Cuti Gantian', 'name_en' => 'Replacement Leave', 'colour' => '#16a34a', 'entitlement_basis' => 'compensatory', ...$paid,
                'compensatory_expiry_days' => 90, 'allow_half_day' => true, 'is_active' => true],
            ['code' => 'TKZ', 'name' => 'Cuti Takziah', 'name_en' => 'Compassionate Leave', 'colour' => '#475569', 'entitlement_basis' => 'per_event', ...$paid,
                'per_event_max_days' => 3, 'is_active' => false],
            ['code' => 'KHW', 'name' => 'Cuti Perkahwinan', 'name_en' => 'Marriage Leave', 'colour' => '#f59e0b', 'entitlement_basis' => 'per_event', ...$paid,
                'per_event_max_days' => 3, 'max_events_per_service' => 1, 'is_active' => false],
            ['code' => 'HJI', 'name' => 'Cuti Haji', 'name_en' => 'Hajj Leave', 'colour' => '#065f46', 'entitlement_basis' => 'per_event', ...$paid,
                'per_event_max_days' => 40, 'counts_holidays' => true, 'max_events_per_service' => 1, 'is_active' => false],
            ['code' => 'KCM', 'name' => 'Cuti Kecemasan', 'name_en' => 'Emergency Leave', 'colour' => '#ea580c', 'entitlement_basis' => 'per_period', ...$paid,
                'is_active' => false],
        ];
    }
}

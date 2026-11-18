<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Tetapan Payroll (spec-04 §2.2). Authorisation is done by the controller. */
final class PayrollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days_source' => ['in:leave_only,leave_and_attendance'],
            'unmarked_day_treatment' => ['in:present,absent'],
            'partial_month_basis' => ['in:follow_daily_rate,calendar_days'],
            'lwp_presentation' => ['in:reduce_earning,separate_deduction'],
            'y2_basis' => ['in:current_month,full_cycle'],
            'publish_slips_on' => ['in:submit,paid'],
            'email_slips' => ['boolean'],
            'pdf_password_mode' => ['in:none,ic_no,dob'],
            'show_employer_contributions' => ['boolean'],
            'show_ytd' => ['boolean'],
            'show_leave_balance' => ['boolean'],
            'ic_display' => ['in:full,masked'],
            'variance_threshold_percent' => ['numeric', 'min:0', 'max:999'],
            'block_unverified_rates' => ['boolean'],
            'include_withheld_in_statutory' => ['boolean'],
            'allow_submitter_mark_paid' => ['boolean'],
            'min_wage_check' => ['boolean'],
            'default_bank_account_id' => ['nullable', 'integer'],
            'kwsp_file_format_id' => ['nullable', 'integer'],
            'net_pay_rounding' => ['in:none,nearest_ringgit'],
        ];
    }
}

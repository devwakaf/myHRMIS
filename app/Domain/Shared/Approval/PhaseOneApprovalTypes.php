<?php

declare(strict_types=1);

namespace App\Domain\Shared\Approval;

use App\Domain\Shared\Approval\Enums\AttributeType;

/**
 * Phase 1 request types (A6.2) with their condition catalogues from
 * spec-02 §4.1 and spec-03 §4.12. The Approvable models arrive with M2/M3,
 * which call ApprovalTypeRegistry::bindModel() from their service providers;
 * until then HR can already configure the workflows ("Aliran Kelulusan").
 */
final class PhaseOneApprovalTypes
{
    public static function register(ApprovalTypeRegistry $registry): void
    {
        $n = AttributeType::Number;
        $s = AttributeType::Text;
        $e = AttributeType::Enum;
        $b = AttributeType::Boolean;

        $registry->register(new ApprovalTypeDefinition(
            key: 'leave',
            modelClass: null,
            labelKey: 'approval.types.leave',
            approveAnyPermission: 'leave.application.approve-any',
            viewAllPermission: 'leave.application.view-all',
            attributes: [
                'leave.days' => $n,
                'leave.unpaid_days' => $n,
                'leave.leave_type_code' => $s,
                'leave.entitlement_basis' => $e,
                'leave.pay_mode' => $e,
                'leave.is_backdated' => $b,
                'leave.is_on_behalf' => $b,
                'leave.block_list_hit' => $b,
            ],
            enumOptions: [
                'leave.entitlement_basis' => ['per_period', 'earned', 'per_event', 'compensatory', 'unlimited'],
                'leave.pay_mode' => ['paid', 'unpaid', 'partially_paid'],
            ],
        ));

        $registry->register(new ApprovalTypeDefinition(
            key: 'compensatory_leave',
            modelClass: null,
            labelKey: 'approval.types.compensatory_leave',
            approveAnyPermission: 'leave.application.approve-any',
            viewAllPermission: 'leave.application.view-all',
            attributes: [
                'compensatory_leave.days' => $n,
                'compensatory_leave.includes_public_holiday' => $b,
            ],
        ));

        $registry->register(new ApprovalTypeDefinition(
            key: 'attendance_request',
            modelClass: null,
            labelKey: 'approval.types.attendance_request',
            approveAnyPermission: 'attendance.request.approve-any',
            viewAllPermission: 'attendance.view-all',
            attributes: [
                'attendance_request.kind' => $e,
                'attendance_request.days' => $n,
                'attendance_request.is_backdated' => $b,
                'attendance_request.includes_rest_days' => $b,
            ],
            enumOptions: ['attendance_request.kind' => ['wfh', 'on_duty']],
        ));

        $registry->register(new ApprovalTypeDefinition(
            key: 'shift_request',
            modelClass: null,
            labelKey: 'approval.types.shift_request',
            approveAnyPermission: 'attendance.shift-request.approve-any',
            viewAllPermission: 'attendance.view-all',
            attributes: [
                'shift_request.days' => $n,
                'shift_request.shift_type_code' => $s,
                'shift_request.is_overnight' => $b,
            ],
        ));

        $registry->register(new ApprovalTypeDefinition(
            key: 'overtime_claim',
            modelClass: null,
            labelKey: 'approval.types.overtime_claim',
            approveAnyPermission: 'attendance.overtime.approve-any',
            viewAllPermission: 'attendance.overtime.view-all',
            attributes: [
                'overtime_claim.hours' => $n,
                'overtime_claim.amount' => $n,
                'overtime_claim.needs_compliance_review' => $b,
                'overtime_claim.eligibility' => $e,
            ],
            enumOptions: [
                'overtime_claim.eligibility' => ['eligible_wage', 'eligible_manual_labour', 'eligible_override', 'not_eligible'],
            ],
        ));
    }
}

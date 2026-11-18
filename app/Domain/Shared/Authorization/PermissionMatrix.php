<?php

declare(strict_types=1);

namespace App\Domain\Shared\Authorization;

/**
 * Master permission matrix (spec-00 A7, merged with the "+" rows of spec-02 §6.5,
 * spec-03 §8 and spec-04 §1.4).
 *
 * Super Admin is not a role: users.is_super_admin passes every Gate check.
 * "R" cells in the spec become a separate `.view` permission; "O" (own) and
 * "A" (assignee) cells are decided by policies / ApprovalService::canView, so
 * the permission is granted to the role and the policy narrows it.
 */
final class PermissionMatrix
{
    public const PENGURUS_HR = 'pengurus_hr';

    public const EKSEKUTIF_HR = 'eksekutif_hr';

    public const PEGAWAI_PAYROLL = 'pegawai_payroll';

    public const PELULUS_PAYROLL = 'pelulus_payroll';

    public const KEWANGAN = 'kewangan';

    public const PELULUS = 'pelulus';

    public const KAKITANGAN = 'kakitangan';

    /**
     * @return list<string>
     */
    public static function roles(): array
    {
        return [
            self::PENGURUS_HR,
            self::EKSEKUTIF_HR,
            self::PEGAWAI_PAYROLL,
            self::PELULUS_PAYROLL,
            self::KEWANGAN,
            self::PELULUS,
            self::KAKITANGAN,
        ];
    }

    /**
     * Roles HR may grant through the users screen (pelulus is derived, A6.8).
     *
     * @return list<string>
     */
    public static function assignableRoles(): array
    {
        return array_values(array_diff(self::roles(), [self::PELULUS]));
    }

    /**
     * permission => roles holding it.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $hrm = self::PENGURUS_HR;
        $hre = self::EKSEKUTIF_HR;
        $po = self::PEGAWAI_PAYROLL;
        $pa = self::PELULUS_PAYROLL;
        $fin = self::KEWANGAN;
        $pel = self::PELULUS;
        $kkt = self::KAKITANGAN;

        return [
            // System
            'system.organisations.manage' => [],
            'system.organisations.view' => [$hrm],
            'system.users.manage' => [$hrm],
            'system.reference-data.manage' => [],
            'system.reference-data.view' => [$po, $pa],
            'system.audit.view' => [$hrm],

            // Teras (Core)
            'core.settings.manage' => [$hrm],
            'core.settings.view' => [$hre],
            'core.approval-workflows.manage' => [$hrm],
            'core.approval-workflows.view' => [$hre],
            'approvals.inbox' => [$hrm, $hre, $po, $pa, $fin, $pel, $kkt],
            'core.masters.manage' => [$hrm, $hre],
            'core.masters.view' => [$po, $pa],
            'core.employee.view' => [$hrm, $hre, $po, $pa],
            'core.employee.manage' => [$hrm, $hre],
            'core.employee.correct' => [$hrm],
            'core.employee.view-sensitive' => [$hrm, $hre, $po, $pa],
            'core.employee.view-compensation' => [$hrm, $po, $pa],
            'core.employee.documents.manage' => [$hrm, $hre],
            'core.employee.self-edit' => [$kkt],
            'core.movement.create' => [$hrm, $hre],
            'core.movement.submit' => [$hrm, $hre],
            'core.movement.cancel' => [$hrm],
            'core.holiday.manage' => [$hrm, $hre],
            'core.holiday.view' => [$hrm, $hre, $kkt],
            'core.holiday-assignment.cancel' => [$hrm],
            'core.reports.view' => [$hrm, $hre, $po],

            // Cuti (spec-02 §6.5)
            'leave.config.manage' => [$hrm],
            'leave.config.view' => [$hre, $po],
            'leave.allocation.manage' => [$hrm, $hre],
            'leave.adjustment.submit' => [$hrm],
            'leave.encashment.manage' => [$hrm, $hre],
            'leave.encashment.submit' => [$hrm],
            'leave.encashment.view' => [$po],
            'leave.application.apply' => [$kkt],
            'leave.application.view-all' => [$hrm, $hre, $po],
            'leave.application.create-for-others' => [$hrm, $hre],
            'leave.application.approve-any' => [$hrm],
            'leave.application.cancel-approved' => [$hrm, $hre, $kkt],
            'leave.compensatory.request' => [$kkt],
            'leave.reports.view' => [$hrm, $hre, $po],
            'leave.attachments.view' => [$hrm, $hre],

            // Kehadiran & Shift (spec-03 §8)
            'attendance.config.manage' => [$hrm],
            'attendance.config.view' => [$hre, $po],
            'attendance.manage' => [$hrm, $hre],
            'attendance.view-all' => [$hrm, $hre, $po],
            'attendance.review.manage' => [$hrm, $hre],
            'attendance.checkin' => [$kkt],
            'attendance.checkin.void' => [$hrm],
            'attendance.location.view-coordinates' => [$hrm],
            'attendance.request.create' => [$kkt],
            'attendance.shift-request.create' => [$kkt],
            'attendance.request.create-for-others' => [$hrm, $hre],
            'attendance.request.approve-any' => [$hrm],
            'attendance.shift-request.approve-any' => [$hrm],
            'attendance.overtime.approve-any' => [$hrm],
            'attendance.overtime.view-all' => [$hrm, $hre, $po, $pa],
            'attendance.overtime.manage' => [$hrm, $hre],
            'attendance.overtime.submit-for-approval' => [$hrm, $hre],
            'attendance.overtime.cancel' => [$hrm],
            'attendance.reports.view' => [$hrm, $hre, $po, $pa],

            // Payroll & Statutori (spec-00 A7 + spec-04 §1.4)
            'payroll.config.manage' => [$po],
            'payroll.config.view' => [$hrm, $pa],
            'payroll.run.prepare' => [$po],
            'payroll.run.submit' => [$pa],
            'payroll.run.cancel' => [$pa],
            'payroll.run.mark-paid' => [$fin],
            'payroll.run.bank-file' => [$fin],
            'payroll.slip.view-all' => [$hrm, $po, $pa, $fin],
            'payroll.adjustments.manage' => [$po],
            'payroll.adjustments.view' => [$pa],
            'payroll.withholding.manage' => [$po],
            'payroll.withholding.release' => [$pa, $fin],
            'statutory.files.export' => [$po, $pa, $fin],
            'statutory.remittances.manage' => [$po, $fin],
            'statutory.ea.publish' => [$pa],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_keys(self::matrix());
    }

    /**
     * @return list<string>
     */
    public static function permissionsFor(string $role): array
    {
        $out = [];
        foreach (self::matrix() as $permission => $roles) {
            if (in_array($role, $roles, true)) {
                $out[] = $permission;
            }
        }

        return $out;
    }
}

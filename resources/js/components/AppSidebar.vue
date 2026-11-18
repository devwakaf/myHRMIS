<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    AlarmClock,
    ArrowLeftRight,
    Ban,
    BookUser,
    Building,
    CalendarCheck,
    CalendarClock,
    Banknote,
    Landmark,
    ReceiptText,
    FileSpreadsheet,
    Calculator,
    HandCoins,
    FileClock,
    PauseCircle,
    FileSignature,
    Percent,
    Layers3,
    ListOrdered,
    FileCheck2,
    CalendarOff,
    CalendarPlus,
    Coins,
    FileBarChart,
    Fingerprint,
    Gauge,
    Grid3x3,
    Hourglass,
    ListChecks,
    MapPinned,
    Scale,
    ScanSearch,
    SlidersHorizontal,
    Timer,
    UserCheck,
    Wallet,
    Building2,
    CalendarDays,
    CalendarRange,
    ClipboardCheck,
    ClipboardList,
    TableProperties,
    FileText,
    GitBranch,
    History,
    LayoutGrid,
    Layers,
    MapPin,
    Network,
    Settings2,
    ShieldCheck,
    Shuffle,
    UserCircle,
    Users,
    Workflow,
} from 'lucide-vue-next';
import type { LucideIcon } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';

type Item = { key: string; href: string; icon: LucideIcon; visible: boolean; badge?: number; label?: string };
type Group = { key: string; items: Item[] };

const page = usePage();
const { can, isSuperAdmin } = usePermissions();
const { isCurrentOrParentUrl } = useCurrentUrl();

const hasOrg = computed(() => page.props.organisation !== null);
const hasEmployee = computed(() => Boolean(page.props.auth?.user?.employee_id));

const groups = computed<Group[]>(() => [
    {
        key: 'main',
        items: [
            { key: 'dashboard', href: '/dashboard', icon: LayoutGrid, visible: true },
            { key: 'approvals', href: '/approvals', icon: ClipboardCheck, visible: hasOrg.value && can('approvals.inbox'), badge: page.props.pendingApprovals },
            { key: 'me', href: '/me', icon: UserCircle, visible: hasEmployee.value },
        ],
    },
    {
        key: 'core',
        items: [
            { key: 'employees', href: '/employees', icon: Users, visible: hasOrg.value && can('core.employee.view') },
            { key: 'movements', href: '/employee-movements', icon: Shuffle, visible: hasOrg.value && can('core.movement.create') },
            { key: 'departments', href: '/departments', icon: Network, visible: hasOrg.value && can('core.masters.manage', 'core.masters.view') },
            { key: 'branches', href: '/branches', icon: MapPin, visible: hasOrg.value && can('core.masters.manage', 'core.masters.view') },
            { key: 'designations', href: '/designations', icon: BookUser, visible: hasOrg.value && can('core.masters.manage', 'core.masters.view') },
            { key: 'grades', href: '/grades', icon: Layers, visible: hasOrg.value && can('core.masters.manage', 'core.masters.view') },
            { key: 'employment_types', href: '/employment-types', icon: GitBranch, visible: hasOrg.value && can('core.masters.manage', 'core.masters.view') },
            { key: 'document_types', href: '/document-types', icon: FileText, visible: hasOrg.value && can('core.masters.manage', 'core.masters.view') },
            { key: 'holiday_lists', href: '/holiday-lists', icon: CalendarDays, visible: hasOrg.value && can('core.holiday.manage') },
            { key: 'holiday_assignments', href: '/holiday-assignments', icon: CalendarRange, visible: hasOrg.value && can('core.holiday.manage') },
            { key: 'reports', href: '/reports/core/employees', icon: BarChart3, visible: hasOrg.value && can('core.reports.view') },
        ],
    },
    {
        key: 'leave',
        items: [
            { key: 'leave_me', label: 'leave.pages.me', href: '/leave/me', icon: CalendarCheck, visible: hasOrg.value && hasEmployee.value },
            { key: 'leave_calendar', label: 'leave.pages.calendar', href: '/leave/calendar', icon: CalendarRange, visible: hasOrg.value && (hasEmployee.value || can('leave.application.view-all')) },
            { key: 'leave_compensatory', label: 'leave.pages.compensatory', href: '/leave/compensatory-requests', icon: CalendarPlus, visible: hasOrg.value && (hasEmployee.value || can('leave.application.create-for-others')) },
            { key: 'leave_applications', label: 'leave.pages.applications', href: '/leave/applications', icon: UserCheck, visible: hasOrg.value && can('leave.application.view-all') },
            { key: 'leave_review_tasks', label: 'leave.pages.review_tasks', href: '/leave/review-tasks', icon: ListChecks, visible: hasOrg.value && can('leave.allocation.manage') },
            { key: 'leave_assignments', label: 'leave.pages.assignments', href: '/leave/policy-assignments', icon: Users, visible: hasOrg.value && can('leave.allocation.manage') },
            { key: 'leave_allocations', label: 'leave.pages.allocations', href: '/leave/allocations', icon: Wallet, visible: hasOrg.value && can('leave.allocation.manage') },
            { key: 'leave_adjustments', label: 'leave.pages.adjustments', href: '/leave/adjustments', icon: SlidersHorizontal, visible: hasOrg.value && can('leave.allocation.manage', 'leave.adjustment.submit') },
            { key: 'leave_encashments', label: 'leave.pages.encashments', href: '/leave/encashments', icon: Coins, visible: hasOrg.value && can('leave.encashment.manage', 'leave.encashment.view') },
            { key: 'leave_credit_schedules', label: 'leave.pages.credit_schedules', href: '/leave/credit-schedules', icon: CalendarClock, visible: hasOrg.value && can('leave.allocation.manage') },
            { key: 'leave_reports', label: 'leave.reports.title', href: can('leave.allocation.manage') ? '/reports/leave/balances' : '/reports/leave/unpaid', icon: FileBarChart, visible: hasOrg.value && can('leave.reports.view') },
        ],
    },
    {
        key: 'leave_config',
        items: [
            { key: 'leave_types', label: 'leave.pages.types', href: '/leave/types', icon: CalendarOff, visible: hasOrg.value && can('leave.config.manage', 'leave.config.view') },
            { key: 'leave_periods', label: 'leave.pages.periods', href: '/leave/periods', icon: Hourglass, visible: hasOrg.value && can('leave.config.manage', 'leave.config.view', 'leave.allocation.manage') },
            { key: 'leave_policies', label: 'leave.pages.policies', href: '/leave/policies', icon: Scale, visible: hasOrg.value && can('leave.config.manage', 'leave.config.view') },
            { key: 'leave_caps', label: 'leave.pages.aggregate_caps', href: '/leave/aggregate-caps', icon: Gauge, visible: hasOrg.value && can('leave.config.manage', 'leave.config.view') },
            { key: 'leave_block_lists', label: 'leave.pages.block_lists', href: '/leave/block-lists', icon: Ban, visible: hasOrg.value && can('leave.config.manage', 'leave.config.view') },
            { key: 'leave_settings', label: 'leave.pages.settings', href: '/settings/leave', icon: Settings2, visible: hasOrg.value && can('leave.config.manage', 'leave.config.view') },
        ],
    },
    {
        key: 'attendance',
        items: [
            { key: 'attendance_checkin', label: 'attendance.pages.checkin', href: '/check-in', icon: Fingerprint, visible: hasOrg.value && hasEmployee.value && can('attendance.checkin') },
            { key: 'attendance_me', label: 'attendance.pages.me', href: '/me/attendance', icon: CalendarCheck, visible: hasOrg.value && hasEmployee.value },
            { key: 'attendance_requests', label: 'attendance.pages.requests', href: '/attendance-requests', icon: ClipboardList, visible: hasOrg.value && (hasEmployee.value || can('attendance.view-all', 'attendance.request.create-for-others')) },
            { key: 'attendance_shift_requests', label: 'attendance.pages.shift_requests', href: '/shift-requests', icon: ArrowLeftRight, visible: hasOrg.value && (hasEmployee.value || can('attendance.view-all', 'attendance.request.create-for-others')) },
            { key: 'attendance_claims', label: 'attendance.pages.overtime_claims', href: '/attendance/overtime-claims', icon: Timer, visible: hasOrg.value && (hasEmployee.value || can('attendance.overtime.view-all')) },
            { key: 'attendance_assignments', label: 'attendance.pages.shift_assignments', href: '/attendance/shift-assignments', icon: UserCheck, visible: hasOrg.value && can('attendance.manage', 'attendance.view-all') },
            { key: 'attendance_roster', label: 'attendance.pages.roster', href: '/attendance/roster', icon: CalendarRange, visible: hasOrg.value && can('attendance.manage', 'attendance.view-all') },
            { key: 'attendance_bulk_mark', label: 'attendance.pages.bulk_mark', href: '/attendance/bulk-mark', icon: Grid3x3, visible: hasOrg.value && can('attendance.manage') },
            { key: 'attendance_records', label: 'attendance.pages.records', href: '/attendance/records', icon: TableProperties, visible: hasOrg.value && can('attendance.manage', 'attendance.view-all') },
            { key: 'attendance_review', label: 'attendance.pages.review', href: '/attendance/review', icon: ScanSearch, visible: hasOrg.value && can('attendance.review.manage'), badge: page.props.attendanceReviewCount },
            { key: 'attendance_checkins', label: 'attendance.pages.checkins', href: '/attendance/checkins', icon: AlarmClock, visible: hasOrg.value && can('attendance.view-all') },
            { key: 'attendance_reports', label: 'attendance.reports.title', href: '/reports/attendance/monthly', icon: FileBarChart, visible: hasOrg.value && can('attendance.reports.view') },
        ],
    },
    {
        key: 'attendance_config',
        items: [
            { key: 'attendance_shift_types', label: 'attendance.pages.shift_types', href: '/attendance/shift-types', icon: Timer, visible: hasOrg.value && can('attendance.config.manage', 'attendance.config.view') },
            { key: 'attendance_locations', label: 'attendance.pages.locations', href: '/attendance/locations', icon: MapPinned, visible: hasOrg.value && can('attendance.config.manage', 'attendance.config.view') },
            { key: 'attendance_schedules', label: 'attendance.pages.schedules', href: '/attendance/shift-schedules', icon: CalendarClock, visible: hasOrg.value && can('attendance.config.manage', 'attendance.config.view') },
            { key: 'attendance_ot_types', label: 'attendance.pages.overtime_types', href: '/attendance/overtime-types', icon: Coins, visible: hasOrg.value && can('attendance.config.manage', 'attendance.config.view') },
            { key: 'attendance_settings', label: 'attendance.pages.settings', href: '/settings/attendance', icon: Settings2, visible: hasOrg.value && can('attendance.config.manage', 'attendance.config.view') },
        ],
    },
    {
        key: 'payroll',
        items: [
            { key: 'payroll_my_slips', label: 'payroll.pages.my_payslips', href: '/me/payslips', icon: ReceiptText, visible: hasOrg.value && hasEmployee.value },
            { key: 'payroll_my_ea', label: 'payroll.pages.my_ea', href: '/me/ea', icon: FileCheck2, visible: hasOrg.value && hasEmployee.value },
            { key: 'payroll_runs', label: 'payroll.pages.runs', href: '/payroll/runs', icon: Banknote, visible: hasOrg.value && can('payroll.run.prepare', 'payroll.run.submit', 'payroll.run.mark-paid', 'payroll.slip.view-all') },
            { key: 'payroll_slips', label: 'payroll.pages.slips', href: '/payroll/slips', icon: FileText, visible: hasOrg.value && can('payroll.slip.view-all') },
            { key: 'payroll_assignments', label: 'payroll.pages.assignments', href: '/payroll/assignments', icon: ListOrdered, visible: hasOrg.value && can('payroll.config.manage', 'payroll.config.view') },
            { key: 'payroll_additional', label: 'payroll.pages.additional_salaries', href: '/payroll/additional-salaries', icon: HandCoins, visible: hasOrg.value && can('payroll.adjustments.manage', 'payroll.adjustments.view') },
            { key: 'payroll_arrears', label: 'payroll.pages.arrears', href: '/payroll/arrears', icon: FileClock, visible: hasOrg.value && can('payroll.adjustments.manage', 'payroll.adjustments.view') },
            { key: 'payroll_corrections', label: 'payroll.pages.corrections', href: '/payroll/corrections', icon: Calculator, visible: hasOrg.value && can('payroll.adjustments.manage', 'payroll.adjustments.view') },
            { key: 'payroll_withholdings', label: 'payroll.pages.withholdings', href: '/payroll/withholdings', icon: PauseCircle, visible: hasOrg.value && can('payroll.withholding.manage', 'payroll.withholding.release', 'payroll.adjustments.view') },
            { key: 'payroll_declarations', label: 'payroll.pages.declarations', href: '/payroll/declarations', icon: FileSignature, visible: hasOrg.value && can('payroll.adjustments.manage', 'payroll.adjustments.view') },
            { key: 'payroll_periods', label: 'payroll.pages.periods', href: '/payroll/periods', icon: CalendarDays, visible: hasOrg.value && can('payroll.run.prepare', 'payroll.run.submit', 'payroll.run.mark-paid', 'payroll.slip.view-all') },
            { key: 'payroll_register', label: 'payroll.pages.register', href: '/payroll/reports/register', icon: FileSpreadsheet, visible: hasOrg.value && can('payroll.slip.view-all') },
        ],
    },
    {
        key: 'payroll_config',
        items: [
            { key: 'payroll_components', label: 'payroll.pages.components', href: '/payroll/components', icon: Layers3, visible: hasOrg.value && can('payroll.config.manage', 'payroll.config.view') },
            { key: 'payroll_structures', label: 'payroll.pages.structures', href: '/payroll/structures', icon: Layers, visible: hasOrg.value && can('payroll.config.manage', 'payroll.config.view') },
            { key: 'payroll_settings', label: 'payroll.pages.settings', href: '/payroll/settings', icon: Settings2, visible: hasOrg.value && can('payroll.config.manage', 'payroll.config.view') },
        ],
    },
    {
        key: 'statutory',
        items: [
            { key: 'statutory_files', label: 'statutory.pages.files', href: '/statutory/files', icon: Landmark, visible: hasOrg.value && can('statutory.files.export') },
            { key: 'statutory_listings', label: 'statutory.pages.listings', href: '/statutory/listings/kwsp', icon: FileText, visible: hasOrg.value && can('statutory.files.export') },
            { key: 'statutory_remittances', label: 'statutory.pages.remittances', href: '/statutory/remittances', icon: Coins, visible: hasOrg.value && can('statutory.remittances.manage', 'statutory.files.export') },
            { key: 'statutory_ea', label: 'statutory.pages.ea', href: '/statutory/ea', icon: FileCheck2, visible: hasOrg.value && can('statutory.files.export', 'statutory.ea.publish') },
            { key: 'statutory_rates', label: 'statutory.pages.rates', href: '/statutory/rates', icon: Percent, visible: hasOrg.value && can('system.reference-data.manage', 'system.reference-data.view') },
        ],
    },
    {
        key: 'settings',
        items: [
            { key: 'organisations', href: '/organisations', icon: Building2, visible: isSuperAdmin.value || can('system.organisations.view') },
            { key: 'hr_settings', href: '/settings/hr', icon: Settings2, visible: hasOrg.value && can('core.settings.manage', 'core.settings.view') },
            { key: 'approval_workflows', href: '/settings/approval-workflows', icon: Workflow, visible: hasOrg.value && can('core.approval-workflows.manage', 'core.approval-workflows.view') },
            { key: 'users', href: '/users', icon: ShieldCheck, visible: hasOrg.value && can('system.users.manage') },
            { key: 'audit', href: '/audit', icon: History, visible: hasOrg.value && can('system.audit.view') },
        ],
    },
]);

const visibleGroups = computed(() =>
    groups.value
        .map((g) => ({ ...g, items: g.items.filter((i) => i.visible) }))
        .filter((g) => g.items.length > 0),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link href="/dashboard">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <SidebarGroup v-for="group in visibleGroups" :key="group.key" class="px-2 py-0">
                <SidebarGroupLabel>{{ $t(`common.nav.groups.${group.key}`) }}</SidebarGroupLabel>
                <SidebarMenu>
                    <SidebarMenuItem v-for="item in group.items" :key="item.key">
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentOrParentUrl(item.href)"
                            :tooltip="$t(item.label ?? `common.nav.${item.key}`)"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ $t(item.label ?? `common.nav.${item.key}`) }}</span>
                            </Link>
                        </SidebarMenuButton>
                        <SidebarMenuBadge v-if="item.badge">{{ item.badge }}</SidebarMenuBadge>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <div class="px-2 pb-1 text-[11px] text-muted-foreground group-data-[collapsible=icon]:hidden">
                <Building class="mr-1 inline size-3" />{{ page.props.organisation?.name ?? $t('common.nav.no_organisation') }}
            </div>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

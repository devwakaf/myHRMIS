<?php

return [

    'types' => [
        'leave' => 'Leave Application',
        'compensatory_leave' => 'Compensatory Leave Request',
        'attendance_request' => 'Attendance Request',
        'shift_request' => 'Shift Request',
        'overtime_claim' => 'Overtime (OT) Claim',
        'test' => 'Test Request',
    ],

    'rule_types' => [
        'named_approver' => 'Employee Approver',
        'department_approvers' => 'Department Approvers',
        'named_or_department' => 'Default Approver',
        'department_head' => 'Head of Department',
        'reports_to' => 'Supervisor',
        'role' => 'Role',
        'specific_user' => 'Specific User',
    ],

    'modes' => [
        'any_one' => 'Any one',
        'all' => 'All',
    ],

    'on_no_approver' => [
        'fail' => 'Fail (cannot submit)',
        'skip' => 'Skip the step',
        'hr_queue' => 'Send to HR "No Approver" queue',
    ],

    'selection' => [
        'first' => 'First approver',
        'all' => 'All approvers',
        'applicant_chooses' => 'Applicant chooses',
    ],

    'request_status' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ],

    'step_status' => [
        'waiting' => 'Not Started',
        'active' => 'In Progress',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'skipped' => 'Skipped',
        'cancelled' => 'Cancelled',
    ],

    'skip_reasons' => [
        'condition_false' => 'Condition not met',
        'applicant_only' => 'Applicant was the only approver',
        'same_as_previous' => 'Same approver as previous step',
        'no_approver' => 'No approver',
    ],

    'assignee_origin' => [
        'rule' => 'Rule',
        'applicant_choice' => 'Applicant choice',
        'reassigned' => 'Reassigned',
    ],

    'assignee_decision' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'not_required' => 'Not required',
        'removed' => 'Removed',
    ],

    'action_types' => [
        'submitted' => 'Submitted',
        'step_activated' => 'Step started',
        'step_skipped' => 'Step skipped',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'override_approved' => 'Approved by HR (override)',
        'override_rejected' => 'Rejected by HR (override)',
        'reassigned' => 'Reassigned',
        'reminded' => 'Reminder sent',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
    ],

    'decisions' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
    ],

    'attribute_types' => [
        'number' => 'Number',
        'string' => 'Text',
        'enum' => 'Choice',
        'bool' => 'Yes/No',
    ],

    'attributes' => [
        'leave' => [
            'days' => 'Leave days',
            'unpaid_days' => 'Unpaid days',
            'leave_type_code' => 'Leave type code',
            'entitlement_basis' => 'Entitlement basis',
            'pay_mode' => 'Pay mode',
            'is_backdated' => 'Backdated',
            'is_on_behalf' => 'Applied on behalf',
            'block_list_hit' => 'Hits a blocked date',
        ],
        'compensatory_leave' => [
            'days' => 'Days claimed',
            'includes_public_holiday' => 'Includes a public holiday',
        ],
        'attendance_request' => [
            'kind' => 'Request kind',
            'days' => 'Number of days',
            'is_backdated' => 'Backdated',
            'includes_rest_days' => 'Includes rest days',
        ],
        'shift_request' => [
            'days' => 'Number of days',
            'shift_type_code' => 'Shift type code',
            'is_overnight' => 'Overnight shift',
        ],
        'overtime_claim' => [
            'hours' => 'OT hours',
            'amount' => 'OT amount',
            'needs_compliance_review' => 'Needs compliance review',
            'eligibility' => 'OT eligibility',
        ],
        'test' => [
            'days' => 'Number of days (test)',
        ],
        'applicant' => [
            'department_id' => 'Applicant department',
            'branch_id' => 'Applicant branch',
            'grade_rank' => 'Applicant grade rank',
            'employment_type_category' => 'Applicant employment category',
            'citizenship_status' => 'Applicant citizenship',
        ],
    ],

    'fields' => [
        'workflow_name' => 'Workflow name',
        'step_name' => 'Step name',
        'rule_type' => 'Rule Type',
        'mode' => 'Mode',
        'selection' => 'Approver selection',
        'walk_ancestors' => 'Look in parent departments if none',
        'walk_up' => 'Go up to the parent department if the post is vacant / the applicant',
        'level' => 'Supervisor level',
        'role' => 'Role',
        'user' => 'User',
        'on_no_approver' => 'If no approver',
        'remind_after_days' => 'Remind after (days)',
        'skip_if_applicant' => 'Skip if the applicant is the only approver',
        'skip_if_same_as_previous' => 'Skip if same approver as the previous step',
        'exclude_submitter' => 'Submitter may not approve',
        'condition' => 'Condition (all must hold)',
        'prevent_self_approval' => 'Applicants may not approve their own requests',
        'allow_override' => 'Allow HR to override decisions',
        'notify_applicant_each_step' => 'Notify the applicant after each step',
    ],

    'defaults' => [
        'workflow_name' => 'Standard Approval',
        'step_name' => 'Approver',
    ],

    'errors' => [
        'unknown_type' => 'Request type ":type" is not registered.',
        'no_active_workflow' => 'There is no active approval workflow for :type.',
        'no_approver_found' => 'No approver for step :sequence (":step"). The request cannot be submitted.',
        'choice_not_allowed' => 'The chosen approver is not allowed for step ":step".',
        'choice_required' => 'Please choose an approver for step ":step".',
        'already_submitted' => 'This document has already been submitted for approval.',
        'override_not_allowed' => 'You are not allowed to override this approval.',
        'reassign_empty' => 'Choose at least one approver.',
        'reassign_not_member' => 'Approvers must be active users of this organisation.',
        'self_approval' => 'You cannot approve your own request.',
        'submitter_approval' => 'The submitter of a request cannot approve it.',
        'already_decided' => 'This step has already been decided.',
        'not_assignee' => 'You are not an approver of the current step.',
        'not_pending' => 'This request is no longer pending.',
        'note_required' => 'A note is required to reject.',
        'reason_required' => 'A reason is required.',
        'unknown_attribute' => 'Condition #:n: attribute ":attribute" is not in the catalogue.',
        'unknown_operator' => 'Condition #:n: operator ":operator" is invalid.',
        'operator_not_numeric' => 'Comparison operators are for number attributes only (:attribute).',
        'value_not_numeric' => 'The value for :attribute must be a number.',
        'value_not_allowed' => 'Value ":value" is not allowed for :attribute.',
        'step_count' => 'A workflow must have 1 to 10 steps.',
        'invalid_rule' => 'Invalid rule type.',
        'invalid_mode' => 'Invalid mode.',
        'invalid_on_no_approver' => 'Invalid "if no approver" option.',
        'invalid_role' => 'Invalid role.',
        'invalid_user' => 'The user must be an active member of this organisation.',
        'invalid_remind_days' => 'Reminder days must be between 1 and 60.',
        'self_approver' => 'An employee cannot be their own approver.',
        'bulk_one_type' => 'Bulk approval works for one request type at a time.',
    ],

    'mail' => [
        'greeting' => 'Hello :name,',
        'open' => 'Open My Approvals',
        'requested' => ['subject' => ':type awaiting your approval', 'line' => 'A :type from :applicant is waiting for your action.'],
        'progressed' => ['subject' => 'Your :type passed a step', 'line' => 'Your :type was approved at one step and moved to the next.'],
        'approved' => ['subject' => 'Your :type was approved', 'line' => 'Your :type has been fully approved.'],
        'rejected' => ['subject' => 'Your :type was rejected', 'line' => 'Your :type was rejected. Please check the approver note.'],
        'cancelled' => ['subject' => ':type cancelled', 'line' => 'The :type from :applicant was cancelled. No action is needed.'],
        'reassigned' => ['subject' => ':type assigned to you', 'line' => 'HR assigned the :type from :applicant to you.'],
        'reminder' => ['subject' => 'Reminder: :type awaiting your approval', 'line' => 'The :type from :applicant is still waiting for your action.'],
        'no_approver' => ['subject' => 'No approver: :type', 'line' => 'Step ":step" of the :type from :applicant has no approver. Please assign one.'],
    ],

    'flash' => [
        'approved' => 'The request was approved.',
        'rejected' => 'The request was rejected.',
        'overridden' => 'The HR decision was recorded.',
        'reassigned' => 'Approvers were reassigned.',
        'withdrawn' => 'The request was withdrawn.',
        'bulk_approved' => ':count requests approved.',
        'published' => 'Approval workflow version :version published.',
    ],

    'preview' => [
        'summary' => 'Approval route preview',
        'candidates' => 'Candidates',
        'outcomes' => [
            'pending' => 'goes to approver',
            'skipped' => 'skipped',
            'no_approver_fail' => 'NO APPROVER — submission will fail',
            'hr_queue' => 'to HR queue (no approver)',
            'choice_required' => 'applicant must choose an approver',
        ],
    ],

    'context' => [
        'days' => 'Number of days',
    ],

    'inbox' => [
        'description' => 'Requests awaiting your action and your decision history.',
        'tabs' => [
            'mine' => 'Awaiting My Action',
            'processed' => 'Processed by Me',
            'submitted' => 'My Requests',
            'all_pending' => 'All Pending',
            'no_approver' => 'No Approver',
        ],
        'columns' => [
            'type' => 'Type',
            'applicant' => 'Applicant',
            'summary' => 'Summary',
            'step' => 'Step',
            'submitted_at' => 'Submitted',
            'waiting' => 'Waiting',
        ],
        'all_types' => 'All types',
        'applicant' => 'Applicant name / no.',
        'days' => ':days days',
        'empty' => 'No requests.',
        'bulk_approve' => 'Approve :count selected',
        'decision_context' => 'Decision information',
        'timeline' => 'Approval Route',
        'history' => 'Action history',
        'system' => 'System',
        'no_assignees' => 'No approver — waiting for HR.',
        'note' => 'Note (optional when approving)',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'override_hint' => 'HR actions: override or reassign the current step (reason required).',
        'override_approve' => 'Approve on behalf',
        'override_reject' => 'Reject on behalf',
        'reassign' => 'Reassign',
        'withdraw' => 'Withdraw',
    ],

    'workflows' => [
        'description' => 'Multi-level approval workflows for each request type.',
        'editor_description' => 'Arrange the approval steps. A version that has been used is never changed — publishing creates a new version.',
        'module_pending' => 'This module is built in a later milestone; the workflow can be set up now.',
        'has_condition' => 'Conditional',
        'published_at' => 'Published :at',
        'none' => 'No active workflow.',
        'current_version' => 'Current version: v:version',
        'used_note' => 'already used — saving creates a new version',
        'publish' => 'Publish',
        'step_n' => 'Step :n',
        'step_name_hint' => 'e.g. Head of Department',
        'add_condition' => 'Add condition',
        'add_step' => 'Add step',
        'preview' => 'Preview',
        'preview_hint' => 'Preview the active version for an employee and sample values.',
        'pick_employee' => 'Choose an employee',
        'run_preview' => 'Run preview',
        'history' => 'Version history',
    ],

];

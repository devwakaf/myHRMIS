<?php

// Statutory module (spec-04 §2.11, §6, §7). KWSP, PERKESO, SIP, PCB, LHDN, HRD Corp kept as official abbreviations.
return [
    'title' => 'Statutory',

    'pages' => [
        'rates' => 'Statutory Rates',
        'rate_set' => 'Statutory Rate Set',
        'files' => 'Statutory Files',
        'remittances' => 'Statutory Payments',
        'ea' => 'EA Forms',
        'listings' => 'Statutory Listings',
    ],

    'messages' => [
        'copied' => 'New (draft) rate set created.',
        'imported' => ':rows rows imported.',
        'published' => 'Rate set published (not yet verified).',
        'fixtures_failed' => 'Fixture tests failed: :failures',
        'verified' => 'Rate set verified.',
        'unverified_banner' => 'These rates have not been verified against the official source.',
        'format_unverified' => 'Format not verified.',
        'file_submitted' => 'File marked as submitted.',
        'remittance_saved' => 'Payment recorded.',
    ],

    'errors' => [
        'band_incomplete' => ':scheme table incomplete: column :column of band :from–:to is empty.',
        'no_rate_set' => 'No statutory rates in force for :scheme on :date.',
        'epf_band_missing' => 'KWSP Part :part table has no band for a wage of RM :wage (set :code).',
        'socso_band_missing' => 'PERKESO table has no band for a wage of RM :wage (set :code).',
        'eis_band_missing' => 'SIP table has no band for a wage of RM :wage (set :code).',
        'mtd_band_missing' => 'PCB table has no range for P = RM :p (set :code).',
        'params_missing' => 'Parameters of set :code are missing.',
        'import_table' => 'Table :table cannot be imported for this scheme.',
        'import_effective_from' => 'An effective date is required for a new set.',
        'set_not_draft' => 'Set :code is not a draft.',
        'scheme_mismatch' => 'Set :code belongs to another scheme.',
        'import_empty' => 'The CSV file is empty.',
        'import_columns' => 'Required columns missing: :columns.',
        'import_placeholder' => 'Line :line column :column still holds a placeholder; copy the value from the official table.',
        'import_blank' => 'Line :line column :column is blank.',
        'verify_status' => 'Only an unverified set can be verified.',
        'verifier_is_creator' => 'The verifier must differ from the creator of the set.',
        'placeholder_cannot_verify' => 'A set with placeholder bands cannot be verified.',
        'placeholder_production' => 'Placeholder bands are not allowed in production.',
        'placeholder_verified' => 'A verified set cannot be changed.',
        'record_length' => 'Record length :actual, expected :expected.',
        'missing_id' => 'Employee :employee: :field missing.',
        'employee_no_too_long' => 'Employee number :employee is longer than 10 characters (CP39).',
        'no_file_format' => 'No :scheme file format in force.',
    ],

    'enums' => [
        'rate_scheme' => [
            'epf' => 'KWSP', 'socso' => 'PERKESO & SKBBK', 'eis' => 'SIP', 'mtd' => 'PCB/MTD', 'tax_reliefs' => 'Tax Reliefs & Exemptions',
            'hrdc' => 'HRD Corp', 'minimum_wage' => 'Minimum Wage',
        ],
        'rate_set_status' => ['draft' => 'Draft', 'unverified' => 'Not Verified', 'verified' => 'Verified'],
        'epf_part' => ['A' => 'Part A', 'C' => 'Part C', 'E' => 'Part E', 'F' => 'Part F', 'none' => 'No contribution'],
        'citizenship_group' => ['citizen' => 'Citizen', 'pr_or_pre1998' => 'PR / non-citizen before 1998', 'foreign' => 'Non-citizen'],
        'epf_percent_rounding' => ['ceil_each_share' => 'Round up each share', 'ceil_total_then_split' => 'Round up the total, then split'],
        'epf_bonus_base' => ['band_upper' => 'Band upper limit', 'actual_wage' => 'Actual wage'],
        'age_switch_rule' => ['month_after_birthday' => 'Month after the birthday month', 'birthday_month' => 'Birthday month'],
        'socso_category' => ['1' => 'Category 1', '2' => 'Category 2', 'none' => 'None'],
        'file_scheme' => ['kwsp' => 'KWSP', 'perkeso' => 'PERKESO', 'cp39' => 'CP39', 'ea' => 'EA Form', 'cp8d' => 'CP8D', 'pcb2ii' => 'PCB 2(II)'],
        'format_status' => ['unverified' => 'Not Verified', 'verified' => 'Verified'],
    ],

    'rates' => [
        'description' => 'Rate sets per scheme. Unverified sets are marked amber and raise a warning on every payroll screen that uses them.',
        'placeholder_badge' => 'PLACEHOLDER', 'placeholder_message' => 'This set contains placeholder bands for development only and can neither be verified nor used for real payments.',
        'unverified_title' => 'Unverified rates', 'unverified_message' => 'The following sets are not yet verified against an official source: :codes.', 'banner_draft' => 'This set is still a draft', 'banner_unverified' => 'This set is not verified',
        'formats' => 'File formats', 'effective' => 'Effective', 'effective_from' => 'Effective from', 'open_ended' => 'open', 'source' => 'Source', 'source_url' => 'Source URL', 'version' => 'Version',
        'copy' => 'Copy as new set', 'import' => 'Import CSV', 'import_hint' => 'Draft sets only. Placeholder cells are rejected.', 'import_table' => 'Table', 'import_table_default' => 'Main table of the scheme', 'import_file' => 'CSV file',
        'run_fixtures' => 'Run fixture tests', 'fixtures_ok' => 'All fixture tests passed.', 'fixtures_failed' => 'Fixture tests failed:', 'publish' => 'Publish', 'verify' => 'Verify',
        'verify_confirm' => 'Verify set :code? The verifier must differ from the creator and the tables must be checked against the official source.',
        'two_person' => 'Two-person rule: the verifier must differ from the creator of the set.', 'delete_confirm' => 'Delete draft set :code?', 'fixture_passed' => 'Fixture tests passed', 'verified_at' => 'Verified at',
        'truncated' => 'Showing :limit of :total rows.', 'csv_columns' => 'CSV columns',
    ],

    'ea' => [
        'description' => 'Yearly EA forms: generate, publish (Payroll Approver) and download as PDF. PCB 2(II) per employee can be downloaded here.', 'generate' => 'Generate EA forms :year',
        'layout_notice' => 'The layout and box codes of the 2026 EA form are not verified against the official form.', 'year' => 'Year', 'serial' => 'Serial no.', 'publish' => 'Publish', 'empty' => 'No EA forms for this year yet.',
    ],

    'files' => [
        'description' => 'PERKESO, CP39 and EPF upload files for one month, built from submitted slips. Records with incomplete data stop the file from being produced.',
        'kwsp_notice_title' => 'EPF file format not verified', 'kwsp_notice' => 'The EPF file format has not been verified against the official specification; check the file before uploading.',
        'kinds' => ['perkeso' => 'PERKESO file (278 characters)', 'cp39' => 'CP39 (LHDN)', 'kwsp' => 'EPF file'],
        'kind_hint' => ['perkeso' => 'Combined ASSIST 2.0 format: SOCSO, SKBBK and EIS.', 'cp39' => 'Monthly MTD and CP38 deductions.', 'kwsp' => 'EPF contributions of the following month.'],
        'generate' => 'Generate file', 'generated' => 'Generated', 'submitted' => 'Submitted', 'mark_submitted' => 'Mark as submitted', 'external_ref' => 'Submission reference',
        'listings' => 'Listings', 'recorded_payments' => 'Recorded payments', 'no_payments' => 'No payments recorded for this month yet.',
    ],

    'listings' => [
        'names' => ['kwsp' => 'EPF contribution listing', 'perkeso' => 'SOCSO contribution listing', 'sip' => 'EIS contribution listing', 'pcb' => 'MTD & zakat listing', 'hrdc' => 'HRD Corp levy listing'],
        'rows' => ':count rows', 'malaysian' => ':count citizens/PR',
        'columns' => [
            'employee_no' => 'Employee no.', 'full_name' => 'Name', 'member_id' => 'Member no.', 'part' => 'Part', 'wage' => 'Wage', 'employer' => 'Employer', 'employee' => 'Employee', 'total' => 'Total',
            'category' => 'Category', 'skbbk' => 'SKBBK', 'tin' => 'Tax no.', 'pcb' => 'MTD', 'cp38' => 'CP38', 'zakat' => 'Zakat', 'citizenship' => 'Citizenship', 'rate' => 'Rate', 'levy' => 'Levy',
        ],
    ],

    'remittances' => [
        'description' => 'Records of payments to EPF, SOCSO, LHDN, HRD Corp and the zakat authority; used for reminders and PCB 2(II).', 'new' => 'Record payment', 'scheme' => 'Scheme',
        'paid_on' => 'Paid on', 'receipt_no' => 'Receipt no.',
    ],
];

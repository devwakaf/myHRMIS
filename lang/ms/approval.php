<?php

return [

    'types' => [
        'leave' => 'Permohonan Cuti',
        'compensatory_leave' => 'Permohonan Cuti Gantian',
        'attendance_request' => 'Permohonan Kehadiran',
        'shift_request' => 'Permohonan Shift',
        'overtime_claim' => 'Tuntutan Kerja Lebih Masa (OT)',
        'test' => 'Permohonan Ujian',
    ],

    'rule_types' => [
        'named_approver' => 'Pelulus Kakitangan',
        'department_approvers' => 'Pelulus Jabatan',
        'named_or_department' => 'Pelulus Lalai',
        'department_head' => 'Ketua Jabatan',
        'reports_to' => 'Penyelia',
        'role' => 'Peranan',
        'specific_user' => 'Pengguna Tertentu',
    ],

    'modes' => [
        'any_one' => 'Mana-mana satu',
        'all' => 'Semua',
    ],

    'on_no_approver' => [
        'fail' => 'Gagal (tidak boleh dihantar)',
        'skip' => 'Langkau langkah',
        'hr_queue' => 'Ke barisan HR "Tanpa Pelulus"',
    ],

    'selection' => [
        'first' => 'Pelulus pertama',
        'all' => 'Semua pelulus',
        'applicant_chooses' => 'Pemohon memilih',
    ],

    'request_status' => [
        'pending' => 'Menunggu',
        'approved' => 'Diluluskan',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
    ],

    'step_status' => [
        'waiting' => 'Belum Bermula',
        'active' => 'Dalam Tindakan',
        'approved' => 'Diluluskan',
        'rejected' => 'Ditolak',
        'skipped' => 'Dilangkau',
        'cancelled' => 'Dibatalkan',
    ],

    'skip_reasons' => [
        'condition_false' => 'Syarat tidak dipenuhi',
        'applicant_only' => 'Hanya pemohon sendiri',
        'same_as_previous' => 'Pelulus sama dengan langkah sebelumnya',
        'no_approver' => 'Tiada pelulus',
    ],

    'assignee_origin' => [
        'rule' => 'Peraturan',
        'applicant_choice' => 'Pilihan pemohon',
        'reassigned' => 'Ditugaskan semula',
    ],

    'assignee_decision' => [
        'pending' => 'Menunggu',
        'approved' => 'Lulus',
        'rejected' => 'Tolak',
        'not_required' => 'Tidak diperlukan',
        'removed' => 'Dikeluarkan',
    ],

    'action_types' => [
        'submitted' => 'Dihantar',
        'step_activated' => 'Langkah dimulakan',
        'step_skipped' => 'Langkah dilangkau',
        'approved' => 'Diluluskan',
        'rejected' => 'Ditolak',
        'override_approved' => 'Diluluskan oleh HR (atasi)',
        'override_rejected' => 'Ditolak oleh HR (atasi)',
        'reassigned' => 'Ditugaskan semula',
        'reminded' => 'Peringatan dihantar',
        'cancelled' => 'Dibatalkan',
        'completed' => 'Selesai',
    ],

    'decisions' => [
        'approve' => 'Lulus',
        'reject' => 'Tolak',
    ],

    'attribute_types' => [
        'number' => 'Nombor',
        'string' => 'Teks',
        'enum' => 'Pilihan',
        'bool' => 'Ya/Tidak',
    ],

    'attributes' => [
        'leave' => [
            'days' => 'Bilangan hari cuti',
            'unpaid_days' => 'Hari tanpa gaji',
            'leave_type_code' => 'Kod jenis cuti',
            'entitlement_basis' => 'Asas kelayakan',
            'pay_mode' => 'Mod bayaran',
            'is_backdated' => 'Permohonan ke belakang',
            'is_on_behalf' => 'Dimohon bagi pihak',
            'block_list_hit' => 'Bertembung tarikh sekatan',
        ],
        'compensatory_leave' => [
            'days' => 'Hari dituntut',
            'includes_public_holiday' => 'Termasuk cuti umum',
        ],
        'attendance_request' => [
            'kind' => 'Jenis permohonan',
            'days' => 'Bilangan hari',
            'is_backdated' => 'Permohonan ke belakang',
            'includes_rest_days' => 'Termasuk hari rehat',
        ],
        'shift_request' => [
            'days' => 'Bilangan hari',
            'shift_type_code' => 'Kod jenis shift',
            'is_overnight' => 'Shift melepasi tengah malam',
        ],
        'overtime_claim' => [
            'hours' => 'Jam OT',
            'amount' => 'Amaun OT',
            'needs_compliance_review' => 'Perlu semakan pematuhan',
            'eligibility' => 'Kelayakan OT',
        ],
        'test' => [
            'days' => 'Bilangan hari (ujian)',
        ],
        'applicant' => [
            'department_id' => 'Jabatan pemohon',
            'branch_id' => 'Cawangan pemohon',
            'grade_rank' => 'Kedudukan gred pemohon',
            'employment_type_category' => 'Kategori perkhidmatan pemohon',
            'citizenship_status' => 'Kewarganegaraan pemohon',
        ],
    ],

    'fields' => [
        'workflow_name' => 'Nama aliran',
        'step_name' => 'Nama langkah',
        'rule_type' => 'Jenis Peraturan',
        'mode' => 'Mod',
        'selection' => 'Pemilihan pelulus',
        'walk_ancestors' => 'Cari di jabatan induk jika tiada',
        'walk_up' => 'Naik ke jabatan induk jika jawatan kosong / pemohon sendiri',
        'level' => 'Tahap penyelia',
        'role' => 'Peranan',
        'user' => 'Pengguna',
        'on_no_approver' => 'Jika tiada pelulus',
        'remind_after_days' => 'Peringatan selepas (hari)',
        'skip_if_applicant' => 'Langkau jika pemohon satu-satunya pelulus',
        'skip_if_same_as_previous' => 'Langkau jika pelulus sama dengan langkah sebelumnya',
        'exclude_submitter' => 'Penghantar tidak boleh melulus',
        'condition' => 'Syarat (semua mesti dipenuhi)',
        'prevent_self_approval' => 'Pemohon tidak boleh melulus permohonan sendiri',
        'allow_override' => 'Benarkan HR mengatasi keputusan',
        'notify_applicant_each_step' => 'Maklumkan pemohon selepas setiap langkah',
    ],

    'defaults' => [
        'workflow_name' => 'Kelulusan Standard',
        'step_name' => 'Pelulus',
    ],

    'errors' => [
        'unknown_type' => 'Jenis permohonan ":type" tidak didaftarkan.',
        'no_active_workflow' => 'Tiada aliran kelulusan aktif untuk :type.',
        'no_approver_found' => 'Tiada pelulus untuk langkah :sequence (":step"). Permohonan tidak dapat dihantar.',
        'choice_not_allowed' => 'Pelulus yang dipilih tidak dibenarkan untuk langkah ":step".',
        'choice_required' => 'Sila pilih pelulus untuk langkah ":step".',
        'already_submitted' => 'Dokumen ini telah dihantar untuk kelulusan.',
        'override_not_allowed' => 'Anda tidak dibenarkan mengatasi kelulusan ini.',
        'reassign_empty' => 'Pilih sekurang-kurangnya seorang pelulus.',
        'reassign_not_member' => 'Pelulus mesti pengguna aktif Organisasi ini.',
        'self_approval' => 'Anda tidak boleh meluluskan permohonan anda sendiri.',
        'submitter_approval' => 'Penghantar permohonan tidak boleh meluluskannya.',
        'already_decided' => 'Langkah ini telah diputuskan.',
        'not_assignee' => 'Anda bukan pelulus bagi langkah semasa.',
        'not_pending' => 'Permohonan ini tidak lagi menunggu kelulusan.',
        'note_required' => 'Catatan wajib diisi untuk menolak.',
        'reason_required' => 'Sebab wajib diisi.',
        'unknown_attribute' => 'Syarat #:n: atribut ":attribute" tiada dalam katalog.',
        'unknown_operator' => 'Syarat #:n: operator ":operator" tidak sah.',
        'operator_not_numeric' => 'Operator perbandingan hanya untuk atribut nombor (:attribute).',
        'value_not_numeric' => 'Nilai untuk :attribute mesti nombor.',
        'value_not_allowed' => 'Nilai ":value" tidak dibenarkan untuk :attribute.',
        'step_count' => 'Aliran mesti mempunyai 1 hingga 10 langkah.',
        'invalid_rule' => 'Jenis peraturan tidak sah.',
        'invalid_mode' => 'Mod tidak sah.',
        'invalid_on_no_approver' => 'Pilihan "jika tiada pelulus" tidak sah.',
        'invalid_role' => 'Peranan tidak sah.',
        'invalid_user' => 'Pengguna mesti ahli aktif Organisasi ini.',
        'invalid_remind_days' => 'Hari peringatan mesti antara 1 hingga 60.',
        'self_approver' => 'Kakitangan tidak boleh menjadi pelulus untuk diri sendiri.',
        'bulk_one_type' => 'Kelulusan pukal hanya untuk satu jenis permohonan.',
    ],

    'mail' => [
        'greeting' => 'Salam sejahtera :name,',
        'open' => 'Buka Kelulusan Saya',
        'requested' => ['subject' => ':type menunggu kelulusan anda', 'line' => ':type daripada :applicant menunggu tindakan anda.'],
        'progressed' => ['subject' => ':type anda telah melepasi satu langkah', 'line' => ':type anda telah diluluskan pada satu langkah dan diteruskan ke langkah seterusnya.'],
        'approved' => ['subject' => ':type anda telah diluluskan', 'line' => ':type anda telah diluluskan sepenuhnya.'],
        'rejected' => ['subject' => ':type anda telah ditolak', 'line' => ':type anda telah ditolak. Sila semak catatan pelulus.'],
        'cancelled' => ['subject' => ':type telah dibatalkan', 'line' => ':type daripada :applicant telah dibatalkan. Tiada tindakan diperlukan.'],
        'reassigned' => ['subject' => ':type ditugaskan kepada anda', 'line' => 'HR telah menugaskan :type daripada :applicant kepada anda.'],
        'reminder' => ['subject' => 'Peringatan: :type menunggu kelulusan anda', 'line' => ':type daripada :applicant masih menunggu tindakan anda.'],
        'no_approver' => ['subject' => 'Tiada pelulus: :type', 'line' => 'Langkah ":step" bagi :type daripada :applicant tiada pelulus. Sila tugaskan pelulus.'],
    ],

    'flash' => [
        'approved' => 'Permohonan telah diluluskan.',
        'rejected' => 'Permohonan telah ditolak.',
        'overridden' => 'Keputusan HR telah direkodkan.',
        'reassigned' => 'Pelulus telah ditugaskan semula.',
        'withdrawn' => 'Permohonan telah ditarik balik.',
        'bulk_approved' => ':count permohonan diluluskan.',
        'published' => 'Aliran kelulusan versi :version telah diterbitkan.',
    ],

    'preview' => [
        'summary' => 'Pratonton laluan kelulusan',
        'candidates' => 'Calon',
        'outcomes' => [
            'pending' => 'akan ke pelulus',
            'skipped' => 'dilangkau',
            'no_approver_fail' => 'TIADA PELULUS — penghantaran akan gagal',
            'hr_queue' => 'ke barisan HR (tiada pelulus)',
            'choice_required' => 'pemohon perlu memilih pelulus',
        ],
    ],

    'context' => [
        'days' => 'Bilangan hari',
    ],

    'inbox' => [
        'description' => 'Permohonan yang menunggu tindakan anda dan sejarah keputusan.',
        'tabs' => [
            'mine' => 'Menunggu Tindakan Saya',
            'processed' => 'Telah Saya Proses',
            'submitted' => 'Permohonan Saya',
            'all_pending' => 'Semua Menunggu',
            'no_approver' => 'Tanpa Pelulus',
        ],
        'columns' => [
            'type' => 'Jenis',
            'applicant' => 'Pemohon',
            'summary' => 'Ringkasan',
            'step' => 'Langkah',
            'submitted_at' => 'Tarikh Dihantar',
            'waiting' => 'Tempoh Menunggu',
        ],
        'all_types' => 'Semua jenis',
        'applicant' => 'Nama / No. pemohon',
        'days' => ':days hari',
        'empty' => 'Tiada permohonan.',
        'bulk_approve' => 'Lulus :count dipilih',
        'decision_context' => 'Maklumat untuk keputusan',
        'timeline' => 'Laluan Kelulusan',
        'history' => 'Sejarah tindakan',
        'system' => 'Sistem',
        'no_assignees' => 'Tiada pelulus — menunggu tindakan HR.',
        'note' => 'Catatan (pilihan untuk lulus)',
        'approve' => 'Lulus',
        'reject' => 'Tolak',
        'override_hint' => 'Tindakan HR: mengatasi atau menugaskan semula langkah semasa (sebab wajib).',
        'override_approve' => 'Lulus bagi pihak pelulus',
        'override_reject' => 'Tolak bagi pihak pelulus',
        'reassign' => 'Tugaskan Semula',
        'withdraw' => 'Tarik Balik',
    ],

    'workflows' => [
        'description' => 'Aliran kelulusan berbilang peringkat bagi setiap jenis permohonan.',
        'editor_description' => 'Susun langkah kelulusan. Versi yang telah digunakan tidak diubah — menerbitkan perubahan mencipta versi baharu.',
        'module_pending' => 'Modul ini dibina dalam milestone kemudian; aliran boleh disediakan sekarang.',
        'has_condition' => 'Bersyarat',
        'published_at' => 'Diterbitkan :at',
        'none' => 'Tiada aliran aktif.',
        'current_version' => 'Versi semasa: v:version',
        'used_note' => 'telah digunakan — menyimpan akan mencipta versi baharu',
        'publish' => 'Terbitkan',
        'step_n' => 'Langkah :n',
        'step_name_hint' => 'cth. Ketua Jabatan',
        'add_condition' => 'Tambah syarat',
        'add_step' => 'Tambah langkah',
        'preview' => 'Pratonton',
        'preview_hint' => 'Pratonton laluan versi aktif untuk seorang kakitangan dan nilai contoh.',
        'pick_employee' => 'Pilih kakitangan',
        'run_preview' => 'Jalankan pratonton',
        'history' => 'Sejarah versi',
    ],

];

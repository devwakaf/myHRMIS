<?php

// Modul Statutori (spec-04 §2.11, §6, §7). KWSP, PERKESO, SIP, PCB, LHDN, HRD Corp kekal sebagai singkatan rasmi.
return [
    'title' => 'Statutori',

    'pages' => [
        'rates' => 'Kadar Statutori',
        'rate_set' => 'Set Kadar Statutori',
        'files' => 'Fail Statutori',
        'remittances' => 'Bayaran Statutori',
        'ea' => 'Borang EA',
        'listings' => 'Senarai Statutori',
    ],

    'messages' => [
        'copied' => 'Set kadar baharu (draf) dicipta.',
        'imported' => ':rows baris diimport.',
        'published' => 'Set kadar diterbitkan (belum disahkan).',
        'fixtures_failed' => 'Ujian fixture gagal: :failures',
        'verified' => 'Set kadar disahkan.',
        'unverified_banner' => 'Kadar ini belum disahkan dengan sumber rasmi.',
        'format_unverified' => 'Format belum disahkan.',
        'file_submitted' => 'Fail ditandakan telah dihantar.',
        'remittance_saved' => 'Bayaran direkodkan.',
    ],

    'errors' => [
        'band_incomplete' => 'Jadual :scheme tidak lengkap: lajur :column bagi jalur :from–:to kosong.',
        'no_rate_set' => 'Tiada kadar statutori berkuat kuasa untuk :scheme pada :date.',
        'epf_band_missing' => 'Jadual KWSP Bahagian :part tiada jalur untuk gaji RM :wage (set :code).',
        'socso_band_missing' => 'Jadual PERKESO tiada jalur untuk gaji RM :wage (set :code).',
        'eis_band_missing' => 'Jadual SIP tiada jalur untuk gaji RM :wage (set :code).',
        'mtd_band_missing' => 'Jadual PCB tiada julat untuk P = RM :p (set :code).',
        'params_missing' => 'Parameter set :code tiada.',
        'import_table' => 'Jadual :table tidak boleh diimport untuk skim ini.',
        'import_effective_from' => 'Tarikh berkuat kuasa diperlukan untuk set baharu.',
        'set_not_draft' => 'Set :code bukan draf.',
        'scheme_mismatch' => 'Set :code milik skim lain.',
        'import_empty' => 'Fail CSV kosong.',
        'import_columns' => 'Lajur wajib tiada: :columns.',
        'import_placeholder' => 'Baris :line lajur :column masih mengandungi pemegang tempat; salin nilai daripada jadual rasmi.',
        'import_blank' => 'Baris :line lajur :column kosong.',
        'verify_status' => 'Hanya set belum disahkan boleh disahkan.',
        'verifier_is_creator' => 'Pengesah mesti berbeza daripada pencipta set.',
        'placeholder_cannot_verify' => 'Set dengan jalur pemegang tempat tidak boleh disahkan.',
        'placeholder_production' => 'Jalur pemegang tempat tidak dibenarkan dalam production.',
        'placeholder_verified' => 'Set yang disahkan tidak boleh diubah.',
        'record_length' => 'Panjang rekod :actual, dijangka :expected.',
        'missing_id' => 'Kakitangan :employee: :field tiada.',
        'employee_no_too_long' => 'No. kakitangan :employee melebihi 10 aksara (CP39).',
        'no_file_format' => 'Tiada format fail :scheme berkuat kuasa.',
    ],

    'enums' => [
        'rate_scheme' => [
            'epf' => 'KWSP', 'socso' => 'PERKESO & SKBBK', 'eis' => 'SIP', 'mtd' => 'PCB/MTD', 'tax_reliefs' => 'Pelepasan & Pengecualian Cukai',
            'hrdc' => 'HRD Corp', 'minimum_wage' => 'Gaji Minimum',
        ],
        'rate_set_status' => ['draft' => 'Draf', 'unverified' => 'Belum Disahkan', 'verified' => 'Disahkan'],
        'epf_part' => ['A' => 'Bahagian A', 'C' => 'Bahagian C', 'E' => 'Bahagian E', 'F' => 'Bahagian F', 'none' => 'Tiada caruman'],
        'citizenship_group' => ['citizen' => 'Warganegara', 'pr_or_pre1998' => 'PR / bukan warganegara sebelum 1998', 'foreign' => 'Bukan warganegara'],
        'epf_percent_rounding' => ['ceil_each_share' => 'Bundar naik setiap bahagian', 'ceil_total_then_split' => 'Bundar naik jumlah, kemudian dibahagi'],
        'epf_bonus_base' => ['band_upper' => 'Had atas jalur', 'actual_wage' => 'Gaji sebenar'],
        'age_switch_rule' => ['month_after_birthday' => 'Bulan selepas bulan lahir', 'birthday_month' => 'Bulan lahir'],
        'socso_category' => ['1' => 'Kategori 1', '2' => 'Kategori 2', 'none' => 'Tiada'],
        'file_scheme' => ['kwsp' => 'KWSP', 'perkeso' => 'PERKESO', 'cp39' => 'CP39', 'ea' => 'Borang EA', 'cp8d' => 'CP8D', 'pcb2ii' => 'PCB 2(II)'],
        'format_status' => ['unverified' => 'Belum Disahkan', 'verified' => 'Disahkan'],
    ],

    'rates' => [
        'description' => 'Set kadar mengikut skim. Set belum disahkan ditandakan amber dan mengaktifkan amaran pada semua skrin payroll yang menggunakannya.',
        'placeholder_badge' => 'PEMEGANG TEMPAT', 'placeholder_message' => 'Set ini mengandungi jalur pemegang tempat untuk pembangunan sahaja dan tidak boleh disahkan atau digunakan untuk bayaran sebenar.',
        'unverified_title' => 'Kadar belum disahkan', 'unverified_message' => 'Set berikut belum disahkan dengan sumber rasmi: :codes.', 'banner_draft' => 'Set ini masih draf', 'banner_unverified' => 'Set ini belum disahkan',
        'formats' => 'Format Fail', 'effective' => 'Berkuat kuasa', 'effective_from' => 'Berkuat kuasa dari', 'open_ended' => 'terbuka', 'source' => 'Sumber', 'source_url' => 'URL sumber', 'version' => 'Versi',
        'copy' => 'Salin Set Baharu', 'import' => 'Import CSV', 'import_hint' => 'Hanya set draf. Sel pemegang tempat ditolak.', 'import_table' => 'Jadual', 'import_table_default' => 'Jadual utama skim', 'import_file' => 'Fail CSV',
        'run_fixtures' => 'Jalankan Ujian Fixture', 'fixtures_ok' => 'Semua ujian fixture lulus.', 'fixtures_failed' => 'Ujian fixture gagal:', 'publish' => 'Terbitkan', 'verify' => 'Sahkan',
        'verify_confirm' => 'Sahkan set :code? Pengesah mesti berbeza daripada pencipta dan jadual mesti disemak dengan sumber rasmi.',
        'two_person' => 'Peraturan dua orang: pengesah mesti berbeza daripada pencipta set.', 'delete_confirm' => 'Padam set draf :code?', 'fixture_passed' => 'Ujian fixture lulus', 'verified_at' => 'Disahkan pada',
        'truncated' => 'Memaparkan :limit daripada :total baris.', 'csv_columns' => 'Lajur CSV',
    ],

    'ea' => [
        'description' => 'Borang EA tahunan: jana, terbitkan (Pelulus Payroll) dan muat turun PDF. PCB 2(II) setiap Kakitangan boleh dimuat turun di sini.', 'generate' => 'Jana Borang EA :year',
        'layout_notice' => 'Susun atur dan kod kotak Borang EA 2026 belum disahkan dengan borang rasmi.', 'year' => 'Tahun', 'serial' => 'No. siri', 'publish' => 'Terbitkan', 'empty' => 'Belum ada Borang EA bagi tahun ini.',
    ],

    'files' => [
        'description' => 'Fail muat naik PERKESO, CP39 dan KWSP bagi satu bulan, daripada slip yang dihantar. Rekod dengan data tidak lengkap menghalang fail dijana.',
        'kwsp_notice_title' => 'Format fail KWSP belum disahkan', 'kwsp_notice' => 'Format fail KWSP belum disahkan dengan spesifikasi rasmi; semak fail sebelum memuat naik.',
        'kinds' => ['perkeso' => 'Fail PERKESO (278 aksara)', 'cp39' => 'CP39 (LHDN)', 'kwsp' => 'Fail KWSP'],
        'kind_hint' => ['perkeso' => 'Format gabungan ASSIST 2.0: PERKESO, SKBBK dan SIP.', 'cp39' => 'Potongan PCB dan CP38 bulanan.', 'kwsp' => 'Caruman KWSP bulan berikutnya.'],
        'generate' => 'Jana Fail', 'generated' => 'Dijana', 'submitted' => 'Dihantar', 'mark_submitted' => 'Tandakan Dihantar', 'external_ref' => 'Rujukan penghantaran',
        'listings' => 'Senarai', 'recorded_payments' => 'Bayaran direkodkan', 'no_payments' => 'Belum ada bayaran direkodkan bagi bulan ini.',
    ],

    'listings' => [
        'names' => ['kwsp' => 'Senarai Caruman KWSP', 'perkeso' => 'Senarai Caruman PERKESO', 'sip' => 'Senarai Caruman SIP', 'pcb' => 'Senarai PCB & Zakat', 'hrdc' => 'Senarai Levi HRD Corp'],
        'rows' => ':count baris', 'malaysian' => ':count warganegara/PR',
        'columns' => [
            'employee_no' => 'No. Kakitangan', 'full_name' => 'Nama', 'member_id' => 'No. ahli', 'part' => 'Bahagian', 'wage' => 'Gaji', 'employer' => 'Majikan', 'employee' => 'Pekerja', 'total' => 'Jumlah',
            'category' => 'Kategori', 'skbbk' => 'SKBBK', 'tin' => 'No. Cukai', 'pcb' => 'PCB', 'cp38' => 'CP38', 'zakat' => 'Zakat', 'citizenship' => 'Kewarganegaraan', 'rate' => 'Kadar', 'levy' => 'Levi',
        ],
    ],

    'remittances' => [
        'description' => 'Rekod bayaran kepada KWSP, PERKESO, LHDN, HRD Corp dan pihak berkuasa zakat; digunakan untuk peringatan dan PCB 2(II).', 'new' => 'Rekod Bayaran', 'scheme' => 'Skim',
        'paid_on' => 'Tarikh bayar', 'receipt_no' => 'No. resit',
    ],
];

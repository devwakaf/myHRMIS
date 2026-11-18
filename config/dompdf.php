<?php

/*
| dompdf (barryvdh/laravel-dompdf) for Slip Gaji, Borang EA, PCB 2(II) and the statutory
| listings. Nothing is fetched over the network: remote access is off, the chroot is the
| application's resources and storage directories, and the only font used is "DejaVu Sans",
| which ships inside the dompdf package (vendor/dompdf/dompdf/lib/fonts) — so rendering
| behaves the same on Windows and on Linux without any system font. The writable font
| cache lives in storage/fonts.
*/

return [
    'show_warnings' => false,
    'public_path' => null,
    'convert_entities' => true,

    'options' => [
        'font_dir' => storage_path('fonts'),
        'font_cache' => storage_path('fonts'),
        'temp_dir' => sys_get_temp_dir(),
        'chroot' => [realpath(resource_path()) ?: resource_path(), realpath(storage_path('app')) ?: storage_path('app')],
        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
        ],
        'artifactPathValidation' => null,
        'log_output_file' => null,
        'enable_font_subsetting' => true,
        'pdf_backend' => 'CPDF',
        'default_media_type' => 'print',
        'default_paper_size' => 'a4',
        'default_paper_orientation' => 'portrait',
        'default_font' => 'DejaVu Sans',
        'dpi' => 96,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => false,
        'allowed_remote_hosts' => [],
        'font_height_ratio' => 1.1,
        'enable_html5_parser' => true,
    ],
];

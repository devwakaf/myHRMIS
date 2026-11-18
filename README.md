# MyHRMIS

Sistem Pengurusan Sumber Manusia (HRMIS) berbilang Organisasi — Laravel 12 + Inertia/Vue 3,
Bahasa Melayu sebagai bahasa lalai (toggle English). Milestone semasa: **M0 (asas) + M1 (Teras Kakitangan)**.
Spesifikasi: `investigations/frappe-hrms/spec-00-architecture.md` (repo makmal).

## Keperluan

- PHP 8.4 dengan `bcmath`, `intl`, `mbstring`, `pdo_mysql`, `gd`, `zip`, `fileinfo`, `openssl`
- MySQL 8.4 (utf8mb4), Composer 2, Node 22+/npm

## Persediaan (Windows / Laragon)

```bash
cp .env.example .env              # DB_DATABASE=myhrmis, APP_LOCALE=ms, APP_TIMEZONE=Asia/Kuala_Lumpur
composer install
php artisan key:generate
# cipta pangkalan data: myhrmis dan myhrmis_test (utf8mb4)
php artisan migrate --seed        # data rujukan + data demo
npm install
npm run build                     # jika `php` tiada dalam PATH: set PHP_BINARY=C:/laragon/bin/php/.../php.exe
```

Laragon menghidangkan `public/` sebagai `http://myhrmis.test` (Reload Laragon). Atau `php artisan serve`.

Proses latar (dev): `php artisan queue:work` dan `php artisan schedule:work`.

## Log masuk demo (pembangunan sahaja)

Kata laluan semua akaun demo: **`Demo@2026`** (lihat `database/seeders/DemoSeeder.php`).

| Emel | Peranan |
| --- | --- |
| admin@myhrmis.test | Super Admin (semua Organisasi) |
| hr.manager@myhrmis.test | Pengurus HR — Syarikat Demo Teknologi (Pahang) |
| hr.exec@myhrmis.test | Eksekutif HR — Pahang |
| payroll@myhrmis.test | Pegawai Payroll — Pahang |
| ketua@myhrmis.test | Kakitangan, Ketua Jabatan IT / Pelulus Jabatan |
| lead.dev@myhrmis.test | Kakitangan, Pelulus Jabatan Pembangunan Aplikasi |
| gm@myhrmis.test | Kakitangan, Pelulus Jabatan akar |
| staff@myhrmis.test | Kakitangan |
| hr.selangor@myhrmis.test | Pengurus HR — Demo Logistik Selangor |

Tukar atau buang akaun demo sebelum production. `DemoSeeder` tidak dijalankan apabila `APP_ENV=production`.

## Semakan kualiti

```bash
composer check        # Pint --test + Larastan (tahap 6) + Pest
php artisan test      # ujian sahaja (MySQL: myhrmis_test)
npm run build
```

## Struktur

- `app/Domain/Shared` — OrganisationContext & skop, kitaran hayat dokumen, Money/Rounding, tarikh berkuat kuasa,
  penomboran, audit, kebenaran (`PermissionMatrix`), **enjin kelulusan berbilang peringkat** (`Approval/`).
- `app/Domain/Core` — Teras Kakitangan: Organisasi, Cawangan, Jabatan, Jawatan, Gred, Jenis Perkhidmatan, Kakitangan,
  dokumen, Senarai Cuti Umum + `HolidayResolver`, pergerakan, laporan, kerja berjadual.
- `lang/ms`, `lang/en` — fail terjemahan PHP per modul (dimuat ke Vue oleh laravel-vue-i18n).
- `resources/js/pages` — skrin Inertia (nama fail sensitif huruf; diuji).

## Production (Linux)

Nginx → `public/`, PHP-FPM 8.4, MySQL 8.4 (`timezone +08:00`), supervisor untuk
`php artisan queue:work database --sleep=3 --tries=3 --max-time=3600`, cron
`* * * * * cd /path && php artisan schedule:run`. Deploy: `composer install --no-dev -o`,
`npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`.
Fail dokumen kakitangan disimpan dalam cakera persendirian (`storage/app/private`).

Tarikh cuti umum 2026 dalam `ReferenceDataSeeder` ialah data DEMO (belum disahkan) — sahkan dengan warta rasmi.

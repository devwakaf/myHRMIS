# Prompt sambungan untuk Codex (MyHRMIS)

> Salin blok di bawah ke Codex (jalankan Codex dalam folder `C:\laragon\www\MyHRMIS`).
> Bahagian "STATUS SEMASA" dikemas kini oleh Claude setiap kali milestone siap.

## STATUS SEMASA
- M0 (asas, auth, i18n, peranan, enjin kelulusan) — SIAP, ujian lulus.
- M1 (Teras Kakitangan) — SIAP, 134 ujian lulus.
- M2 (Cuti) back-end — SIAP (218 ujian lulus). M2 UI — SIAP (lihat "Build notes — M2 (UI)" dalam spec-02; baki kecil: picker pelulus pada borang Cuti Gantian, padam LeavePlaceholder.vue yang tidak digunakan).
- PENTING: jika dua proses ujian berjalan serentak, guna DB ujian berasingan (cth. DB_DATABASE=myhrmis_test_ui php artisan test) untuk elak deadlock.
- M3 (Kehadiran & Shift) back-end — SIAP (composer check hijau, 348 ujian). M3 UI — SIAP (375 ujian; baki: manifest PWA check-in, pratonton laluan Permohonan Shift, DemoAttendanceSeeder belum dijalankan pada DB dev).
- M4 (Payroll & Statutori) back-end — SIAP (composer check hijau, 468 ujian). M4 UI — SIAP (492 ujian; Pint, Larastan 0 ralat, vue-tsc 0 ralat, build OK). Baki M4: import TP3 dari rekod sebelumnya, import CSV Elaun/Potongan, kotak baki cuti pada slip, laporan rekonsiliasi bayaran, CSV CP8D/e-Data Praisi, semakan visual PDF, dan 6 ralat ESLint import-order lama dalam fail starter-kit.
- SEMUA MILESTONE M0–M4 SIAP. Langkah seterusnya (bukan Fasa 1 atau belum selesai): (1) uji penuh `composer check` sebagai satu arahan (ejen tidak dapat jalankan sekali gus — MySQL perlahan di latar belakang); (2) uji di Linux; (3) sahkan kadar statutori + ujian [V-PDF]/[V-LHDN]; (4) semak 16 fail Attendance FormRequests; (5) pasang PWA manifest check-in; (6) jawab soalan terbuka dalam §9/§13 setiap spec; (7) fasa 2: Recruitment, Onboarding/Separation, Performance, Training, Expense Claim, dll.
- SEBELUM PRODUCTION: semak 16 fail app/Domain/Attendance/Http/Requests (digeneratkan semula secara tidak sengaja oleh ejen M4; ujian hijau tetapi sahkan tiada suntingan manual hilang); sahkan jadual kadar statutori (spec-04 §9.2); jalankan pada Linux; kadar placeholder (statutory:placeholder) hanya untuk dev.

---

```
Anda menyambung pembangunan MyHRMIS, sistem HR Laravel 12 + Inertia + Vue 3 (TypeScript, shadcn-vue), Bahasa Melayu default dengan toggle English. Projek: C:\laragon\www\MyHRMIS (Windows/Laragon untuk development, Linux untuk production).

PERSEKITARAN
- PHP 8.4: C:\laragon\bin\php\php-8.4.14-nts-Win32-vs17-x64\php.exe (tiada dalam PATH; panggil dengan path penuh).
- Composer: php C:\laragon\bin\composer\composer.phar ...
- MySQL 8.4 127.0.0.1:3306 root (tiada password). DB: myhrmis, ujian: myhrmis_test.
- Semakan penuh: composer check (Pint + Larastan level 6 + Pest). Frontend: npm run build (set PHP_BINARY ke php.exe Laragon jika perlu).

BACA DAHULU (spec ialah sumber kebenaran; ikut konvensyennya, jangan reka semula):
1. C:\laragon\www\Reverse Engineering Lab\investigations\frappe-hrms\README.md — indeks + keputusan silang modul.
2. ...\00-recon.md — keputusan pengguna (autoritatif). ...\glossary-bm.md — istilah UI.
3. ...\spec-00-architecture.md — konvensyen (Part A), enjin kelulusan §A6, peranan §A7, milestone §A11, dan "Build notes" di hujung (apa yang sudah dibina + penyimpangan).
4. Spec modul yang sedang dibina: spec-02-leave.md (M2), spec-03-attendance.md (M3), spec-04-payroll.md (M4, termasuk tambahan antara muka §4.2.1 untuk spec-02/03).
5. Kod sedia ada: app/Domain/*, routes/, resources/js/Pages/*, lang/ms + lang/en, tests/.

PERATURAN
- Sambung milestone pertama yang belum SIAP dalam docs/CODEX-HANDOFF.md. Semak kod sedia ada dahulu; jangan ulang kerja yang sudah siap.
- Semua teks UI melalui lang/ms/*.php dan lang/en/*.php (kunci mesti sepadan; ada ujian parity).
- Skop semua data ikut organisation_id (scope fail-closed sedia ada). Guna enjin kelulusan A6 (Approvable + ApprovalService + hooks) — jangan buat logik pelulus sendiri.
- Wang: BcMath\Number + servis Rounding sedia ada. Tarikh efektif: helper sedia ada.
- Kod asli sahaja. JANGAN baca atau salin apa-apa dari investigations\frappe-hrms\source\ (GPL).
- Nilai statutori bertanda UNVERIFIED mesti data konfigurasi (seed), bukan dihardcode.
- Tulis ujian Pest untuk setiap kes ujian dalam spec modul; composer check dan npm run build mesti lulus sebelum milestone dianggap siap.
- Jangan commit melainkan diminta. Jangan tulis kata laluan/token ke dalam investigations/.
- Selepas setiap milestone: tambah "Build notes — Mx" di hujung spec modul berkenaan (apa dibina, penyimpangan + sebab, keputusan ujian sebenar, jurang), kemas kini STATUS SEMASA dalam docs/CODEX-HANDOFF.md, dan berhenti untuk semakan pengguna.
- Jika spec bercanggah atau keputusan model data besar diperlukan, tanya pengguna dahulu.
```

<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\PayrollFileKind;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Every generated export (bank, statutory, CSV) on the private disk (spec-04 §2.10).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int|null $payroll_run_id
 * @property CarbonImmutable $period
 * @property PayrollFileKind $kind
 * @property int|null $organisation_bank_account_id
 * @property string|null $format_code
 * @property string|null $format_version
 * @property string $file_name
 * @property string $file_path
 * @property string $sha256
 * @property int $record_count
 * @property string $total_amount
 * @property string $status
 * @property bool $format_unverified
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $generated_by
 * @property CarbonImmutable|null $generated_at
 */
class PayrollFile extends Model
{
    use BelongsToOrganisation;

    protected $guarded = ['id', 'organisation_id'];

    protected function casts(): array
    {
        return [
            'period' => 'immutable_date',
            'kind' => PayrollFileKind::class,
            'record_count' => 'integer',
            'total_amount' => 'decimal:2',
            'format_unverified' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
        ];
    }
}

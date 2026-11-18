<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Payroll\Enums\RemittanceScheme;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Recorded payment to KWSP / PERKESO / LHDN / HRD Corp / zakat authority (spec-04 §2.10).
 *
 * @property int $id
 * @property int $organisation_id
 * @property RemittanceScheme $scheme
 * @property CarbonImmutable $period
 * @property string $amount
 * @property CarbonImmutable $paid_on
 * @property string|null $receipt_no
 * @property int|null $payroll_file_id
 * @property string|null $notes
 */
class StatutoryRemittance extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'scheme' => RemittanceScheme::class,
            'period' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'amount' => 'decimal:2',
        ];
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Employee;
use App\Domain\Payroll\Enums\DeclarationType;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Lifecycle\DocumentStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Numbering\HasDocumentNumber;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Deklarasi Cukai (spec-04 §2.8): TP1 reliefs, TP3 previous employer YTD, zakat
 * paid outside payroll. HR-entered in Phase 1.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $number
 * @property int $employee_id
 * @property DeclarationType $type
 * @property int $tax_year
 * @property CarbonImmutable $period
 * @property bool $is_correction
 * @property int|null $consumed_by_slip_id
 * @property string|null $prev_employer_name
 * @property string|null $prev_employer_e_no
 * @property CarbonImmutable|null $prev_from
 * @property CarbonImmutable|null $prev_to
 * @property string $prev_gross_normal
 * @property string $prev_gross_additional
 * @property string $prev_epf
 * @property string $prev_pcb
 * @property string $prev_zakat
 * @property string $prev_cp38
 * @property string $prev_lp
 * @property string $zakat_amount
 * @property string|null $zakat_authority
 * @property string|null $receipt_no
 * @property CarbonImmutable|null $receipt_date
 * @property string|null $remarks
 * @property DocumentStatus $status
 * @property-read Employee $employee
 * @property-read Collection<int, TaxDeclarationLine> $lines
 */
class TaxDeclaration extends Model
{
    use BelongsToOrganisation, HasDocumentNumber, HasLifecycle, LogsBusinessActivity, TracksAuthor;

    public const AMOUNT_COLUMNS = ['prev_gross_normal', 'prev_gross_additional', 'prev_epf', 'prev_pcb', 'prev_zakat', 'prev_cp38', 'prev_lp', 'zakat_amount'];

    protected $guarded = ['id', 'organisation_id', 'number', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected $attributes = ['status' => 'draft', 'is_correction' => false];

    protected function casts(): array
    {
        $casts = [
            'type' => DeclarationType::class,
            'tax_year' => 'integer',
            'period' => 'immutable_date',
            'is_correction' => 'boolean',
            'prev_from' => 'immutable_date',
            'prev_to' => 'immutable_date',
            'receipt_date' => 'immutable_date',
            'status' => DocumentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
        foreach (self::AMOUNT_COLUMNS as $column) {
            $casts[$column] = 'decimal:2';
        }

        return $casts;
    }

    public static function documentNumberPrefix(): string
    {
        return 'DKL';
    }

    public static function activityLogName(): string
    {
        return 'payroll';
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }

    /**
     * @return list<string>
     */
    public function lifecycleMutableAttributes(): array
    {
        return ['consumed_by_slip_id'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<TaxDeclarationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TaxDeclarationLine::class);
    }
}

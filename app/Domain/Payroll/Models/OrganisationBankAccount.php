<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Core\Models\Bank;
use App\Domain\Shared\Concerns\LogsBusinessActivity;
use App\Domain\Shared\Concerns\TracksAuthor;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akaun Bank Organisasi — paying account (spec-04 §2.10).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $bank_id
 * @property string $account_no
 * @property string $account_name
 * @property string|null $corporate_id
 * @property int|null $bank_file_format_id
 * @property bool $is_default
 * @property bool $is_active
 * @property-read Bank $bank
 * @property-read BankFileFormat|null $format
 */
class OrganisationBankAccount extends Model
{
    use BelongsToOrganisation, LogsBusinessActivity, TracksAuthor;

    protected $guarded = ['id', 'organisation_id', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function activityLogModule(): string
    {
        return 'payroll';
    }

    /**
     * @return list<string>
     */
    public function sensitiveAttributes(): array
    {
        return ['account_no', 'corporate_id'];
    }

    /**
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /**
     * @return BelongsTo<BankFileFormat, $this>
     */
    public function format(): BelongsTo
    {
        return $this->belongsTo(BankFileFormat::class, 'bank_file_format_id');
    }
}

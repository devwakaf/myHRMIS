<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TP1 relief line (code from tp1_relief_codes of the YA).
 *
 * @property int $id
 * @property int $tax_declaration_id
 * @property string $relief_code
 * @property string $amount
 * @property string|null $reason
 */
class TaxDeclarationLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}

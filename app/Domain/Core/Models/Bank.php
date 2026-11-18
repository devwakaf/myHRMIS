<?php

declare(strict_types=1);

namespace App\Domain\Core\Models;

use Database\Factories\Core\BankFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Global bank reference data (B1.7 Bank tab).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $swift_code
 * @property string|null $bank_file_code
 * @property bool $is_active
 */
class Bank extends Model
{
    /** @use HasFactory<BankFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'swift_code', 'bank_file_code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

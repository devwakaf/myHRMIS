<?php

declare(strict_types=1);

namespace App\Domain\Leave\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $leave_block_list_id
 * @property CarbonImmutable $date
 * @property string|null $reason
 */
class LeaveBlockListDate extends Model
{
    public $timestamps = false;

    protected $fillable = ['leave_block_list_id', 'date', 'reason'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date'];
    }
}

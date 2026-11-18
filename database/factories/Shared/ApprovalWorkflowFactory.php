<?php

declare(strict_types=1);

namespace Database\Factories\Shared;

use App\Domain\Shared\Approval\Models\ApprovalWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalWorkflow>
 */
class ApprovalWorkflowFactory extends Factory
{
    protected $model = ApprovalWorkflow::class;

    public function definition(): array
    {
        return [
            'approval_type' => 'leave',
            'name' => 'Aliran Ujian',
            'version' => 1,
            'is_active' => true,
            'prevent_self_approval' => true,
            'allow_override' => true,
            'notify_applicant_each_step' => false,
            'published_at' => now(),
        ];
    }
}

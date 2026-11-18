<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Core\Models\Employee;
use App\Domain\Shared\Approval\ApprovalCheck;
use App\Domain\Shared\Approval\ApprovalTypeDefinition;
use App\Domain\Shared\Approval\ApprovalTypeRegistry;
use App\Domain\Shared\Approval\Concerns\HasApprovalRequest;
use App\Domain\Shared\Approval\Contracts\Approvable;
use App\Domain\Shared\Approval\Enums\AttributeType;
use App\Domain\Shared\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Lifecycle\ApprovalStatus;
use App\Domain\Shared\Lifecycle\HasLifecycle;
use App\Domain\Shared\Organisation\BelongsToOrganisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Test-only Approvable registered as type "test".
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $employee_id
 * @property string $days
 * @property ApprovalStatus $status
 */
class FakeApprovable extends Model implements Approvable
{
    use BelongsToOrganisation, HasApprovalRequest, HasLifecycle;

    /** @var array<string, int> hook call counters */
    public static array $calls = [];

    public static ?ApprovalCheck $completionCheck = null;

    public static bool $throwOnCompleted = false;

    /** @var list<array{0: string, 1: bool|null}> */
    public static array $log = [];

    protected $table = 'fake_approvables';

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['status' => ApprovalStatus::class];
    }

    public static function register(): void
    {
        app(ApprovalTypeRegistry::class)->register(new ApprovalTypeDefinition(
            key: 'test',
            modelClass: self::class,
            labelKey: 'approval.types.test',
            approveAnyPermission: 'leave.application.approve-any',
            viewAllPermission: 'leave.application.view-all',
            attributes: ['test.days' => AttributeType::Number],
        ));
        self::reset();
    }

    public static function reset(): void
    {
        self::$calls = ['check' => 0, 'completed' => 0, 'rejected' => 0, 'cancelled' => 0];
        self::$completionCheck = null;
        self::$throwOnCompleted = false;
        self::$log = [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function lockBypassFields(): array
    {
        return ['holiday_list_changed' => ['days']];
    }

    public function approvalType(): string
    {
        return 'test';
    }

    public function approvalApplicant(): Employee
    {
        return Employee::acrossOrganisations()->findOrFail($this->employee_id);
    }

    public function approvalAttributes(): array
    {
        return ['test.days' => (string) $this->days];
    }

    public function approvalSummary(): array
    {
        return ['key' => 'approval.context.days', 'params' => []];
    }

    public function decisionContext(User $viewer): array
    {
        return ['days' => (string) $this->days];
    }

    public function checkApprovalCompletion(ApprovalRequest $request): ApprovalCheck
    {
        self::$calls['check']++;

        return self::$completionCheck ?? ApprovalCheck::ok();
    }

    public function onApprovalCompleted(ApprovalRequest $request): void
    {
        if (self::$throwOnCompleted) {
            throw new RuntimeException('hook failed');
        }
        self::$calls['completed']++;
        self::$log[] = ['completed', DB::transactionLevel() > 0];
    }

    public function onApprovalRejected(ApprovalRequest $request): void
    {
        self::$calls['rejected']++;
    }

    public function onApprovalCancelled(ApprovalRequest $request, bool $wasApproved): void
    {
        self::$calls['cancelled']++;
        self::$log[] = ['cancelled', $wasApproved];
    }
}

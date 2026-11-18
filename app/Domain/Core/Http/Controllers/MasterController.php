<?php

declare(strict_types=1);

namespace App\Domain\Core\Http\Controllers;

use App\Domain\Core\Actions\SaveMaster;
use App\Domain\Core\Enums\EmploymentCategory;
use App\Domain\Core\Models\Branch;
use App\Domain\Core\Models\Designation;
use App\Domain\Core\Models\DocumentType;
use App\Domain\Core\Models\EmploymentType;
use App\Domain\Core\Models\Grade;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Organisation\OrganisationContext;
use App\Domain\Shared\Support\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Simple masters with list + modal form (B4): Cawangan, Jawatan, Gred,
 * Jenis Perkhidmatan, Jenis Dokumen. One controller, one Vue page.
 */
final class MasterController extends Controller
{
    public const KINDS = ['branches', 'designations', 'grades', 'employment-types', 'document-types'];

    /** @var array<string, array{0: class-string<Model>, 1: string|null}> kind => [model, employee FK] */
    private const MODELS = [
        'branches' => [Branch::class, 'branch_id'],
        'designations' => [Designation::class, 'designation_id'],
        'grades' => [Grade::class, 'grade_id'],
        'employment-types' => [EmploymentType::class, 'employment_type_id'],
        'document-types' => [DocumentType::class, null],
    ];

    public function index(Request $request, string $master): Response
    {
        $this->requirePermission('core.masters.manage', 'core.masters.view');
        [$class] = self::MODELS[$master];
        $search = trim((string) $request->query('search', ''));

        $rows = $class::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$search%")->orWhere('code', 'like', "%$search%")))
            ->orderByDesc('is_active')
            ->orderBy($master === 'grades' ? 'rank' : 'name')
            ->get()
            ->map(fn (Model $m) => $this->row($master, $m))
            ->values();

        return Inertia::render('Core/Masters/Index', [
            'kind' => $master,
            'rows' => $rows,
            'filters' => ['search' => $search],
            'options' => [
                'states' => MalaysianState::options(),
                'categories' => EmploymentCategory::options(),
            ],
            'canManage' => $request->user()->can('core.masters.manage'),
        ]);
    }

    public function store(Request $request, string $master, SaveMaster $save): RedirectResponse
    {
        $this->requirePermission('core.masters.manage');
        [$class, $fk] = self::MODELS[$master];
        $save->handle($class, null, $this->validated($request, $master, null), $fk);

        return $this->done('common.saved');
    }

    public function update(Request $request, string $master, int $id, SaveMaster $save): RedirectResponse
    {
        $this->requirePermission('core.masters.manage');
        [$class, $fk] = self::MODELS[$master];
        $model = $class::query()->findOrFail($id);
        $save->handle($class, $model, $this->validated($request, $master, $model), $fk);

        return $this->done('common.saved');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $kind, Model $m): array
    {
        $data = $m->only(array_merge(['id'], $m->getFillable()));
        unset($data['organisation_id']);
        $data['display_name'] = method_exists($m, 'displayName') ? $m->displayName() : $m->getAttribute('name');

        foreach ($data as $key => $value) {
            if ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
                $data[$key.'_label'] = method_exists($value, 'label') ? $value->label() : $value->value;
            } elseif ($value instanceof Money) {
                $data[$key] = $value->toDecimalString();
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $kind, ?Model $model): array
    {
        $table = $model?->getTable() ?? (new (self::MODELS[$kind][0]))->getTable();
        $unique = fn (string $column) => Rule::unique($table, $column)
            ->where('organisation_id', OrganisationContext::id())
            ->ignore($model?->getKey());

        $common = [
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];

        $rules = match ($kind) {
            'branches' => [
                'code' => ['required', 'string', 'max:20', $unique('code')],
                'name' => ['required', 'string', 'max:255', $unique('name')],
                'address_line1' => ['nullable', 'string', 'max:255'],
                'address_line2' => ['nullable', 'string', 'max:255'],
                'postcode' => ['nullable', 'regex:/^\d{5}$/'],
                'city' => ['nullable', 'string', 'max:255'],
                'state' => ['required', Rule::enum(MalaysianState::class)],
                'phone' => ['nullable', 'string', 'max:30'],
            ],
            'designations' => [
                'code' => ['nullable', 'string', 'max:20'],
                'name' => ['required', 'string', 'max:255', $unique('name')],
                'description' => ['nullable', 'string', 'max:2000'],
            ],
            'grades' => [
                'code' => ['required', 'string', 'max:20', $unique('code')],
                'rank' => ['required', 'integer', 'min:0', 'max:999'],
                'salary_min' => ['nullable', 'numeric', 'min:0'],
                'salary_max' => ['nullable', 'numeric', 'min:0', 'gte:salary_min'],
            ],
            'employment-types' => [
                'code' => ['required', 'string', 'max:20', $unique('code')],
                'category' => ['required', Rule::enum(EmploymentCategory::class)],
                'requires_end_date' => ['boolean'],
            ],
            default => [ // document-types
                'code' => ['required', 'string', 'max:30', $unique('code')],
                'tracks_expiry' => ['boolean'],
            ],
        };

        $data = $request->validate(array_merge($common, $rules));
        foreach (['salary_min', 'salary_max'] as $money) {
            if (array_key_exists($money, $data) && $data[$money] !== null) {
                $data[$money] = (string) $data[$money];
            }
        }

        return $data;
    }
}

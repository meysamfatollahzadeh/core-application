<?php

namespace App\Modules\Personnel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Services\EffectivePermissions;
use App\Modules\Login\Models\User;
use App\Modules\Organization\Http\Requests\OptionSelection;
use App\Modules\Organization\Models\Company;
use App\Modules\Organization\Models\Position;
use App\Modules\Organization\Services\PositionPaths;
use App\Modules\Personnel\Actions\ManagePersonnel;
use App\Modules\Personnel\Http\Requests\AssignmentRequest;
use App\Modules\Personnel\Http\Requests\PersonnelRequest;
use App\Modules\Personnel\Models\UserPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PersonnelController extends Controller
{
    public function __construct(private EffectivePermissions $access, private ManagePersonnel $change) {}

    private function allow(Request $r, string $key, ?int $company = null): void
    {
        $this->access->authorize($r->user()->id, $key, $company);
    }

    private function query(Request $r)
    {
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $q = User::query()->join('building_areas', 'building_areas.id', '=', 'users.building_area_id')->join('buildings', 'buildings.id', '=', 'building_areas.building_id')->select('users.id', 'users.name', 'users.building_area_id')->selectRaw("buildings.title || ' / ' || building_areas.title as location_title");
        if (! empty($d['search'])) {
            $q->where('users.name', 'ilike', '%'.$d['search'].'%');
        }

        return $q;
    }

    public function index(Request $r)
    {
        $this->allow($r, 'personnel.view');

        return response()->json($this->query($r)->orderBy('users.id')->paginate($r->integer('per_page', 25)));
    }

    public function show(Request $r, User $user)
    {
        $this->allow($r, 'personnel.view');

        return response()->json(['data' => $user->only(['id', 'name', 'building_area_id'])]);
    }

    public function update(PersonnelRequest $r, User $user)
    {
        $this->allow($r, 'personnel.update');

        return response()->json(['data' => $this->change->user($user, $r->validated())->only(['id', 'name', 'building_area_id'])]);
    }

    public function companyPeople(Request $r, Company $company)
    {
        $this->allow($r, 'personnel.view', $company->id);
        $q = $this->query($r)->where(function ($q) use ($company) {
            $q->whereExists(fn ($q) => $q->selectRaw('1')->from('user_positions')->join('positions', 'positions.id', '=', 'user_positions.position_id')->whereColumn('user_positions.user_id', 'users.id')->where('positions.company_id', $company->id))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('company_user_role')->whereColumn('company_user_role.user_id', 'users.id')->where('company_id', $company->id));
        });

        return response()->json($q->orderBy('users.id')->paginate($r->integer('per_page', 25)));
    }

    private function assignmentsQuery()
    {
        return DB::table('user_positions')->join('positions', 'positions.id', '=', 'user_positions.position_id')->join('users', 'users.id', '=', 'user_positions.user_id')->select('user_positions.id', 'user_id', 'position_id', 'users.name as user_name', 'positions.title as position_title', 'positions.company_id');
    }

    private function assignmentData($assignment): array
    {
        return $this->assignmentRows([$assignment])->first();
    }

    private function assignmentRows(iterable $assignments): Collection
    {
        $rows = collect($assignments);
        $reports = DB::table('user_position_reports')->join('positions', 'positions.id', '=', 'report_to_position_id')->join('companies', 'companies.id', '=', 'positions.company_id')
            ->whereIn('user_position_id', $rows->pluck('id'))->orderBy('report_to_position_id')
            ->select('user_position_id', 'report_to_position_id')->selectRaw("companies.title || ' / ' || positions.title as title")->get();
        $paths = app(PositionPaths::class)->labels($rows->pluck('position_id')->merge($reports->pluck('report_to_position_id'))->all());
        $byAssignment = $reports->groupBy('user_position_id');

        return $rows->map(function ($assignment) use ($paths, $byAssignment): array {
            $targets = $byAssignment->get($assignment->id, collect());

            return [...(array) $assignment,
                'position_path' => $paths[$assignment->position_id],
                'report_position_ids' => $targets->pluck('report_to_position_id')->all(),
                'report_titles' => $targets->pluck('title')->all(),
                'report_paths' => $targets->map(fn ($target) => $paths[$target->report_to_position_id])->all(),
            ];
        });
    }

    public function assignments(Request $r, Company $company)
    {
        $this->allow($r, 'user_positions.view', $company->id);
        $r->validate(['page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $q = $this->assignmentsQuery()->where('company_id', $company->id);
        if ($r->filled('search')) {
            $q->where('users.name', 'ilike', '%'.$r->string('search').'%');
        }
        $page = $q->orderBy('user_positions.id')->paginate(25);
        $page->setCollection($this->assignmentRows($page->items()));

        return response()->json($page);
    }

    private function belongs(Company $company, UserPosition $assignment): Position
    {
        $position = Position::findOrFail($assignment->position_id);
        abort_unless($position->company_id === $company->id, 404);

        return $position;
    }

    public function assignment(Request $r, Company $company, UserPosition $assignment)
    {
        $this->allow($r, 'user_positions.view', $company->id);
        $this->belongs($company, $assignment);

        return response()->json(['data' => $this->assignmentData($this->assignmentsQuery()->where('user_positions.id', $assignment->id)->first())]);
    }

    public function create(AssignmentRequest $r, Company $company)
    {
        $this->allow($r, 'user_positions.create', $company->id);
        $d = $r->validated();
        $position = Position::where('company_id', $company->id)->findOrFail($d['position_id']);
        $assignment = $this->change->assignment(null, User::findOrFail($d['user_id']), $position, array_map('intval', $d['report_position_ids']), $r->user()->id);

        return response()->json(['data' => $this->assignmentData($this->assignmentsQuery()->where('user_positions.id', $assignment->id)->first())], 201);
    }

    public function replace(AssignmentRequest $r, Company $company, UserPosition $assignment)
    {
        $this->allow($r, 'user_positions.update', $company->id);
        $p = $this->belongs($company, $assignment);
        $this->change->assignment($assignment, User::findOrFail($assignment->user_id), $p, array_map('intval', $r->validated('report_position_ids')), $r->user()->id);

        return response()->json(['data' => $this->assignmentData($this->assignmentsQuery()->where('user_positions.id', $assignment->id)->first())]);
    }

    public function delete(Request $r, Company $company, UserPosition $assignment)
    {
        $this->allow($r, 'user_positions.delete', $company->id);
        $this->belongs($company, $assignment);
        $this->change->delete($assignment);

        return response()->noContent();
    }

    public function userPositions(Request $r, User $user)
    {
        $this->allow($r, 'personnel.view');
        $r->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = $this->assignmentsQuery()->where('user_id', $user->id)->orderBy('user_positions.id')->paginate(25);
        $page->setCollection($this->assignmentRows($page->items()));

        return response()->json($page);
    }

    public function options(Request $r)
    {
        $grants = $this->access->grants($r->user()->id);
        abort_unless(collect($grants)->contains(fn ($g) => in_array($g['key'], ['user_positions.create', 'access.assign'], true)), 403);

        $q = $this->query($r)->select('users.id', 'users.name');
        OptionSelection::prioritize($r, $q, 'users.id');

        return response()->json($q->orderBy('users.id')->paginate(100));
    }
}

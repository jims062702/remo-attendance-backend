<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\LookupException;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Site;
use App\Models\SupportTeam;
use App\Models\Workstation;
use App\Services\ActivityLogger;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin management of the reference lists the daily flow depends on:
 * projects, workstations, sites and support teams.
 *
 * One controller covers all four because the operations are identical -- list,
 * create, update, retire. The differences are confined to the `TYPES` map, so
 * adding another lookup means one entry rather than another controller.
 *
 * Nothing here hard deletes. Retiring sets is_active = false, which keeps the
 * row resolvable from the historical entries that reference it.
 */
class LookupController extends Controller
{
    use ApiResponses;

    /**
     * @var array<string, array{model: class-string<Model>, label: string, unique: string, with: array<int, string>}>
     */
    private const TYPES = [
        'projects' => [
            'model' => Project::class,
            'label' => 'Project',
            'unique' => 'code',
            'with' => [],
            // What would lose its meaning if the row disappeared. Deleting is
            // only offered when every one of these is empty; see destroy().
            'used_by' => [
                ['table' => 'tracker_items', 'column' => 'project_id', 'noun' => 'tracker entry'],
            ],
        ],
        'workstations' => [
            'model' => Workstation::class,
            'label' => 'Workstation',
            'unique' => 'name',
            'with' => ['site'],
            'used_by' => [
                ['table' => 'attendances', 'column' => 'workstation_id', 'noun' => 'shift'],
            ],
        ],
        'sites' => [
            'model' => Site::class,
            'label' => 'Site',
            'unique' => 'name',
            'with' => [],
            'used_by' => [
                ['table' => 'workstations', 'column' => 'site_id', 'noun' => 'workstation'],
                ['table' => 'tracker_entries', 'column' => 'site_id', 'noun' => 'tracker entry'],
            ],
        ],
        'support-teams' => [
            'model' => SupportTeam::class,
            'label' => 'Support team',
            'unique' => 'name',
            'with' => [],
            'used_by' => [
                ['table' => 'tracker_entries', 'column' => 'support_team_id', 'noun' => 'tracker entry'],
            ],
        ],
    ];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request, string $type): JsonResponse
    {
        $config = $this->config($type);

        /** @var \Illuminate\Database\Eloquent\Builder<Model> $query */
        $query = $config['model']::query();

        $records = $query
            ->with($config['with'])
            ->when($request->query('search'), function ($q, $term) use ($config) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $term).'%';
                $q->where($config['unique'], Sql::like(), $like);
            })
            ->when(
                $request->has('is_active') && $request->query('is_active') !== '',
                fn ($q) => $q->where('is_active', $request->boolean('is_active')),
            )
            ->orderBy($config['unique'])
            ->paginate((int) $request->query('per_page', 50))
            ->withQueryString();

        return $this->ok(
            collect($records->items())->map(fn (Model $row) => $this->present($type, $row))->all(),
            null,
            ['pagination' => $this->paginationMeta($records)],
        );
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $config = $this->config($type);
        $data = $this->validatePayload($request, $type, null);

        /** @var Model $record */
        $record = new $config['model'];
        $record->fill($data + ['is_active' => true]);
        $record->save();

        $this->logger->log(
            'lookup.created',
            "Added {$config['label']}: ".($data[$config['unique']] ?? ''),
            $record,
            $data,
            $request->user(),
        );

        return $this->created(
            $this->present($type, $record->load($config['with'])),
            "{$config['label']} added.",
        );
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $config = $this->config($type);

        /** @var Model $record */
        $record = $config['model']::findOrFail($id);
        $data = $this->validatePayload($request, $type, $id);

        $before = $record->only(array_keys($data));
        $record->fill($data);
        $record->save();

        $this->logger->logChanges(
            'lookup.updated',
            "Updated {$config['label']}",
            $record,
            $before,
            $record->only(array_keys($data)),
            $request->user(),
        );

        return $this->ok(
            $this->present($type, $record->load($config['with'])),
            "{$config['label']} updated.",
        );
    }

    /**
     * Retire rather than delete, so historical entries still resolve.
     */
    /**
     * Delete the record outright, but only while nothing depends on it.
     *
     * The two halves of this are not interchangeable, and the split is the
     * point. A workstation nobody has ever sat at is a mistake in a list and
     * should simply go. A workstation carrying 148 shifts is part of the
     * record of who was where, and the foreign key is nullOnDelete -- so
     * deleting it would not fail, it would quietly blank the PC column on
     * every one of those nights. Refusing is the only honest option, and the
     * caller is told the count so the refusal is not a mystery.
     *
     * Deactivating remains available for exactly that case: the row stays,
     * history keeps resolving, and the machine leaves the picker.
     */
    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $config = $this->config($type);

        /** @var Model $record */
        $record = $config['model']::findOrFail($id);

        $blockers = $this->dependents($config, $id);

        if ($blockers !== []) {
            throw LookupException::inUse(
                strtolower($config['label']),
                $this->describe($blockers),
                $blockers,
            );
        }

        $snapshot = $this->present($type, $record);

        $record->delete();

        $this->logger->log(
            'lookup.deleted',
            "Deleted {$config['label']}",
            null,
            ['type' => $type, 'record' => $snapshot],
            $request->user(),
        );

        return $this->ok(
            $snapshot,
            "{$config['label']} deleted.",
        );
    }

    /**
     * Take the record out of circulation without removing it.
     */
    public function deactivate(Request $request, string $type, int $id): JsonResponse
    {
        $config = $this->config($type);

        /** @var Model $record */
        $record = $config['model']::findOrFail($id);
        $record->is_active = false;
        $record->save();

        $this->logger->log(
            'lookup.deactivated',
            "Deactivated {$config['label']}",
            $record,
            [],
            $request->user(),
        );

        return $this->ok(
            $this->present($type, $record),
            "{$config['label']} deactivated. Existing records that reference it are unaffected.",
        );
    }

    /**
     * Rows elsewhere that point at this record, counted per table.
     *
     * @param  array<string, mixed>  $config
     * @return array<int, array{noun: string, count: int}>
     */
    private function dependents(array $config, int $id): array
    {
        $found = [];

        foreach ($config['used_by'] ?? [] as $relation) {
            $count = DB::table($relation['table'])
                ->where($relation['column'], $id)
                ->count();

            if ($count > 0) {
                $found[] = ['noun' => $relation['noun'], 'count' => $count];
            }
        }

        return $found;
    }

    /**
     * "148 shifts", or "3 workstations and 12 tracker entries".
     *
     * @param  array<int, array{noun: string, count: int}>  $blockers
     */
    private function describe(array $blockers): string
    {
        $parts = array_map(
            fn (array $b): string => $b['count'].' '.$b['noun'].($b['count'] === 1 ? '' : 's'),
            $blockers,
        );

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }


    // ----------------------------------------------------------------- Helpers

    /**
     * @return array{model: class-string<Model>, label: string, unique: string, with: array<int, string>}
     */
    private function config(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404, 'Unknown lookup type.');

        return self::TYPES[$type];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, string $type, ?int $ignoreId): array
    {
        $rules = match ($type) {
            'projects' => [
                'code' => [
                    'required', 'string', 'max:255',
                    Rule::unique('projects', 'code')->ignore($ignoreId),
                ],
                'name' => ['nullable', 'string', 'max:255'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'workstations' => [
                'name' => ['required', 'string', 'max:255'],
                'site_id' => ['nullable', 'integer', 'exists:sites,id'],
                'notes' => ['nullable', 'string', 'max:500'],
                'is_active' => ['sometimes', 'boolean'],
                'is_support' => ['sometimes', 'boolean'],
            ],
            'sites' => [
                'name' => [
                    'required', 'string', 'max:255',
                    Rule::unique('sites', 'name')->ignore($ignoreId),
                ],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'support-teams' => [
                'name' => [
                    'required', 'string', 'max:255',
                    Rule::unique('support_teams', 'name')->ignore($ignoreId),
                ],
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            default => [],
        };

        $validated = $request->validate($rules);

        // A workstation name is unique per site, not globally -- two sites may
        // each have a "PC-01". Checked here because the rule depends on another
        // submitted field.
        if ($type === 'workstations') {
            $exists = Workstation::query()
                ->where('site_id', $validated['site_id'] ?? null)
                ->where('name', $validated['name'])
                ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                abort(422, 'A workstation with that name already exists at this site.');
            }
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $type, Model $row): array
    {
        $base = [
            'id' => $row->getKey(),
            'is_active' => (bool) $row->getAttribute('is_active'),
            'created_at' => $row->getAttribute('created_at')?->toIso8601String(),
        ];

        return match ($type) {
            'projects' => $base + [
                'code' => $row->getAttribute('code'),
                'name' => $row->getAttribute('name'),
            ],
            'workstations' => $base + [
                'name' => $row->getAttribute('name'),
                'site_id' => $row->getAttribute('site_id'),
                'site_name' => $row->relationLoaded('site') ? $row->getRelation('site')?->name : null,
                'notes' => $row->getAttribute('notes'),
                'is_support' => (bool) $row->getAttribute('is_support'),
            ],
            default => $base + ['name' => $row->getAttribute('name')],
        };
    }
}

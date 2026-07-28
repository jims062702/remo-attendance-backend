<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Site;
use App\Models\SupportTeam;
use App\Models\Workstation;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ],
        'workstations' => [
            'model' => Workstation::class,
            'label' => 'Workstation',
            'unique' => 'name',
            'with' => ['site'],
        ],
        'sites' => [
            'model' => Site::class,
            'label' => 'Site',
            'unique' => 'name',
            'with' => [],
        ],
        'support-teams' => [
            'model' => SupportTeam::class,
            'label' => 'Support team',
            'unique' => 'name',
            'with' => [],
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
                $q->where($config['unique'], 'like', $like);
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
    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $config = $this->config($type);

        /** @var Model $record */
        $record = $config['model']::findOrFail($id);
        $record->is_active = false;
        $record->save();

        $this->logger->log(
            'lookup.retired',
            "Retired {$config['label']}",
            $record,
            [],
            $request->user(),
        );

        return $this->ok(
            $this->present($type, $record),
            "{$config['label']} retired. Existing records that reference it are unaffected.",
        );
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

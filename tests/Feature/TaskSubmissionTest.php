<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Task;
use App\Services\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function (): void {
    $this->tasker = tasker();
    Date::setTestNow(CarbonImmutable::parse('2026-07-26 23:00'));
});

afterEach(function (): void {
    Date::setTestNow();
});

it('submits a task with a generated code and server derived date', function (): void {
    $response = $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'task_name' => 'Data Validation',
        'task_description' => 'Validate customer records',
        'output_count' => 150,
        'task_status' => 'completed',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.task_name', 'Data Validation')
        ->assertJsonPath('data.output_count', 150)
        ->assertJsonPath('data.task_date', '2026-07-26')
        ->assertJsonPath('data.task_code', 'TASK-20260726-0001');
});

it('numbers task codes sequentially within a business date', function (): void {
    $service = app(TaskService::class);

    $codes = collect(range(1, 3))->map(fn () => $service->create($this->tasker, [
        'task_name' => 'Data Entry',
        'output_count' => 10,
        'task_status' => 'completed',
    ])->task_code);

    expect($codes->all())->toBe([
        'TASK-20260726-0001',
        'TASK-20260726-0002',
        'TASK-20260726-0003',
    ]);
});

it('does not reuse the code of a soft deleted task', function (): void {
    $service = app(TaskService::class);

    $first = $service->create($this->tasker, [
        'task_name' => 'A', 'output_count' => 1, 'task_status' => 'pending',
    ]);

    $first->delete();

    $second = $service->create($this->tasker, [
        'task_name' => 'B', 'output_count' => 1, 'task_status' => 'pending',
    ]);

    // The deleted row still occupies its code in the unique index.
    expect($second->task_code)->toBe('TASK-20260726-0002');
});

it('links a submission to the shift it was produced in', function (): void {
    $attendance = Attendance::create([
        'user_id' => $this->tasker->id,
        'attendance_date' => '2026-07-26',
        'time_in' => CarbonImmutable::parse('2026-07-26 22:00'),
        'status' => 'present',
    ]);

    $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'task_name' => 'Data Entry',
        'output_count' => 200,
        'task_status' => 'in_progress',
    ])->assertCreated();

    expect(Task::firstOrFail()->attendance_id)->toBe($attendance->id);
});

it('stores "N/A" optional fields as null', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'task_name' => 'Data Entry',
        'output_count' => 10,
        'task_status' => 'pending',
        'screenshot_link' => 'N/A',
        'external_task_id' => 'n/a',
    ])->assertCreated()
        // NULL in the database, "N/A" for display.
        ->assertJsonPath('data.screenshot_link', null)
        ->assertJsonPath('data.screenshot_link_display', 'N/A')
        ->assertJsonPath('data.external_task_id', null)
        ->assertJsonPath('data.external_task_id_display', 'N/A');

    $task = Task::firstOrFail();

    expect($task->screenshot_link)->toBeNull()
        ->and($task->external_task_id)->toBeNull();
});

it('accepts multiple tasks with no external reference', function (): void {
    // The case a single nullable-but-unique "Task ID" column could not handle:
    // two rows both meaning "not applicable".
    foreach (['First', 'Second', 'Third'] as $name) {
        $this->actingAs($this->tasker)->postJson('/api/tasks', [
            'task_name' => $name,
            'output_count' => 5,
            'task_status' => 'pending',
            'external_task_id' => 'N/A',
        ])->assertCreated();
    }

    expect(Task::whereNull('external_task_id')->count())->toBe(3)
        ->and(Task::distinct()->count('task_code'))->toBe(3);
});

it('rejects a malformed screenshot link', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'task_name' => 'Data Entry',
        'output_count' => 10,
        'task_status' => 'pending',
        'screenshot_link' => 'not-a-url',
    ])->assertStatus(422)->assertJsonValidationErrors('screenshot_link');
});

it('accepts a valid screenshot link', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'task_name' => 'Data Entry',
        'output_count' => 10,
        'task_status' => 'pending',
        'screenshot_link' => 'https://drive.example.com/file/abc123',
    ])->assertCreated()
        ->assertJsonPath('data.screenshot_link', 'https://drive.example.com/file/abc123');
});

it('rejects a negative output count', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'task_name' => 'Data Entry',
        'output_count' => -5,
        'task_status' => 'pending',
    ])->assertStatus(422)->assertJsonValidationErrors('output_count');
});

it('rejects an unknown task status', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'task_name' => 'Data Entry',
        'output_count' => 10,
        'task_status' => 'invented_status',
    ])->assertStatus(422)->assertJsonValidationErrors('task_status');
});

it('requires a task name', function (): void {
    $this->actingAs($this->tasker)->postJson('/api/tasks', [
        'output_count' => 10,
        'task_status' => 'pending',
    ])->assertStatus(422)->assertJsonValidationErrors('task_name');
});

it('soft deletes rather than destroying a task', function (): void {
    $task = Task::factory()->for($this->tasker)->pending()->create();

    $this->actingAs($this->tasker)->deleteJson("/api/tasks/{$task->id}")->assertOk();

    expect(Task::count())->toBe(0)
        ->and(Task::withTrashed()->count())->toBe(1);
});

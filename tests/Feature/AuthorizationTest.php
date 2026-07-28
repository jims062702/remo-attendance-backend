<?php

declare(strict_types=1);

use App\Models\Attendance;
use App\Models\Task;

/**
 * Business rules 8 and 9: a tasker reaches only their own records; an admin
 * reaches everyone's.
 *
 * These are the tests that matter most for privacy -- a regression here leaks
 * one employee's attendance to another.
 */
beforeEach(function (): void {
    $this->alice = tasker(['name' => 'Alice', 'email' => 'alice@test.local']);
    $this->bob = tasker(['name' => 'Bob', 'email' => 'bob@test.local']);
    $this->admin = admin();
});

it('forbids a tasker from reading another tasker\'s task', function (): void {
    $bobsTask = Task::factory()->for($this->bob)->create();

    $this->actingAs($this->alice)->getJson("/api/tasks/{$bobsTask->id}")
        ->assertForbidden();
});

it('allows a tasker to read their own task', function (): void {
    $task = Task::factory()->for($this->alice)->create();

    $this->actingAs($this->alice)->getJson("/api/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $task->id);
});

it('allows an admin to read any task', function (): void {
    $bobsTask = Task::factory()->for($this->bob)->create();

    $this->actingAs($this->admin)->getJson("/api/tasks/{$bobsTask->id}")
        ->assertOk();
});

it('forbids a tasker from editing another tasker\'s task', function (): void {
    $bobsTask = Task::factory()->for($this->bob)->pending()->create();

    $this->actingAs($this->alice)
        ->putJson("/api/tasks/{$bobsTask->id}", ['output_count' => 999])
        ->assertForbidden();

    expect($bobsTask->refresh()->output_count)->not->toBe(999);
});

it('scopes the task list to the authenticated tasker', function (): void {
    Task::factory()->count(3)->for($this->alice)->create();
    Task::factory()->count(5)->for($this->bob)->create();

    $response = $this->actingAs($this->alice)->getJson('/api/tasks')->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(3);

    foreach ($response->json('data') as $task) {
        expect($task['user_id'])->toBe($this->alice->id);
    }
});

it('ignores a user_id filter supplied by a tasker', function (): void {
    Task::factory()->count(3)->for($this->alice)->create();
    Task::factory()->count(5)->for($this->bob)->create();

    // Alice tries to read Bob's list by filtering for his id.
    $response = $this->actingAs($this->alice)
        ->getJson("/api/tasks?user_id={$this->bob->id}")
        ->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(3);
});

it('scopes attendance history to the authenticated tasker', function (): void {
    Attendance::factory()->count(2)->for($this->alice)->create();
    Attendance::factory()->count(4)->for($this->bob)->create();

    $response = $this->actingAs($this->alice)
        ->getJson("/api/attendance/history?user_id={$this->bob->id}")
        ->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(2);
});

it('forbids a tasker from every admin endpoint', function (string $method, string $uri): void {
    $this->actingAs($this->alice)->json($method, $uri)
        ->assertForbidden()
        ->assertJsonPath('code', 'auth.forbidden');
})->with([
    ['get', '/api/admin/dashboard'],
    ['get', '/api/admin/attendance'],
    ['get', '/api/admin/taskers'],
    ['post', '/api/admin/taskers'],
    ['get', '/api/admin/reports/attendance'],
    ['get', '/api/admin/reports/productivity'],
    ['get', '/api/admin/activity-logs'],
    ['get', '/api/admin/exports/attendance'],
]);

it('allows an admin to list all attendance', function (): void {
    Attendance::factory()->count(2)->for($this->alice)->create();
    Attendance::factory()->count(4)->for($this->bob)->create();

    $response = $this->actingAs($this->admin)->getJson('/api/admin/attendance')->assertOk();

    expect($response->json('meta.pagination.total'))->toBe(6);
});

it('rejects unauthenticated requests', function (): void {
    $this->getJson('/api/me')->assertUnauthorized();
    $this->postJson('/api/attendance/time-in')->assertUnauthorized();
    $this->getJson('/api/admin/dashboard')->assertUnauthorized();
});

it('prevents a tasker from editing a completed task', function (): void {
    // Once work is reported as done it becomes production history; only an
    // admin may revise it.
    $task = Task::factory()->for($this->alice)->completed()->create();

    $this->actingAs($this->alice)
        ->putJson("/api/tasks/{$task->id}", ['output_count' => 500])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->putJson("/api/tasks/{$task->id}", ['output_count' => 500])
        ->assertOk();
});

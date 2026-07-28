<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->admin = admin();
    $this->juan = tasker(['name' => 'Juan Dela Cruz', 'email' => 'juan@test.local']);
    $this->ana = tasker(['name' => 'Ana Reyes', 'email' => 'ana@test.local']);
});

afterEach(function (): void {
    Date::setTestNow();
});

/**
 * Build a CSV upload with the importer's expected header row.
 *
 * @param  array<int, array<int, string>>  $rows
 */
function importFile(array $rows, string $name = 'attendance.csv'): UploadedFile
{
    $header = 'Email,Shift Date,Time In,Time Out,Committed Hours,Status,Notes';
    $lines = array_map(fn (array $row) => implode(',', $row), $rows);

    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, $header."\n".implode("\n", $lines));

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

// -------------------------------------------------------------------- Exports

it('exports a formatted attendance workbook', function (): void {
    Attendance::factory()->count(3)->for($this->juan)->create();

    $response = $this->actingAs($this->admin)
        ->get('/api/admin/exports/attendance?from=2026-07-01&to=2026-07-31');

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($response->headers->get('content-disposition'))->toContain('attendance_');
});

it('exports each report type', function (string $type): void {
    Attendance::factory()->for($this->juan)->create();

    $this->actingAs($this->admin)->get("/api/admin/exports/{$type}")->assertOk();
})->with(['attendance', 'productivity', 'taskers', 'tasker-summary']);

it('rejects an unknown export type', function (): void {
    $this->actingAs($this->admin)->get('/api/admin/exports/nonsense')->assertNotFound();
});

it('records an export in the audit log', function (): void {
    $this->actingAs($this->admin)->get('/api/admin/exports/attendance')->assertOk();

    $this->assertDatabaseHas('activity_logs', ['action' => 'report.exported']);
});

it('offers a downloadable import template', function (): void {
    $this->actingAs($this->admin)
        ->get('/api/admin/imports/attendance/template')
        ->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

// -------------------------------------------------------------------- Imports

it('previews a valid file without writing anything', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', 'Normal shift'],
        ['ana@test.local', '2026-07-20', '22:30', '06:00', '8', '', 'Late arrival'],
    ]);

    $response = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    expect($response->json('data.summary.total'))->toBe(2)
        ->and($response->json('data.summary.valid'))->toBe(2)
        ->and($response->json('data.summary.invalid'))->toBe(0)
        // Nothing is written during preview.
        ->and(Attendance::count())->toBe(0);
});

it('resolves an overnight time out onto the following day', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', ''],
    ]);

    $preview = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    // 06:00 looks "before" 22:00, so it must be read as the next morning.
    expect($preview->json('data.rows.0.input.time_in'))->toBe('2026-07-20 22:00')
        ->and($preview->json('data.rows.0.input.time_out'))->toBe('2026-07-21 06:00');

    $this->actingAs($this->admin)
        ->postJson('/api/admin/imports/attendance/commit', ['token' => $preview->json('data.token')])
        ->assertOk();

    $attendance = Attendance::firstOrFail();

    expect($attendance->attendance_date->toDateString())->toBe('2026-07-20')
        ->and($attendance->total_hours)->toBe(8.0);
});

it('flags a row whose email is not registered', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', ''],
        ['ghost@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', ''],
    ]);

    $response = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    expect($response->json('data.summary.valid'))->toBe(1)
        ->and($response->json('data.summary.invalid'))->toBe(1)
        ->and($response->json('data.rows.1.errors.0'))->toContain('No registered tasker');
});

it('flags duplicate rows within the same file', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', ''],
        ['juan@test.local', '2026-07-20', '23:00', '06:00', '8', 'present', ''],
    ]);

    $response = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    // Row numbers are the spreadsheet's own, so the first data row is row 2.
    expect($response->json('data.summary.invalid'))->toBe(1)
        ->and($response->json('data.rows.1.errors.0'))->toContain('Duplicate of row 2');
});

it('flags a row that would exceed the maximum shift length', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '20:00', '8', '', ''],
    ]);

    $response = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    expect($response->json('data.rows.0.valid'))->toBeFalse()
        ->and($response->json('data.rows.0.errors.0'))->toContain('above the');
});

it('flags an unrecognised status', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '06:00', '8', 'vacationing', ''],
    ]);

    $response = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    expect($response->json('data.rows.0.errors.0'))->toContain('not a recognised attendance status');
});

it('derives a missing status from the imported times', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:05', '06:00', '8', '', ''],
        ['ana@test.local', '2026-07-20', '22:45', '06:00', '8', '', ''],
    ]);

    $response = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    // Within grace vs. beyond it.
    expect($response->json('data.rows.0.input.status'))->toBe('present')
        ->and($response->json('data.rows.1.input.status'))->toBe('late');
});

it('imports only the valid rows on commit', function (): void {
    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', ''],
        ['ghost@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', ''],
        ['ana@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', ''],
    ]);

    $preview = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/admin/imports/attendance/commit', ['token' => $preview->json('data.token')])
        ->assertOk();

    expect($response->json('data.summary.imported'))->toBe(2)
        ->and(Attendance::count())->toBe(2);
});

it('updates rather than duplicates an existing record', function (): void {
    Attendance::create([
        'user_id' => $this->juan->id,
        'attendance_date' => '2026-07-20',
        'status' => AttendanceStatus::Absent,
    ]);

    $file = importFile([
        ['juan@test.local', '2026-07-20', '22:00', '06:00', '8', 'present', 'Corrected'],
    ]);

    $preview = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', ['file' => $file])
        ->assertOk();

    // The admin is warned before committing.
    expect($preview->json('data.rows.0.will_update'))->toBeTrue();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/admin/imports/attendance/commit', ['token' => $preview->json('data.token')])
        ->assertOk();

    expect($response->json('data.summary.updated'))->toBe(1)
        ->and(Attendance::count())->toBe(1)
        ->and(Attendance::first()->status)->toBe(AttendanceStatus::Present);
});

it('rejects a file missing the required columns', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents($path, "Something,Else\nfoo,bar");

    $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', [
            'file' => new UploadedFile($path, 'bad.csv', 'text/csv', null, true),
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'import.unreadable');
});

it('accepts alternative column headings', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'import').'.csv';
    file_put_contents(
        $path,
        "Email Address,Date,Clock In,Clock Out,Commitment,Status,Remarks\n"
        .'juan@test.local,2026-07-20,22:00,06:00,8,present,ok',
    );

    $response = $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', [
            'file' => new UploadedFile($path, 'aliased.csv', 'text/csv', null, true),
        ])
        ->assertOk();

    expect($response->json('data.summary.valid'))->toBe(1);
});

it('rejects a non spreadsheet upload', function (): void {
    Storage::fake('local');

    $this->actingAs($this->admin)
        ->post('/api/admin/imports/attendance/preview', [
            'file' => UploadedFile::fake()->create('malware.exe', 16),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('rejects a commit with an unknown token', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/admin/imports/attendance/commit', [
            'token' => '00000000-0000-4000-8000-000000000000',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'import.expired');
});

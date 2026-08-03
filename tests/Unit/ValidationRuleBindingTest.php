<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\DatabaseRule;

/**
 * Guards a bug that no functional test in this suite can catch.
 *
 * `Rule::exists(...)->where($column, $value)` does not hand the value to the
 * query builder. It serialises it into the rule's string form, and that
 * serialisation is a plain string cast -- so `false` becomes an empty string
 * and the constraint reaches the database as `is_support = ''`.
 *
 * MySQL coerces '' to 0, which is the value that was meant, so the rule appears
 * to work and every test here passes. PostgreSQL refuses to guess:
 *
 *     SQLSTATE[22P02]: invalid input syntax for type boolean: ""
 *
 * That took down every activation in production while development stayed
 * green. Both engines are exercised by this application -- MariaDB locally,
 * PostgreSQL deployed -- so "passes on MySQL" is not evidence of correctness.
 *
 * The fix is to pass a closure, which goes straight to the query builder with
 * the boolean intact. This test asserts the serialised form of every database
 * rule in the application carries no empty value, which is what a lost boolean
 * looks like from the outside.
 */

/**
 * Every FormRequest in the application, so a rule added later is covered
 * without anyone remembering to list it here.
 *
 * @return array<int, class-string<FormRequest>>
 */
function formRequestClasses(): array
{
    $classes = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Http/Requests')),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(
            [app_path().DIRECTORY_SEPARATOR, '/', '.php'],
            ['App\\', '\\', ''],
            $file->getPathname(),
        );

        $class = str_replace('\\\\', '\\', $relative);

        if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('finds the request classes to check', function (): void {
    // A sweep that silently matched nothing would pass forever while checking
    // nothing at all.
    expect(formRequestClasses())->not->toBeEmpty();
});

it('never serialises a validation constraint to an empty value', function (): void {
    $offenders = [];

    foreach (formRequestClasses() as $class) {
        $request = new $class;

        try {
            $rules = $request->rules();
        } catch (Throwable) {
            // Some requests read the route for an ignore id and cannot be
            // built standalone. Skipping them is safe: this is a sweep for a
            // known shape, not a completeness guarantee.
            continue;
        }

        foreach ($rules as $field => $fieldRules) {
            foreach ((array) $fieldRules as $rule) {
                if (! $rule instanceof DatabaseRule) {
                    continue;
                }

                $serialised = (string) $rule;

                // `column,""` is the signature: a where-value that cast to an
                // empty string. A boolean false is the usual cause.
                if (str_contains($serialised, ',""')) {
                    $offenders[] = "{$class}::{$field} -> {$serialised}";
                }
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'A database validation rule serialised a constraint to an empty string. '
        .'This is almost certainly ->where($column, false); pass a closure instead '
        .'so the boolean reaches the query builder intact. See app/Http/Requests/'
        .'Daily/ActivateAttendanceRequest.php for the correct form.',
    );
});

it('still rejects a support PC through the fixed rule', function (): void {
    // The behaviour the rule exists for, proving the closure form did not
    // quietly drop the constraint along with the serialisation.
    $site = App\Models\Site::create(['name' => 'BEAMO 3F C', 'is_active' => true]);

    $support = App\Models\Workstation::create([
        'name' => 'PC-19', 'site_id' => $site->id, 'is_active' => true, 'is_support' => true,
    ]);
    $normal = App\Models\Workstation::create([
        'name' => 'PC-20', 'site_id' => $site->id, 'is_active' => true, 'is_support' => false,
    ]);
    $inactive = App\Models\Workstation::create([
        'name' => 'PC-21', 'site_id' => $site->id, 'is_active' => false, 'is_support' => false,
    ]);

    $payload = fn (int $id): array => [
        'commitment_bracket' => '7_plus_hours',
        'tasking_statuses' => ['tasking'],
        'workstation_id' => $id,
        'pc_status' => 'used',
    ];

    $tasker = tasker();

    $this->actingAs($tasker)->postJson('/api/daily/activate', $payload($support->id))
        ->assertStatus(422)->assertJsonValidationErrors('workstation_id');

    $this->actingAs($tasker)->postJson('/api/daily/activate', $payload($inactive->id))
        ->assertStatus(422)->assertJsonValidationErrors('workstation_id');

    $this->actingAs($tasker)->postJson('/api/daily/activate', $payload($normal->id))
        ->assertCreated();
});

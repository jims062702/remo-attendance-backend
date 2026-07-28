<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    private const TASK_NAMES = [
        'Data Validation',
        'Data Entry',
        'Image Tagging',
        'Transcription',
        'Lead Verification',
        'Content Moderation',
        'Catalogue Cleanup',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = CarbonImmutable::instance(fake()->dateTimeBetween('-30 days', '-1 day'))->startOfDay();

        return [
            // Unique without a database round trip, which keeps factory-heavy
            // tests fast. Production codes are minted sequentially by
            // TaskService; this only has to not collide.
            'task_code' => 'TASK-'.$date->format('Ymd').'-'.Str::upper(Str::random(6)),
            'external_task_id' => fake()->boolean(40) ? 'REF-'.fake()->numberBetween(1000, 9999) : null,
            'user_id' => User::factory()->tasker(),
            'attendance_id' => null,
            'task_date' => $date->toDateString(),
            'task_name' => fake()->randomElement(self::TASK_NAMES),
            'task_description' => fake()->sentence(),
            'output_count' => fake()->numberBetween(20, 400),
            'task_status' => fake()->randomElement(TaskStatus::cases()),
            'screenshot_link' => fake()->boolean(30) ? fake()->url() : null,
            'notes' => fake()->boolean(20) ? fake()->sentence() : null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => ['task_status' => TaskStatus::Completed]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['task_status' => TaskStatus::Pending]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['task_status' => TaskStatus::Cancelled]);
    }
}

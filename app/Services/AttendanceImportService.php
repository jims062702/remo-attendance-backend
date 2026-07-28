<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Two-phase Excel import of historical attendance.
 *
 * Phase 1 (preview) parses and validates every row and returns a per-row
 * verdict without writing anything. Phase 2 (commit) re-reads the stored file
 * and writes only the rows that pass.
 *
 * The file is re-parsed on commit rather than the parsed rows being cached: a
 * large import would otherwise have to be held in the cache between two
 * requests, and the cached copy could diverge from the file the admin reviewed.
 */
class AttendanceImportService
{
    private const DISK = 'local';

    private const DIRECTORY = 'imports';

    /**
     * Accepted spellings for each column, after Laravel Excel has slugged the
     * heading row. Being liberal here costs nothing and spares admins from
     * renaming columns to match us exactly.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMN_ALIASES = [
        'email' => ['email', 'email_address', 'tasker_email'],
        'shift_date' => ['shift_date', 'date', 'attendance_date', 'business_date'],
        'time_in' => ['time_in', 'timein', 'clock_in', 'start_time'],
        'time_out' => ['time_out', 'timeout', 'clock_out', 'end_time'],
        'expected_hours' => ['expected_hours', 'committed_hours', 'commitment', 'commitment_hours'],
        'status' => ['status', 'attendance_status'],
        'notes' => ['notes', 'remarks', 'comment'],
    ];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly ActivityLogger $logger,
    ) {}

    // -------------------------------------------------------------- Phase one

    /**
     * Store the upload and return a validated preview.
     *
     * @return array{token: string, summary: array<string, int>, rows: array<int, array<string, mixed>>}
     */
    public function preview(UploadedFile $file): array
    {
        $token = (string) Str::uuid();
        $path = self::DIRECTORY.'/'.$token.'.'.$file->getClientOriginalExtension();

        Storage::disk(self::DISK)->putFileAs(
            self::DIRECTORY,
            $file,
            $token.'.'.$file->getClientOriginalExtension(),
        );

        $rows = $this->parse($path);

        return [
            'token' => $token,
            'filename' => $file->getClientOriginalName(),
            'summary' => $this->summarise($rows),
            'rows' => $rows,
        ];
    }

    // -------------------------------------------------------------- Phase two

    /**
     * Import the valid rows of a previously previewed file.
     *
     * @return array{summary: array<string, int>, rows: array<int, array<string, mixed>>}
     */
    public function commit(string $token, ?User $actor = null): array
    {
        $path = $this->locate($token);
        $rows = $this->parse($path);

        $imported = 0;
        $updated = 0;

        DB::transaction(function () use ($rows, &$imported, &$updated): void {
            foreach ($rows as $row) {
                if (! $row['valid']) {
                    continue;
                }

                $existing = Attendance::query()
                    ->where('user_id', $row['resolved']['user_id'])
                    ->where('attendance_date', $row['resolved']['attendance_date'])
                    ->first();

                $payload = [
                    'time_in' => $row['resolved']['time_in'],
                    'time_out' => $row['resolved']['time_out'],
                    'total_hours' => $row['resolved']['total_hours'],
                    'expected_hours' => $row['resolved']['expected_hours'],
                    'status' => $row['resolved']['status'],
                    'notes' => $row['resolved']['notes'],
                ];

                if ($existing !== null) {
                    $existing->forceFill($payload)->save();
                    $updated++;

                    continue;
                }

                Attendance::create($payload + [
                    'user_id' => $row['resolved']['user_id'],
                    'attendance_date' => $row['resolved']['attendance_date'],
                ]);
                $imported++;
            }
        });

        Storage::disk(self::DISK)->delete($path);

        $summary = $this->summarise($rows) + ['imported' => $imported, 'updated' => $updated];

        $this->logger->log(
            'attendance.imported',
            "Imported {$imported} new and updated {$updated} attendance records",
            null,
            $summary,
            $actor,
        );

        return ['summary' => $summary, 'rows' => $rows];
    }

    // ----------------------------------------------------------------- Parsing

    /**
     * Read every row and attach a verdict. Nothing is written here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $path): array
    {
        $absolute = Storage::disk(self::DISK)->path($path);

        /** @var Collection<int, Collection<int, Collection<string, mixed>>> $sheets */
        $sheets = Excel::toCollection(null, $absolute);

        $sheet = $sheets->first() ?? collect();

        if ($sheet->isEmpty()) {
            return [];
        }

        // First row is the header; map its cells onto our canonical names.
        $headerMap = $this->mapHeaders($sheet->first());

        if (! isset($headerMap['email'], $headerMap['shift_date'])) {
            throw new RuntimeException(
                'The file must contain at least an "Email" and a "Shift Date" column.',
            );
        }

        // Resolve every referenced address in one query rather than per row.
        $emails = $sheet->skip(1)
            ->map(fn ($row) => $this->cell($row, $headerMap, 'email'))
            ->filter()
            ->map(fn ($e) => strtolower(trim((string) $e)))
            ->unique();

        $users = User::query()
            ->whereIn('email', $emails)
            ->get()
            ->keyBy(fn (User $u) => strtolower($u->email));

        $existingKeys = [];
        $rows = [];

        foreach ($sheet->skip(1) as $index => $raw) {
            // skip(1) preserves keys, so $index is 1 for the first data row.
            // +1 makes it the spreadsheet's own row number (row 1 is the
            // header), which is what an admin sees when fixing an error.
            $rows[] = $this->parseRow($raw, $headerMap, $users, $index + 1, $existingKeys);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, mixed>  $raw
     * @param  array<string, int>  $headerMap
     * @param  Collection<string, User>  $users
     * @param  array<string, int>  $existingKeys
     * @return array<string, mixed>
     */
    private function parseRow(
        Collection $raw,
        array $headerMap,
        Collection $users,
        int $rowNumber,
        array &$existingKeys,
    ): array {
        $errors = [];

        $emailRaw = trim((string) $this->cell($raw, $headerMap, 'email'));
        $email = strtolower($emailRaw);
        $user = $email === '' ? null : $users->get($email);

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "\"{$emailRaw}\" is not a valid email address.";
        } elseif ($user === null) {
            $errors[] = "No registered tasker has the email \"{$emailRaw}\".";
        } elseif (! $user->canAuthenticate()) {
            $errors[] = "The account for \"{$emailRaw}\" is {$user->status->label()}.";
        }

        $date = $this->parseDate($this->cell($raw, $headerMap, 'shift_date'));

        if ($date === null) {
            $errors[] = 'Shift date is missing or not a recognisable date.';
        }

        $timeIn = $date ? $this->parseDateTime($this->cell($raw, $headerMap, 'time_in'), $date) : null;
        $timeOut = $date ? $this->parseDateTime($this->cell($raw, $headerMap, 'time_out'), $date) : null;

        // The shift runs overnight, so a time out that looks earlier than the
        // time in belongs to the following calendar day. Applying the same rule
        // the live clock uses keeps imported history consistent with recorded
        // history.
        if ($timeIn !== null && $timeOut !== null && $timeOut->lessThanOrEqualTo($timeIn)) {
            $timeOut = $timeOut->addDay();
        }

        if ($timeOut !== null && $timeIn === null) {
            $errors[] = 'A time out cannot be imported without a time in.';
        }

        $totalHours = null;

        if ($timeIn !== null && $timeOut !== null) {
            $hours = round(($timeOut->getTimestamp() - $timeIn->getTimestamp()) / 3600, 2);
            $max = (float) config('attendance.max_shift_hours');

            if ($hours > $max) {
                $errors[] = sprintf('The shift spans %.2f hours, above the %.2f hour maximum.', $hours, $max);
            } else {
                $totalHours = $hours;
            }
        }

        $expectedHours = $this->parseNumber($this->cell($raw, $headerMap, 'expected_hours'));

        if ($expectedHours !== null) {
            $min = (float) config('attendance.commitment.min');
            $max = (float) config('attendance.commitment.max');

            if ($expectedHours < $min || $expectedHours > $max) {
                $errors[] = sprintf('Committed hours must be between %s and %s.', $min, $max);
                $expectedHours = null;
            }
        }

        $status = $this->parseStatus($this->cell($raw, $headerMap, 'status'), $timeIn, $timeOut, $date, $errors);

        // Duplicates within the uploaded file itself.
        $duplicateOfRow = null;

        if ($user !== null && $date !== null) {
            $key = $user->id.'|'.$date->toDateString();

            if (isset($existingKeys[$key])) {
                $duplicateOfRow = $existingKeys[$key];
                $errors[] = "Duplicate of row {$duplicateOfRow} -- the same tasker and shift date appear twice in this file.";
            } else {
                $existingKeys[$key] = $rowNumber;
            }
        }

        // Rows that will overwrite an existing record are flagged but allowed:
        // correcting historical data is a legitimate reason to import.
        $willUpdate = false;

        if ($user !== null && $date !== null && $duplicateOfRow === null) {
            $willUpdate = Attendance::query()
                ->where('user_id', $user->id)
                ->where('attendance_date', $date->toDateString())
                ->exists();
        }

        return [
            'row' => $rowNumber,
            'valid' => $errors === [],
            'errors' => $errors,
            'will_update' => $willUpdate,
            'input' => [
                'email' => $emailRaw,
                'shift_date' => $date?->toDateString(),
                'time_in' => $timeIn?->format('Y-m-d H:i'),
                'time_out' => $timeOut?->format('Y-m-d H:i'),
                'expected_hours' => $expectedHours,
                'status' => $status?->value,
                'notes' => $this->cell($raw, $headerMap, 'notes'),
            ],
            'resolved' => $errors === [] ? [
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'attendance_date' => $date?->toDateString(),
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'total_hours' => $totalHours,
                'expected_hours' => $expectedHours,
                'status' => $status ?? AttendanceStatus::Present,
                'notes' => $this->stringOrNull($this->cell($raw, $headerMap, 'notes')),
            ] : null,
        ];
    }

    // ----------------------------------------------------------- Cell parsing

    /**
     * @param  Collection<int, mixed>  $headerRow
     * @return array<string, int>
     */
    private function mapHeaders(Collection $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $heading) {
            $slug = Str::snake(strtolower(trim((string) $heading)));
            $slug = preg_replace('/[^a-z0-9_]/', '', $slug) ?? '';

            foreach (self::COLUMN_ALIASES as $canonical => $aliases) {
                if (in_array($slug, $aliases, true) && ! isset($map[$canonical])) {
                    $map[$canonical] = (int) $index;
                }
            }
        }

        return $map;
    }

    /**
     * @param  Collection<int, mixed>  $row
     * @param  array<string, int>  $headerMap
     */
    private function cell(Collection $row, array $headerMap, string $column): mixed
    {
        if (! isset($headerMap[$column])) {
            return null;
        }

        return $row->get($headerMap[$column]);
    }

    /**
     * Accepts an Excel date serial, a Y-m-d string, or common regional spellings.
     */
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Excel stores dates as a day count; a bare number is that, not a year.
        if (is_numeric($value)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-M-Y', 'F j, Y'] as $format) {
            $parsed = $this->tryFormat($format, trim((string) $value));

            if ($parsed !== null) {
                return $parsed->startOfDay();
            }
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Combine a clock time with the shift's business date.
     *
     * Excel hands times over as a fraction of a day (0.9166... = 22:00), which
     * is why a bare numeric is treated as a fraction rather than an hour count.
     */
    private function parseDateTime(mixed $value, CarbonImmutable $date): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $fraction = (float) $value;

            // A value of 1 or more is a full datetime serial, not a time.
            if ($fraction >= 1) {
                try {
                    return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($fraction));
                } catch (\Throwable) {
                    return null;
                }
            }

            $seconds = (int) round($fraction * 86400);

            return $date->startOfDay()->addSeconds($seconds);
        }

        $text = trim((string) $value);

        foreach (['H:i:s', 'H:i', 'g:i A', 'g:iA', 'g:i a'] as $format) {
            $parsed = $this->tryFormat($format, $text);

            if ($parsed !== null) {
                return $date->startOfDay()->setTime((int) $parsed->hour, (int) $parsed->minute, (int) $parsed->second);
            }
        }

        // A full datetime in the cell wins over the shift date column. Pinned
        // to the app timezone for the same reason admin corrections are: if the
        // cell carries an offset, Carbon yields an instant in *that* zone and
        // the datetime cast would write its foreign wall clock into a
        // timezone-less column. A naive string is unaffected -- Carbon already
        // parses it in the app timezone, so this is a no-op.
        try {
            return CarbonImmutable::parse($text)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Try one format, treating any failure as "not this format".
     *
     * Carbon 3 raises InvalidFormatException where Carbon 2 returned false, so
     * probing a list of candidate formats has to catch rather than compare.
     * Strict mode is on so that a partial match is rejected instead of being
     * silently padded with today's date.
     */
    private function tryFormat(string $format, string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!'.$format, $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function parseStatus(
        mixed $value,
        ?CarbonImmutable $timeIn,
        ?CarbonImmutable $timeOut,
        ?CarbonImmutable $date,
        array &$errors,
    ): ?AttendanceStatus {
        $text = trim((string) ($value ?? ''));

        if ($text !== '') {
            $slug = Str::snake(strtolower($text));
            $status = AttendanceStatus::tryFrom($slug);

            if ($status === null) {
                $errors[] = "\"{$text}\" is not a recognised attendance status.";
            }

            return $status;
        }

        // Not supplied: derive it the same way the live clock would.
        if ($timeIn === null || $date === null) {
            return AttendanceStatus::Absent;
        }

        if ($timeOut === null) {
            return AttendanceStatus::Incomplete;
        }

        return $this->attendance->resolveClockInStatus($timeIn, $date);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summarise(array $rows): array
    {
        return [
            'total' => count($rows),
            'valid' => count(array_filter($rows, fn ($r) => $r['valid'])),
            'invalid' => count(array_filter($rows, fn ($r) => ! $r['valid'])),
            'will_update' => count(array_filter($rows, fn ($r) => $r['will_update'])),
        ];
    }

    /**
     * Resolve a preview token back to its stored file.
     */
    private function locate(string $token): string
    {
        foreach (['xlsx', 'xls', 'csv'] as $extension) {
            $path = self::DIRECTORY.'/'.$token.'.'.$extension;

            if (Storage::disk(self::DISK)->exists($path)) {
                return $path;
            }
        }

        throw new RuntimeException(
            'That upload is no longer available. Please upload the file again.',
        );
    }
}

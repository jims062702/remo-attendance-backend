<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exports\AttendanceImportTemplateExport;
use App\Http\Controllers\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\AttendanceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel import in two phases, so nothing is written until an admin has seen
 * exactly what will happen.
 *
 *   POST .../preview  upload, validate, return a per-row verdict
 *   POST .../commit   write the rows that passed
 */
class ImportController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AttendanceImportService $import) {}

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file',
                // Both the extension and the reported MIME type are checked:
                // an extension alone is trivially renamed.
                'mimes:xlsx,xls,csv,txt',
                'max:10240', // 10 MB
            ],
        ], [
            'file.mimes' => 'The file must be an Excel workbook (.xlsx, .xls) or a CSV.',
            'file.max' => 'The file may not be larger than 10 MB.',
        ]);

        try {
            $result = $this->import->preview($request->file('file'));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'import.unreadable',
            ], 422);
        }

        return $this->ok(
            $result,
            $result['summary']['valid'] === 0
                ? 'No rows in this file can be imported. Please review the errors below.'
                : sprintf(
                    '%d of %d rows are ready to import.',
                    $result['summary']['valid'],
                    $result['summary']['total'],
                ),
        );
    }

    public function commit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'uuid'],
        ]);

        try {
            $result = $this->import->commit($validated['token'], $request->user());
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'import.expired',
            ], 422);
        }

        return $this->ok(
            $result,
            sprintf(
                'Imported %d new record(s) and updated %d existing record(s).',
                $result['summary']['imported'],
                $result['summary']['updated'],
            ),
        );
    }

    /**
     * A blank workbook with the expected columns and a worked example, so an
     * admin does not have to guess the format.
     */
    public function template(): BinaryFileResponse
    {
        return Excel::download(
            new AttendanceImportTemplateExport,
            'attendance_import_template.xlsx',
        );
    }
}

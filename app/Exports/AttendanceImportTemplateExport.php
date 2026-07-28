<?php

declare(strict_types=1);

namespace App\Exports;

use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * The import template.
 *
 * Ships with two worked example rows, including one that clocks out after
 * midnight, because that is the case admins most often get wrong: the shift
 * date stays the night the shift *started*.
 */
class AttendanceImportTemplateExport implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithTitle
{
    public function title(): string
    {
        return 'Attendance Import';
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Email',
            'Shift Date',
            'Time In',
            'Time Out',
            'Committed Hours',
            'Status',
            'Notes',
        ];
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function array(): array
    {
        $yesterday = CarbonImmutable::now()->subDay()->toDateString();
        $twoDaysAgo = CarbonImmutable::now()->subDays(2)->toDateString();

        return [
            [
                'tasker@example.com',
                $yesterday,
                '22:00',
                '06:00',
                '8',
                'present',
                'Clocked out at 6 AM the following morning.',
            ],
            [
                'another.tasker@example.com',
                $twoDaysAgo,
                '22:35',
                '06:00',
                '8',
                '',
                'Status left blank -- the system works it out (this one is late).',
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        return ['A' => 30, 'B' => 14, 'C' => 12, 'D' => 12, 'E' => 17, 'F' => 14, 'G' => 52];
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();

                $sheet->getStyle('A1:G1')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);

                $sheet->getStyle('A2:G3')->applyFromArray([
                    'font' => ['italic' => true, 'color' => ['rgb' => '6B7280']],
                ]);

                $notes = [
                    '',
                    'HOW TO USE THIS TEMPLATE',
                    'Replace the two grey example rows with your own data. Delete any row you do not need.',
                    '',
                    'Email            Must match a registered tasker exactly.',
                    'Shift Date       The date the shift STARTED, even when it ended the next morning.',
                    '                 A shift running 10:00 PM Monday to 6:00 AM Tuesday has a Shift Date of Monday.',
                    'Time In/Out      24-hour (22:00) or 12-hour (10:00 PM). A time out earlier than the time in',
                    '                 is understood to be the next morning.',
                    'Committed Hours  The hours the tasker committed to. Leave blank if not applicable.',
                    'Status           present, late, incomplete, absent or on_leave. Leave blank to let the',
                    '                 system derive it from the times.',
                    '',
                    'Every row is validated and shown to you for review before anything is saved.',
                    'A row matching an existing record for the same tasker and shift date will UPDATE it.',
                ];

                $row = 5;

                foreach ($notes as $line) {
                    $sheet->setCellValue("A{$row}", $line);
                    $row++;
                }

                $sheet->getStyle('A6')->applyFromArray(['font' => ['bold' => true, 'size' => 12]]);
                $sheet->getStyle('A5:A'.$row)->applyFromArray([
                    'font' => ['name' => 'Consolas', 'size' => 10, 'color' => ['rgb' => '374151']],
                ]);

                $sheet->freezePane('A2');
            },
        ];
    }
}

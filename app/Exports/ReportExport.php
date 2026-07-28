<?php

declare(strict_types=1);

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared layout for every exported report, so a workbook handed to management
 * looks the same whichever report produced it.
 *
 * Sheet structure:
 *
 *   1  REPORT TITLE
 *   2  Generated: ...
 *   3  Date range: ...
 *   4  Filters: ...
 *   5  (blank)
 *   6  column headers          <- frozen, filterable
 *   7+ data
 *   n  TOTAL                   <- when the report defines totals
 *
 * Recording the filters on the sheet itself matters: a report emailed onward
 * is otherwise a table of numbers with no statement of what it covers.
 */
abstract class ReportExport implements FromCollection, WithColumnWidths, WithEvents, WithHeadings, WithMapping, WithStrictNullComparison, WithTitle
{
    /** Rows of report metadata printed above the table. */
    private const META_ROWS = 5;

    /** 1-indexed row holding the column headers. */
    protected const HEADER_ROW = self::META_ROWS + 1;

    /** Populated during collection() so AfterSheet knows where data ends. */
    protected int $rowCount = 0;

    /**
     * Memoised result of rows(). The totals row needs the same data the table
     * was built from; without this every export would run its query twice.
     *
     * @var Collection<int, mixed>|null
     */
    private ?Collection $cachedRows = null;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected readonly array $filters = []) {}

    // ------------------------------------------------------- Subclass contract

    abstract protected function reportTitle(): string;

    /**
     * Column header => width in characters.
     *
     * @return array<string, int>
     */
    abstract protected function columnMap(): array;

    /**
     * @return Collection<int, mixed>
     */
    abstract protected function rows(): Collection;

    /**
     * Values for the totals row, keyed by 1-indexed column number. Return an
     * empty array for reports where a total is meaningless.
     *
     * @return array<int, mixed>
     */
    protected function totals(): array
    {
        return [];
    }

    /**
     * Columns to render as numbers with two decimals, by 1-indexed position.
     *
     * @return array<int, int>
     */
    protected function decimalColumns(): array
    {
        return [];
    }

    // ------------------------------------------------------- Laravel Excel API

    /**
     * @return Collection<int, mixed>
     */
    public function collection(): Collection
    {
        $rows = $this->resolveRows();
        $this->rowCount = $rows->count();

        return $rows;
    }

    /**
     * The report's rows, fetched at most once.
     *
     * @return Collection<int, mixed>
     */
    protected function resolveRows(): Collection
    {
        return $this->cachedRows ??= $this->rows();
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function headings(): array
    {
        return [
            [$this->reportTitle()],
            ['Generated: '.CarbonImmutable::now()->format('F j, Y \a\t g:i A').' ('.config('app.timezone').')'],
            ['Date range: '.$this->describeDateRange()],
            ['Filters: '.$this->describeFilters()],
            [],
            array_keys($this->columnMap()),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        $widths = [];
        $index = 1;

        foreach ($this->columnMap() as $width) {
            $widths[Coordinate::stringFromColumnIndex($index)] = $width;
            $index++;
        }

        return $widths;
    }

    public function title(): string
    {
        return substr($this->reportTitle(), 0, 31);
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(count($this->columnMap()));

                $this->styleTitleBlock($sheet, $lastColumn);
                $this->styleHeaderRow($sheet, $lastColumn);
                $this->styleDataRows($sheet, $lastColumn);
                $this->writeTotals($sheet, $lastColumn);

                // Keep the headers visible while scrolling a long report.
                $sheet->freezePane('A'.(self::HEADER_ROW + 1));
                $sheet->setAutoFilter('A'.self::HEADER_ROW.':'.$lastColumn.self::HEADER_ROW);
            },
        ];
    }

    // ------------------------------------------------------------- Formatting

    private function styleTitleBlock(Worksheet $sheet, string $lastColumn): void
    {
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F2937']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        foreach ([2, 3, 4] as $row) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['italic' => true, 'size' => 10, 'color' => ['rgb' => '6B7280']],
            ]);
        }
    }

    private function styleHeaderRow(Worksheet $sheet, string $lastColumn): void
    {
        $range = 'A'.self::HEADER_ROW.":{$lastColumn}".self::HEADER_ROW;

        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F2937'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '374151']],
            ],
        ]);

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(22);
    }

    private function styleDataRows(Worksheet $sheet, string $lastColumn): void
    {
        if ($this->rowCount === 0) {
            // Say so explicitly -- an empty grid reads as a broken export.
            $sheet->setCellValue('A'.(self::HEADER_ROW + 1), 'No records matched the selected filters.');
            $sheet->mergeCells('A'.(self::HEADER_ROW + 1).":{$lastColumn}".(self::HEADER_ROW + 1));
            $sheet->getStyle('A'.(self::HEADER_ROW + 1))->applyFromArray([
                'font' => ['italic' => true, 'color' => ['rgb' => '9CA3AF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            return;
        }

        $firstRow = self::HEADER_ROW + 1;
        $lastRow = self::HEADER_ROW + $this->rowCount;

        $sheet->getStyle("A{$firstRow}:{$lastColumn}{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Banded rows, which make a wide table far easier to read across.
        for ($row = $firstRow; $row <= $lastRow; $row += 2) {
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9FAFB']],
            ]);
        }

        foreach ($this->decimalColumns() as $columnIndex) {
            $letter = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getStyle("{$letter}{$firstRow}:{$letter}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }
    }

    private function writeTotals(Worksheet $sheet, string $lastColumn): void
    {
        $totals = $this->totals();

        if ($totals === [] || $this->rowCount === 0) {
            return;
        }

        $row = self::HEADER_ROW + $this->rowCount + 1;

        foreach ($totals as $columnIndex => $value) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex).$row, $value);
        }

        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
            'borders' => [
                'top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '1F2937']],
            ],
        ]);

        foreach ($this->decimalColumns() as $columnIndex) {
            $letter = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getStyle("{$letter}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }

    // ---------------------------------------------------------------- Helpers

    protected function describeDateRange(): string
    {
        $from = $this->filters['from'] ?? null;
        $to = $this->filters['to'] ?? null;

        if ($from === null && $to === null) {
            return 'All dates';
        }

        $format = static fn (?string $d): string => $d === null
            ? '...'
            : CarbonImmutable::parse($d)->format('F j, Y');

        return $format($from).' - '.$format($to);
    }

    protected function describeFilters(): string
    {
        $parts = [];

        foreach (['user_id' => 'Tasker', 'status' => 'Status', 'task_status' => 'Task status', 'search' => 'Search'] as $key => $label) {
            if (! empty($this->filters[$key])) {
                $parts[] = $label.': '.$this->resolveFilterLabel($key, (string) $this->filters[$key]);
            }
        }

        return $parts === [] ? 'None' : implode('   |   ', $parts);
    }

    /**
     * Turn a raw filter value into something a reader recognises -- a tasker's
     * name rather than "user_id: 14".
     */
    private function resolveFilterLabel(string $key, string $value): string
    {
        if ($key !== 'user_id') {
            return str_replace('_', ' ', ucfirst($value));
        }

        return \App\Models\User::withTrashed()->find($value)?->name ?? $value;
    }

    /**
     * The UI convention for an absent optional value.
     */
    protected function na(mixed $value): string
    {
        return ($value === null || $value === '') ? 'N/A' : (string) $value;
    }
}

<?php

namespace App\Services\Library;

use App\Models\Category;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Language\LanguageToFont;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LibraryReportExport
{
    public function download(array $data, string $format)
    {
        $data['generated'] = now()->format('d M Y, h:i A').' (Dhaka)';
        $data['filterText'] = $this->filterText($data['filters']);
        $filename = $data['report'].'-'.now()->format('Ymd-His').'.'.$format;
        $data['rows'] = $data['rows'] instanceof Builder ? $data['rows']->lazy(250) : $data['rows'];

        if ($format === 'pdf') {
            return response($this->pdf($data), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        $workbook = $this->workbook($data);

        return response()->streamDownload(function () use ($workbook) {
            try {
                (new Xlsx($workbook))->save('php://output');
            } finally {
                $workbook->disconnectWorksheets();
            }
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function filterText(array $filters): string
    {
        $labels = [];
        $fields = [
            'from' => 'From', 'to' => 'Through', 'year' => 'Year', 'scope' => 'Loan scope',
            'method' => 'Collection method', 'status' => 'Member status', 'fine' => 'Fine status', 'q' => 'Search',
        ];
        if (in_array($filters['status'] ?? null, ['on_loan', 'returned'], true)) {
            $fields['status'] = 'Loan status';
            $filters['status'] = $filters['status'] === 'on_loan' ? 'On loan' : 'Returned';
        }
        foreach ($fields as $key => $label) {
            if (isset($filters[$key])) {
                $labels[] = $label.' '.$filters[$key];
            }
        }
        if (isset($filters['category'])) {
            $name = Category::find($filters['category'])?->title_bn;
            $labels[] = 'Category '.$name;
        }

        return $labels ? implode(' · ', $labels) : 'All applicable records';
    }

    private function pdf(array $data): string
    {
        $temp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($temp, 0700);
        $pdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'L',
            'margin_top' => 20, 'margin_bottom' => 17, 'margin_left' => 10, 'margin_right' => 10,
            'tempDir' => $temp,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], public_path('fonts')],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'nikosh' => ['R' => 'Nikosh.ttf', 'useOTL' => 0xFF],
            ],
            'autoScriptToLang' => true, 'autoLangToFont' => true,
            'languageToFont' => new class extends LanguageToFont
            {
                public function getLanguageOptions($llcc, $adobeCJK)
                {
                    if (str_starts_with((string) $llcc, 'bn')) {
                        return [false, 'nikosh'];
                    }

                    return parent::getLanguageOptions($llcc, $adobeCJK);
                }
            },
            'default_font' => 'dejavusans', 'default_font_size' => 9,
        ]);
        $pdf->SetTitle($data['title']);
        $pdf->SetAuthor('Bangladesh Public Service Commission');
        $pdf->SetHTMLHeader('<div style="color:#334e78;font-size:9pt;border-bottom:1px solid #aab8ce">Bangladesh Public Service Commission (BPSC) | Library Management System</div>');
        $pdf->SetHTMLFooter('<table style="width:100%;border:0;font-size:8pt;color:#516177"><tr><td style="border:0;padding:0;text-align:left">Software Developed By: <strong>IT Section, BPSC</strong></td><td style="border:0;padding:0;text-align:right">Page {PAGENO} of {nbpg}</td></tr></table>');
        $pdf->WriteHTML('table{border-collapse:collapse;width:100%}th,td{border:1px solid #b5c3d5;padding:5px;vertical-align:top}th{background:#e9eff8;color:#18345b}h1{font-size:17pt;color:#18345b}.numeric{text-align:right}.text-center{text-align:center}.text-start{text-align:left}.align-middle{vertical-align:middle}.fw-bold{font-weight:bold}.bg-green-lt{color:#166534;background:#dcfce7}.bg-red-lt{color:#991b1b;background:#fee2e2}.bg-blue-lt{color:#1e40af;background:#dbeafe}.bengali-value{font-size:11pt}', HTMLParserMode::HEADER_CSS);
        $pdf->WriteHTML(view('reports.pdf-heading', $data)->render());
        $weights = [];
        foreach (array_keys($data['columns']) as $key) {
            $weights[$key] = match ($key) {
                'book', 'email' => 2.8,
                'category', 'english', 'member', 'designation' => 1.8,
                'issued_on', 'added', 'joined', 'due', 'returned_on', 'reminder' => 1.5,
                'fine' => .75,
                default => 1,
            };
        }
        $totalWeight = array_sum($weights);
        $widths = array_map(fn ($weight) => round(100 * $weight / $totalWeight, 2).'%', $weights);
        $table = ['columns' => $data['columns'], 'widths' => $widths, 'report' => $data['report'], 'pdf' => true];
        $pending = [];
        foreach ($data['rows']->chunk(100) as $rows) {
            if ($pending !== []) {
                $pdf->WriteHTML(view('reports.table', $table + ['rows' => $pending])->render());
            }
            $pending = $rows;
        }
        $pdf->WriteHTML(view('reports.table', $table + ['rows' => $pending, 'totals' => $data['totals'] ?? null])->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    private function workbook(array $data): Spreadsheet
    {
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet()->setTitle('Report');
        $last = Coordinate::stringFromColumnIndex(count($data['columns']));
        $metadata = [
            $data['title'],
            $data['filterText'],
            'Generated '.$data['generated'],
            implode(' · ', array_map(fn ($label, $value) => $label.' '.$value, array_keys($data['summary']), $data['summary'])),
            $data['note'],
        ];
        foreach ($metadata as $index => $text) {
            $row = $index + 1;
            $sheet->mergeCells('A'.$row.':'.$last.$row);
            $sheet->setCellValueExplicit('A'.$row, $text, DataType::TYPE_STRING);
        }
        $sheet->getRowDimension(5)->setRowHeight(30);
        $sheet->getStyle('A5')->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A7:'.$last.'7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF294875']],
        ]);
        $fields = array_keys($data['columns']);
        foreach ($fields as $index => $key) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit($column.'7', $data['columns'][$key], DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth(in_array($key, ['book', 'member', 'email', 'category', 'title', 'authors'], true) ? 34 : 23);
        }
        $rowNumber = 8;
        foreach ($data['rows'] as $row) {
            foreach ($fields as $index => $key) {
                $value = $row->{$key} ?? null;
                if ($value === null) {
                    continue;
                }

                $cell = [$index + 1, $rowNumber];
                if (LibraryReports::numeric($key)) {
                    $sheet->setCellValueExplicit($cell, LibraryReports::money($key) ? (float) $value : (int) $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
            $rowNumber++;
        }
        $lastDataRow = max(7, $rowNumber - 1);
        if (isset($data['totals'])) {
            foreach ($fields as $index => $key) {
                $value = $data['totals'][$key] ?? null;
                if ($value !== null) {
                    $sheet->setCellValueExplicit([$index + 1, $rowNumber], $value, LibraryReports::numeric($key) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                }
            }
            $sheet->getStyle('A'.$rowNumber.':'.$last.$rowNumber)->getFont()->setBold(true);
        }
        $lastRow = isset($data['totals']) ? $rowNumber : $lastDataRow;
        foreach ($fields as $index => $key) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            if (LibraryReports::money($key) && $lastRow > 7) {
                $sheet->getStyle($col.'8:'.$col.$lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
            }
            if (LibraryReports::centered($data['report'], $key)) {
                $sheet->getStyle($col.'7:'.$col.$lastRow)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            }
        }
        $sheet->getStyle('A1:'.$last.$lastRow)->getFont()->setName('Nirmala UI');
        $sheet->freezePane('A8')->setAutoFilter('A7:'.$last.$lastDataRow);

        return $workbook;
    }
}

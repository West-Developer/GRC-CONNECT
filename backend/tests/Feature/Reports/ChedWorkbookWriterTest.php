<?php

namespace Tests\Feature\Reports;

use App\Domain\Reports\ChedReport;
use App\Support\Reports\ChedReportHeader;
use App\Support\Reports\ChedWorkbookWriter;
use DOMDocument;
use Tests\TestCase;
use ZipArchive;

final class ChedWorkbookWriterTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    private function target(): string
    {
        return $this->files[] = tempnam(sys_get_temp_dir(), 'ched-test-').'.xlsx';
    }

    private function header(): ChedReportHeader
    {
        return new ChedReportHeader(
            schoolName: 'Global Reciprocal Colleges',
            schoolAddress: '454 GRC Building, Caloocan',
            termLabel: '2nd Term, AY 2025-2026',
            semesterLabel: '2nd Semester',
            schoolYear: '2025-2026',
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function chedRow(int $no, string $surname = 'Santos', string $gender = 'F'): array
    {
        return [
            'no' => $no, 'program' => 'Bachelor of Science in Information Technology', 'major' => '',
            'student_number' => sprintf('2025-08-%05d', $no), 'first_name' => 'Ana', 'middle_name' => 'D.',
            'surname' => $surname, 'name_extension' => '', 'citizenship' => 'Filipino', 'gender' => $gender,
            'year_level' => 2, 'subjects' => 'Intro to Computing (3), Fitness (2)', 'units' => '5', 'remarks' => '',
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function nstpRow(int $no, string $gender = 'FEMALE', string $surname = 'Reyes'): array
    {
        return [
            'no' => $no, 'serial_no' => '', 'surname' => $surname, 'first_name' => 'Maria', 'middle_name' => 'L.',
            'course' => 'Bachelor of Science in Information Technology', 'gender' => $gender, 'birthdate' => '11/25/1992',
            'street_barangay' => '12 Rizal St., Brgy. San Jose', 'town_city' => 'Caloocan City', 'province' => 'Metro Manila',
            'contact_number' => '09171234567', 'email' => 'maria@grc.test',
        ];
    }

    /**
     * @return array{package: ZipArchive, sheet: string, cells: array<string, string>}
     */
    private function open(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the file is not a readable zip package');

        foreach ($this->xmlParts($zip) as $name) {
            $dom = new DOMDocument;
            $this->assertTrue(@$dom->loadXML((string) $zip->getFromName($name)), "{$name} is not well-formed XML");
        }

        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');

        return ['package' => $zip, 'sheet' => $sheet, 'cells' => $this->cells($zip, $sheet)];
    }

    /**
     * @return list<string>
     */
    private function xmlParts(ZipArchive $zip): array
    {
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, string> cell reference => text
     */
    private function cells(ZipArchive $zip, string $sheet): array
    {
        $shared = [];
        if (($sst = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            preg_match_all('#<si>(.*?)</si>#s', $sst, $items);
            foreach ($items[1] as $item) {
                preg_match_all('#<t[^>]*>(.*?)</t>#s', $item, $texts);
                $shared[] = html_entity_decode(implode('', $texts[1]), ENT_QUOTES | ENT_XML1);
            }
        }

        $cells = [];
        preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $sheet, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match('/\br="([A-Z]+\d+)"/', $match[1], $ref);
            $body = $match[2] ?? '';
            if (str_contains($match[1], 't="inlineStr"')) {
                preg_match_all('#<t[^>]*>(.*?)</t>#s', $body, $texts);
                $cells[$ref[1]] = html_entity_decode(implode('', $texts[1]), ENT_QUOTES | ENT_XML1);
            } elseif (preg_match('#<v>(.*?)</v>#s', $body, $v) === 1) {
                $cells[$ref[1]] = str_contains($match[1], 't="s"') ? $shared[(int) $v[1]] : $v[1];
            }
        }

        return $cells;
    }

    private function writer(): ChedWorkbookWriter
    {
        return $this->app->make(ChedWorkbookWriter::class);
    }

    // ---- CHED Enrollment List ---------------------------------------------------------------

    public function test_the_enrollment_list_fills_the_template_header_and_rows(): void
    {
        $path = $this->target();
        $this->writer()->writeList(ChedReport::EnrollmentList, [$this->chedRow(1, 'Santos'), $this->chedRow(2, 'Zamora', 'M')], $this->header(), $path);

        $book = $this->open($path);
        $cells = $book['cells'];

        $this->assertSame('Global Reciprocal Colleges', $cells['B1']);
        $this->assertSame('454 GRC Building, Caloocan', trim($cells['B2']));
        $this->assertSame('2nd Term, AY 2025-2026', $cells['B3']);
        // The template's own header texts and guidance rows are still there.
        $this->assertSame('STUDENT NUMBER ', $cells['D5']);
        $this->assertSame('Ex. Filipino', $cells['I6']);

        $this->assertSame('1', $cells['A8']);
        $this->assertSame('Bachelor of Science in Information Technology', $cells['B8']);
        $this->assertSame('2025-08-00001', $cells['D8']);
        $this->assertSame('Santos', $cells['G8']);
        $this->assertSame('F', $cells['J8']);
        $this->assertSame('2', $cells['K8']);
        $this->assertSame('Intro to Computing (3), Fitness (2)', $cells['L8']);
        $this->assertSame('5', $cells['M8']);
        $this->assertSame('Zamora', $cells['G9']);
        $this->assertSame('M', $cells['J9']);
        $this->assertArrayNotHasKey('A10', $cells, 'no leftover numbered blank rows');
    }

    public function test_the_enrollment_list_keeps_the_template_layout(): void
    {
        $path = $this->target();
        $this->writer()->writeList(ChedReport::EnrollmentList, [$this->chedRow(1)], $this->header(), $path);

        $sheet = $this->open($path)['sheet'];

        $this->assertStringContainsString('<mergeCell ref="B1:N1"/>', $sheet);
        $this->assertStringContainsString('<mergeCell ref="E7:G7"/>', $sheet);
        $this->assertStringContainsString('<dimension ref="A1:N8"/>', $sheet);
        // The number column keeps the template's style; every other data cell is the template's style for
        // its column (same borders and fill) with the alignment of a filled cell.
        $this->assertMatchesRegularExpression('/<c r="A8" s="2"/', $sheet);
        $book = $this->open($path);
        $template = new ZipArchive;
        $this->assertTrue($template->open(base_path('resources/ched-templates/ched-enrollment-list.xlsx')) === true);
        foreach (['B8' => 1, 'N8' => 17] as $ref => $templateStyle) {
            $this->assertSame(
                $this->attribute($this->xfAt($template, $templateStyle), 'borderId'),
                $this->attribute($this->styleOf($book['package'], $sheet, $ref), 'borderId'),
                "{$ref} keeps the template's borders",
            );
            $this->assertSame(
                $this->attribute($this->xfAt($template, $templateStyle), 'fillId'),
                $this->attribute($this->styleOf($book['package'], $sheet, $ref), 'fillId'),
                "{$ref} keeps the template's fill",
            );
        }
    }

    public function test_an_empty_list_still_has_one_blank_row(): void
    {
        $path = $this->target();
        $this->writer()->writeList(ChedReport::EnrollmentList, [], $this->header(), $path);

        $sheet = $this->open($path)['sheet'];

        $this->assertStringContainsString('<row r="8"', $sheet);
        $this->assertStringNotContainsString('<row r="9"', $sheet);
    }

    public function test_text_is_escaped_and_control_characters_are_dropped(): void
    {
        $path = $this->target();
        $row = $this->chedRow(1, "O'Brien & <Sons> \"Q\"");
        $row['first_name'] = "Ana\x01\x0B Marie";
        $this->writer()->writeList(ChedReport::EnrollmentList, [$row], $this->header(), $path);

        $cells = $this->open($path)['cells'];

        $this->assertSame("O'Brien & <Sons> \"Q\"", $cells['G8']);
        $this->assertSame('Ana Marie', $cells['E8']);
    }

    public function test_the_calculation_chain_is_removed_so_excel_rebuilds_it(): void
    {
        $path = $this->target();
        $this->writer()->writeList(ChedReport::NstpEnrollment, [$this->nstpRow(1)], $this->header(), $path);

        $zip = $this->open($path)['package'];

        $this->assertFalse($zip->locateName('xl/calcChain.xml'));
        $this->assertStringNotContainsString('calcChain', (string) $zip->getFromName('[Content_Types].xml'));
        $this->assertStringNotContainsString('calcChain', (string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $this->assertStringContainsString('fullCalcOnLoad="1"', (string) $zip->getFromName('xl/workbook.xml'));
    }

    // ---- NSTP Form 2-B ---------------------------------------------------------------------

    public function test_nstp_enrollment_fills_the_header_rows_and_moves_the_footer_down(): void
    {
        $path = $this->target();
        $rows = [$this->nstpRow(1, 'FEMALE', 'Reyes'), $this->nstpRow(2, 'MALE', 'Santos'), $this->nstpRow(3, 'FEMALE', 'Uy')];
        $this->writer()->writeList(ChedReport::NstpEnrollment, $rows, $this->header(), $path);

        $book = $this->open($path);
        $cells = $book['cells'];

        $this->assertSame('LIST OF NSTP ENROLLMENT FOR SERIAL NUMBER', $cells['A6']);
        $this->assertSame('2nd Semester :    School Year :   2025-2026', trim($cells['A7']));
        $this->assertSame('Global Reciprocal Colleges', $cells['C9']);
        $this->assertSame('454 GRC Building, Caloocan', $cells['C10']);
        $this->assertSame('CWTS', $cells['L10']);

        $this->assertSame('1', $cells['A14']);
        $this->assertSame('', $cells['B14'] ?? '');
        $this->assertSame('Reyes', $cells['C14']);
        $this->assertSame('Maria', $cells['D14']);
        $this->assertSame('FEMALE', $cells['G14']);
        $this->assertSame('11/25/1992', $cells['H14']);
        $this->assertSame('09171234567', $cells['L14']);
        $this->assertSame('maria@grc.test', $cells['M14']);
        $this->assertSame('Uy', $cells['C16']);

        // The footer (Sub Total / Grand Total / signatures) now starts right after the data.
        $this->assertSame('Sub Total:', $cells['A17']);
        $this->assertSame('Grand Total:', $cells['C19']);
        $this->assertSame('Prepared by:', $cells['A22']);
        $this->assertSame('HEI Coordinator', $cells['A26']);
        $this->assertArrayNotHasKey('A1247', $cells);

        $sheet = $book['sheet'];
        $this->assertStringContainsString('<mergeCell ref="A17:B18"/>', $sheet);
        $this->assertStringContainsString('<mergeCell ref="C19:C20"/>', $sheet);
        $this->assertStringContainsString('<mergeCell ref="A2:M2"/>', $sheet);
        $this->assertStringContainsString('<dimension ref="A2:M26"/>', $sheet);
    }

    public function test_the_footer_counts_follow_the_rows(): void
    {
        $path = $this->target();
        $rows = [$this->nstpRow(1, 'FEMALE'), $this->nstpRow(2, 'MALE'), $this->nstpRow(3, 'FEMALE')];
        $this->writer()->writeList(ChedReport::NstpEnrollment, $rows, $this->header(), $path);

        $sheet = $this->open($path)['sheet'];

        $this->assertMatchesRegularExpression('#<f>COUNTIF\(G14:G16,"MALE"\)</f><v>1</v>#', $sheet);
        $this->assertMatchesRegularExpression('#<f>COUNTIF\(G14:G16,"FEMALE"\)</f><v>2</v>#', $sheet);
        $this->assertStringNotContainsString('G1245', $sheet);
    }

    public function test_the_logo_and_the_page_setup_survive(): void
    {
        $path = $this->target();
        $this->writer()->writeList(ChedReport::NstpGraduates, [$this->nstpRow(1)], $this->header(), $path);

        $book = $this->open($path);

        $this->assertNotFalse($book['package']->locateName('xl/media/image1.jpeg'));
        $this->assertNotFalse($book['package']->locateName('xl/drawings/drawing1.xml'));
        $this->assertStringContainsString('<drawing r:id="rId2"/>', $book['sheet']);
        $this->assertStringContainsString('<pageSetup paperSize="5"', $book['sheet']);
        $this->assertSame('LIST OF NSTP GRADUATES FOR SERIAL NUMBER', $book['cells']['A6']);
    }

    public function test_the_logo_keeps_its_own_size_and_is_anchored_beside_the_centred_title_after_the_columns_widen(): void
    {
        foreach ([ChedReport::NstpEnrollment, ChedReport::NstpGraduates] as $report) {
            $path = $this->target();
            $this->writer()->writeList($report, [$this->nstpRow(1)], $this->header(), $path);
            $zip = $this->open($path)['package'];
            $drawing = (string) $zip->getFromName('xl/drawings/drawing1.xml');

            // Anchored at one cell with the picture's own size (it was stretched between two cells before).
            $this->assertStringContainsString('<xdr:oneCellAnchor>', $drawing);
            $this->assertStringNotContainsString('<xdr:twoCellAnchor', $drawing);
            $this->assertStringContainsString('<xdr:ext cx="647699" cy="609600"/>', $drawing);
            $this->assertStringContainsString('r:embed="rId1"', $drawing);
            // The table is wider than CHED's, so the title's centre moved right and the logo with it.
            $this->assertSame(1, preg_match('#<xdr:from><xdr:col>(\d+)</xdr:col>#', $drawing, $from));
            $this->assertGreaterThanOrEqual(4, (int) $from[1], "{$report->value}: the logo moved right with the title");
        }
    }

    public function test_the_graduates_form_moves_its_own_footer(): void
    {
        $path = $this->target();
        $this->writer()->writeList(ChedReport::NstpGraduates, [$this->nstpRow(1), $this->nstpRow(2)], $this->header(), $path);

        $book = $this->open($path);

        $this->assertSame('Sub Total:', $book['cells']['A16']);
        $this->assertStringContainsString('<mergeCell ref="A16:B17"/>', $book['sheet']);
        $this->assertStringContainsString('<mergeCell ref="C18:C19"/>', $book['sheet']);
    }

    public function test_a_list_longer_than_the_template_gets_more_rows_and_a_wider_number_column(): void
    {
        $path = $this->target();
        $rows = [];
        for ($i = 1; $i <= 1300; $i++) {
            $rows[] = $this->nstpRow($i, $i % 2 === 0 ? 'MALE' : 'FEMALE', "Surname{$i}");
        }
        $this->writer()->writeList(ChedReport::NstpEnrollment, $rows, $this->header(), $path);

        $book = $this->open($path);

        $this->assertSame('Surname1300', $book['cells']['C1313']);
        $this->assertSame('1300', $book['cells']['A1313']);
        $this->assertSame('Sub Total:', $book['cells']['A1314']);
        $this->assertMatchesRegularExpression('#COUNTIF\(G14:G1313,"MALE"\)</f><v>650</v>#', $book['sheet']);
        $this->assertMatchesRegularExpression('#<col min="1" max="1" width="(?:[5-9]|\d{2,})#', $book['sheet']);
    }

    public function test_an_empty_nstp_list_keeps_one_blank_row_before_the_footer(): void
    {
        $path = $this->target();
        $this->writer()->writeList(ChedReport::NstpEnrollment, [], $this->header(), $path);

        $cells = $this->open($path)['cells'];

        $this->assertSame('Sub Total:', $cells['A15']);
    }

    // ---- NSTP Form 2-A ---------------------------------------------------------------------

    public function test_form_2a_gets_the_school_row_the_year_and_the_totals(): void
    {
        $none = ['m' => 0, 'f' => 0];
        $summary = [
            'campus' => 'Global Reciprocal Colleges',
            'classification' => 'Private',
            'academic_year' => '2025-2026',
            'enrollment' => [
                '1st' => ['rotc' => $none, 'cwts' => ['m' => 2, 'f' => 1], 'lts' => $none],
                '2nd' => ['rotc' => $none, 'cwts' => ['m' => 1, 'f' => 2], 'lts' => $none],
                'summer' => ['rotc' => $none, 'cwts' => $none, 'lts' => $none],
            ],
            'graduates' => ['rotc' => $none, 'cwts' => ['m' => 4, 'f' => 5], 'lts' => $none],
            'missing_gender' => 0,
        ];
        $path = $this->target();

        $this->writer()->writeSummary($summary, $path);

        $book = $this->open($path);
        $cells = $book['cells'];
        $this->assertSame('Academic Year: 2025-2026', $cells['A7']);
        $this->assertSame('Global Reciprocal Colleges', $cells['A15']);
        $this->assertSame('Private', $cells['B15']);
        // 1st Sem: ROTC C-D, CWTS E-F, LTS G-H. 2nd Sem: I-N. Summer: O-T. Graduates: U-Z.
        $this->assertSame(['0', '0', '2', '1', '0', '0'], array_map(fn (string $c): string => $cells[$c.'15'], ['C', 'D', 'E', 'F', 'G', 'H']));
        $this->assertSame(['0', '0', '1', '2', '0', '0'], array_map(fn (string $c): string => $cells[$c.'15'], ['I', 'J', 'K', 'L', 'M', 'N']));
        $this->assertSame(['0', '0', '0', '0', '0', '0'], array_map(fn (string $c): string => $cells[$c.'15'], ['O', 'P', 'Q', 'R', 'S', 'T']));
        $this->assertSame(['0', '0', '4', '5', '0', '0'], array_map(fn (string $c): string => $cells[$c.'15'], ['U', 'V', 'W', 'X', 'Y', 'Z']));
        // The TOTAL row's cached values match, and the signatories the template carries are intact.
        $this->assertSame('1', $cells['B30']);
        $this->assertSame('2', $cells['E30']);
        $this->assertSame('5', $cells['X30']);
        $this->assertSame('MARIBEL B. GARCIA', $cells['Q34']);
        $this->assertNotFalse($book['package']->locateName('xl/media/image1.jpeg'));
        $this->assertFalse($book['package']->locateName('xl/calcChain.xml'));
    }

    // ---- how a filled file looks in Excel (owner's review, 2026-10-15) --------------------------

    /** The `<xf>` of the cell with this reference, from the package's styles part. */
    private function styleOf(ZipArchive $zip, string $sheet, string $ref): string
    {
        $this->assertSame(1, preg_match('#<c r="'.$ref.'"(?: s="(\d+)")?#', $sheet, $cell), "cell {$ref} not found");

        return $this->xfAt($zip, (int) ($cell[1] ?? 0));
    }

    private function xfAt(ZipArchive $zip, int $index): string
    {
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $this->assertSame(1, preg_match('#<cellXfs\b[^>]*>(.*?)</cellXfs>#s', $styles, $block));
        preg_match_all('#<xf\b[^>]*?(?:/>|>.*?</xf>)#s', $block[1], $xfs);

        return $xfs[0][$index];
    }

    private function attribute(string $xf, string $name): string
    {
        $this->assertSame(1, preg_match('#\b'.$name.'="([^"]*)"#', $xf, $value));

        return $value[1];
    }

    /** @return array{name: string, size: float} */
    private function fontOf(ZipArchive $zip, string $xf): array
    {
        $this->assertSame(1, preg_match('#fontId="(\d+)"#', $xf, $id));
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $this->assertSame(1, preg_match('#<fonts\b[^>]*>(.*?)</fonts>#s', $styles, $block));
        preg_match_all('#<font>.*?</font>|<font/>#s', $block[1], $fonts);
        $font = $fonts[0][(int) $id[1]];
        preg_match('#<name val="([^"]+)"#', $font, $name);
        preg_match('#<sz val="([0-9.]+)"#', $font, $size);

        return ['name' => $name[1] ?? '', 'size' => (float) ($size[1] ?? 0)];
    }

    private function columnWidth(string $sheet, int $index): float
    {
        preg_match_all('#<col min="(\d+)" max="(\d+)" width="([0-9.]+)"#', $sheet, $cols, PREG_SET_ORDER);
        foreach ($cols as $col) {
            if ((int) $col[1] <= $index && $index <= (int) $col[2]) {
                return (float) $col[3];
            }
        }

        $this->fail("no width for column {$index}");
    }

    public function test_the_nstp_list_data_cells_use_the_same_readable_font_as_the_headings_not_the_workbook_default(): void
    {
        foreach ([ChedReport::NstpEnrollment, ChedReport::NstpGraduates] as $report) {
            $path = $this->target();
            (new ChedWorkbookWriter)->writeList($report, [$this->nstpRow(1)], $this->header(), $path);
            ['package' => $zip, 'sheet' => $sheet] = $this->open($path);

            foreach (['B14', 'C14', 'F14', 'G14', 'H14', 'I14', 'J14', 'K14', 'L14', 'M14'] as $ref) {
                $font = $this->fontOf($zip, $this->styleOf($zip, $sheet, $ref));
                $this->assertSame('Calibri', $font['name'], "{$report->value} {$ref} font");
                $this->assertSame(10.0, $font['size'], "{$report->value} {$ref} size");
            }
        }
    }

    public function test_the_nstp_columns_are_wide_enough_for_a_whole_program_name_an_address_and_the_word_female(): void
    {
        foreach ([ChedReport::NstpEnrollment, ChedReport::NstpGraduates] as $report) {
            $path = $this->target();
            (new ChedWorkbookWriter)->writeList($report, [$this->nstpRow(1)], $this->header(), $path);
            ['sheet' => $sheet] = $this->open($path);

            $this->assertGreaterThanOrEqual(46, $this->columnWidth($sheet, 6), 'Course/Program');
            $this->assertGreaterThanOrEqual(9, $this->columnWidth($sheet, 7), 'Gender');
            $this->assertGreaterThanOrEqual(36, $this->columnWidth($sheet, 9), 'Street/Barangay');
            $this->assertGreaterThanOrEqual(22, $this->columnWidth($sheet, 10), 'Town/City');
            $this->assertGreaterThanOrEqual(16, $this->columnWidth($sheet, 4), 'First name');
            $this->assertGreaterThanOrEqual(30, $this->columnWidth($sheet, 13), 'Email');
        }
    }

    public function test_the_enrollment_list_columns_fit_a_long_program_name_and_the_subjects_wrap_inside_their_cell(): void
    {
        $path = $this->target();
        (new ChedWorkbookWriter)->writeList(ChedReport::EnrollmentList, [$this->chedRow(1)], $this->header(), $path);
        ['package' => $zip, 'sheet' => $sheet] = $this->open($path);

        $this->assertGreaterThanOrEqual(46, $this->columnWidth($sheet, 2), 'Programs');
        $this->assertGreaterThanOrEqual(60, $this->columnWidth($sheet, 12), 'Subjects enrolled');
        $this->assertStringContainsString('wrapText="1"', $this->styleOf($zip, $sheet, 'L8'));
        // The year level and gender are centred under their headings.
        $this->assertStringContainsString('horizontal="center"', $this->styleOf($zip, $sheet, 'K8'));
        $this->assertStringContainsString('horizontal="center"', $this->styleOf($zip, $sheet, 'J8'));
    }

    public function test_a_long_subject_list_makes_its_row_taller_and_a_short_one_does_not(): void
    {
        $long = $this->chedRow(1);
        $long['subjects'] = implode(', ', array_map(static fn (int $n): string => "Professional Subject Number {$n} ({$n})", range(1, 14)));
        $short = $this->chedRow(2);

        $path = $this->target();
        (new ChedWorkbookWriter)->writeList(ChedReport::EnrollmentList, [$long, $short], $this->header(), $path);
        ['sheet' => $sheet] = $this->open($path);

        $this->assertSame(1, preg_match('#<row r="8"[^>]*\bht="([0-9.]+)"[^>]*customHeight="1"#', $sheet, $tall), 'the long row has a fixed, taller height');
        $this->assertSame(1, preg_match('#<row r="9"[^>]*\bht="([0-9.]+)"#', $sheet, $normal));
        $this->assertGreaterThan((float) $normal[1] * 2, (float) $tall[1]);
        $this->assertLessThanOrEqual(409.5, (float) $tall[1], 'Excel allows at most 409.5 points');
    }
}

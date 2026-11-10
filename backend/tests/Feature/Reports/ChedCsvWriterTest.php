<?php

namespace Tests\Feature\Reports;

use App\Domain\Reports\ChedReport;
use App\Support\Reports\ChedCsvWriter;
use Tests\TestCase;

final class ChedCsvWriterTest extends TestCase
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
        return $this->files[] = tempnam(sys_get_temp_dir(), 'ched-csv-');
    }

    /**
     * @return list<list<string>>
     */
    private function read(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $raw, 'a UTF-8 byte-order mark so Excel reads accents');

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($raw, 3));
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function test_a_list_has_the_templates_column_headers_then_the_rows(): void
    {
        $path = $this->target();

        (new ChedCsvWriter)->writeList(ChedReport::NstpEnrollment, [[
            'no' => 1, 'serial_no' => '', 'surname' => 'Reyes', 'first_name' => 'Maria', 'middle_name' => 'L.',
            'course' => 'Bachelor of Science in Information Technology', 'gender' => 'FEMALE', 'birthdate' => '11/25/1992',
            'street_barangay' => '12 Rizal St., Brgy. San Jose', 'town_city' => 'Caloocan City', 'province' => 'Metro Manila',
            'contact_number' => '09171234567', 'email' => 'maria@grc.test',
        ]], $path);

        $rows = $this->read($path);

        $this->assertSame([
            'No.', 'Serial No.', 'Surname', 'First Name', 'Middle Name', 'Course/Program (Write in full)', 'Gender',
            'Birthdate (ex. 11/25/1992)', 'Street/Barangay Address', 'Town/City Address', 'Provincial Address',
            'Contact Number Telephone/Mobile', 'Email Address',
        ], $rows[0]);
        $this->assertSame('1', $rows[1][0]);
        $this->assertSame('12 Rizal St., Brgy. San Jose', $rows[1][8], 'a comma inside a value stays in one field');
        $this->assertSame('09171234567', $rows[1][11], 'a leading zero is kept');
        $this->assertCount(2, $rows);
    }

    public function test_the_enrollment_list_headers_are_the_ched_templates(): void
    {
        $path = $this->target();

        (new ChedCsvWriter)->writeList(ChedReport::EnrollmentList, [], $path);

        $this->assertSame([
            'No.', 'Programs', 'Major', 'STUDENT NUMBER', 'FIRST NAME', 'MIDDLE NAME', 'SURNAME', 'Name Extension',
            'Citizenship', 'GENDER', 'Year Level', 'SUBJECTS ENROLLED FOLLOWED BY UNITS', 'NO. OF UNITS', 'REMARKS',
        ], $this->read($path)[0]);
    }

    public function test_text_a_spreadsheet_would_run_as_a_formula_is_neutralised(): void
    {
        $path = $this->target();
        $row = array_fill_keys(array_column(ChedReport::NstpEnrollment->columns(), 'key'), '');
        $row['surname'] = '=HYPERLINK("http://evil","x")';
        $row['first_name'] = '@SUM(A1)';
        $row['middle_name'] = '+cmd|calc';
        $row['contact_number'] = '+639171234567';
        $row['street_barangay'] = "\tTab first";

        (new ChedCsvWriter)->writeList(ChedReport::NstpEnrollment, [$row], $path);

        $data = $this->read($path)[1];
        $this->assertSame('\'=HYPERLINK("http://evil","x")', $data[2]);
        $this->assertSame("'@SUM(A1)", $data[3]);
        $this->assertSame("'+cmd|calc", $data[4]);
        $this->assertSame('+639171234567', $data[11], 'a phone number is not a formula');
        $this->assertSame("'\tTab first", $data[8]);
    }

    public function test_the_summary_is_one_header_row_the_school_and_a_total(): void
    {
        $none = ['m' => 0, 'f' => 0];
        $path = $this->target();

        (new ChedCsvWriter)->writeSummary([
            'campus' => 'Global Reciprocal Colleges', 'classification' => 'Private', 'academic_year' => '2025-2026',
            'enrollment' => [
                '1st' => ['rotc' => $none, 'cwts' => ['m' => 2, 'f' => 1], 'lts' => $none],
                '2nd' => ['rotc' => $none, 'cwts' => ['m' => 1, 'f' => 2], 'lts' => $none],
                'summer' => ['rotc' => $none, 'cwts' => $none, 'lts' => $none],
            ],
            'graduates' => ['rotc' => $none, 'cwts' => ['m' => 4, 'f' => 5], 'lts' => $none],
        ], $path);

        $rows = $this->read($path);

        $this->assertCount(3, $rows);
        $this->assertCount(26, $rows[0]);
        $this->assertSame('NAME OF HEI/CAMPUS', $rows[0][0]);
        $this->assertSame('Classification (Private/Public)', $rows[0][1]);
        $this->assertSame('Enrollment 1st Sem ROTC M', $rows[0][2]);
        $this->assertSame('Enrollment 1st Sem CWTS M', $rows[0][4]);
        $this->assertSame('Enrollment Summer LTS F', $rows[0][19]);
        $this->assertSame('Graduates CWTS F', $rows[0][23]);
        $this->assertSame(['Global Reciprocal Colleges', 'Private'], array_slice($rows[1], 0, 2));
        $this->assertSame('2', $rows[1][4]);
        $this->assertSame('5', $rows[1][23]);
        $this->assertSame('TOTAL', $rows[2][0]);
        $this->assertSame('1', $rows[2][1]);
        $this->assertSame('5', $rows[2][23]);
    }
}

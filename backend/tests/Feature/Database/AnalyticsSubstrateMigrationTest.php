<?php

namespace Tests\Feature\Database;

use App\Domain\Curriculum\CurriculumStatus;
use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\UserRole;
use App\Domain\Identity\UserStatus;
use App\Domain\Organization\AcademicTermStatus;
use App\Domain\Organization\ProgramStatus;
use App\Models\AcademicTerm;
use App\Models\Curriculum;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AnalyticsSubstrateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_prediction_tables_have_the_approved_columns(): void
    {
        $this->assertTrue(Schema::hasTable('prediction_runs'));
        $this->assertSame([
            'id', 'type', 'academic_term_id', 'model_version', 'feature_schema_version',
            'status', 'metrics', 'error_summary', 'started_at', 'completed_at',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('prediction_runs'));

        $this->assertTrue(Schema::hasTable('section_demand_forecasts'));
        $this->assertSame([
            'id', 'prediction_run_id', 'academic_term_id', 'program_id', 'curriculum_id',
            'subject_id', 'year_level', 'historical_school_year', 'historical_semester',
            'historical_year_level', 'predicted_demand', 'suggested_section_count',
            'confidence_lower', 'confidence_upper', 'rationale', 'created_at', 'updated_at',
        ], Schema::getColumnListing('section_demand_forecasts'));

        $this->assertTrue(Schema::hasTable('attrition_predictions'));
        $this->assertSame([
            'id', 'prediction_run_id', 'student_id', 'risk_probability', 'risk_band', 'explanations',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('attrition_predictions'));

        $this->assertTrue(Schema::hasTable('section_demand_observations'));
        $this->assertSame([
            'id', 'academic_term_id', 'program_id', 'curriculum_id', 'subject_id',
            'college', 'year_level', 'cohort_size', 'enrolled_count', 'section_count',
            'offered_capacity', 'source', 'created_at', 'updated_at',
        ], Schema::getColumnListing('section_demand_observations'));
    }

    public function test_prediction_table_columns_have_the_approved_mariadb_metadata(): void
    {
        $this->assertSame([
            ['id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['type', 'varchar', 'varchar(100)', 'NO', 100, null, null, null],
            ['academic_term_id', 'bigint', 'bigint(20) unsigned', 'YES', null, 20, 0, null],
            ['model_version', 'varchar', 'varchar(100)', 'NO', 100, null, null, null],
            ['feature_schema_version', 'varchar', 'varchar(100)', 'NO', 100, null, null, null],
            ['status', 'varchar', 'varchar(50)', 'NO', 50, null, null, null],
            ['metrics', 'longtext', 'longtext', 'YES', 4294967295, null, null, null],
            ['error_summary', 'text', 'text', 'YES', 65535, null, null, null],
            ['started_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
            ['completed_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
            ['created_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
            ['updated_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
        ], $this->columnMetadataFor('prediction_runs'));

        $this->assertSame([
            ['id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['prediction_run_id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['academic_term_id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['program_id', 'bigint', 'bigint(20) unsigned', 'YES', null, 20, 0, null],
            ['curriculum_id', 'bigint', 'bigint(20) unsigned', 'YES', null, 20, 0, null],
            ['subject_id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['year_level', 'tinyint', 'tinyint(3) unsigned', 'YES', null, 3, 0, null],
            ['historical_school_year', 'varchar', 'varchar(255)', 'YES', 255, null, null, null],
            ['historical_semester', 'varchar', 'varchar(255)', 'YES', 255, null, null, null],
            ['historical_year_level', 'tinyint', 'tinyint(3) unsigned', 'YES', null, 3, 0, null],
            ['predicted_demand', 'decimal', 'decimal(10,2)', 'NO', null, 10, 2, null],
            ['suggested_section_count', 'smallint', 'smallint(5) unsigned', 'NO', null, 5, 0, null],
            ['confidence_lower', 'decimal', 'decimal(10,2)', 'YES', null, 10, 2, null],
            ['confidence_upper', 'decimal', 'decimal(10,2)', 'YES', null, 10, 2, null],
            ['rationale', 'longtext', 'longtext', 'YES', 4294967295, null, null, null],
            ['created_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
            ['updated_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
        ], $this->columnMetadataFor('section_demand_forecasts'));

        $this->assertSame([
            ['id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['prediction_run_id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['student_id', 'bigint', 'bigint(20) unsigned', 'NO', null, 20, 0, null],
            ['risk_probability', 'decimal', 'decimal(5,4)', 'NO', null, 5, 4, null],
            ['risk_band', 'varchar', 'varchar(50)', 'NO', 50, null, null, null],
            ['explanations', 'longtext', 'longtext', 'YES', 4294967295, null, null, null],
            ['created_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
            ['updated_at', 'timestamp', 'timestamp', 'YES', null, null, null, 0],
        ], $this->columnMetadataFor('attrition_predictions'));
    }

    public function test_prediction_run_indexes_exist_with_the_approved_names(): void
    {
        $expectedIndexNames = [
            'prediction_runs_term_type_created_index',
            'prediction_runs_type_status_created_index',
        ];

        $this->assertSame($expectedIndexNames, $this->indexNamesFor('prediction_runs', $expectedIndexNames));
    }

    public function test_result_business_unique_constraints_exist_with_the_approved_names(): void
    {
        $forecastUnique = 'section_demand_forecasts_cohort_unique';
        $attritionUnique = 'attrition_predictions_run_student_unique';

        $this->assertSame([$forecastUnique], $this->indexNamesFor('section_demand_forecasts', [$forecastUnique]));
        $this->assertSame([$attritionUnique], $this->indexNamesFor('attrition_predictions', [$attritionUnique]));
    }

    public function test_analytics_indexes_have_the_approved_ordered_columns_and_uniqueness(): void
    {
        $this->assertSame([
            ['attrition_predictions', 'attrition_predictions_run_student_unique', 1, 'prediction_run_id', 0],
            ['attrition_predictions', 'attrition_predictions_run_student_unique', 2, 'student_id', 0],
            ['prediction_runs', 'prediction_runs_term_type_created_index', 1, 'academic_term_id', 1],
            ['prediction_runs', 'prediction_runs_term_type_created_index', 2, 'type', 1],
            ['prediction_runs', 'prediction_runs_term_type_created_index', 3, 'created_at', 1],
            ['prediction_runs', 'prediction_runs_type_status_created_index', 1, 'type', 1],
            ['prediction_runs', 'prediction_runs_type_status_created_index', 2, 'status', 1],
            ['prediction_runs', 'prediction_runs_type_status_created_index', 3, 'created_at', 1],
            ['section_demand_forecasts', 'section_demand_forecasts_cohort_unique', 1, 'prediction_run_id', 0],
            ['section_demand_forecasts', 'section_demand_forecasts_cohort_unique', 2, 'academic_term_id', 0],
            ['section_demand_forecasts', 'section_demand_forecasts_cohort_unique', 3, 'curriculum_id', 0],
            ['section_demand_forecasts', 'section_demand_forecasts_cohort_unique', 4, 'subject_id', 0],
            ['section_demand_forecasts', 'section_demand_forecasts_cohort_unique', 5, 'year_level', 0],
            ['section_demand_forecasts', 'section_demand_forecasts_prediction_run_index', 1, 'prediction_run_id', 1],
        ], $this->indexDefinitionsFor());
    }

    public function test_analytics_tables_have_the_five_approved_named_check_constraints(): void
    {
        $this->assertSame([
            ['attrition_predictions', 'attrition_predictions_risk_range', 'CHECK'],
            ['section_demand_forecasts', 'section_forecasts_bounds_ordered', 'CHECK'],
            ['section_demand_forecasts', 'section_forecasts_demand_nonnegative', 'CHECK'],
            ['section_demand_forecasts', 'section_forecasts_lower_nonnegative', 'CHECK'],
            ['section_demand_forecasts', 'section_forecasts_upper_nonnegative', 'CHECK'],
        ], $this->checkConstraintsFor());
    }

    public function test_an_academic_term_referenced_by_a_prediction_run_cannot_be_deleted(): void
    {
        $term = $this->makeTerm();
        $this->makePredictionRun($term->id);

        $this->expectException(QueryException::class);

        $term->delete();
    }

    public function test_a_prediction_run_referenced_by_a_forecast_cannot_be_deleted(): void
    {
        $runId = $this->makePredictionRun($this->makeTerm()->id);
        $this->insertForecast($runId, $this->makeTerm()->id, $this->makeSubject()->id);

        $this->expectException(QueryException::class);

        DB::table('prediction_runs')->where('id', $runId)->delete();
    }

    public function test_an_academic_term_referenced_by_a_forecast_cannot_be_deleted(): void
    {
        $runId = $this->makePredictionRun($this->makeTerm()->id);
        $forecastTerm = $this->makeTerm();
        $this->insertForecast($runId, $forecastTerm->id, $this->makeSubject()->id);

        $this->expectException(QueryException::class);

        $forecastTerm->delete();
    }

    public function test_a_subject_referenced_by_a_forecast_cannot_be_deleted(): void
    {
        $runId = $this->makePredictionRun($this->makeTerm()->id);
        $subject = $this->makeSubject();
        $this->insertForecast($runId, $this->makeTerm()->id, $subject->id);

        $this->expectException(QueryException::class);

        $subject->delete();
    }

    public function test_a_prediction_run_referenced_by_an_attrition_prediction_cannot_be_deleted(): void
    {
        $runId = $this->makePredictionRun($this->makeTerm()->id);
        $this->insertAttritionPrediction($runId, $this->makeStudent()->id);

        $this->expectException(QueryException::class);

        DB::table('prediction_runs')->where('id', $runId)->delete();
    }

    public function test_a_student_referenced_by_an_attrition_prediction_cannot_be_deleted(): void
    {
        $runId = $this->makePredictionRun($this->makeTerm()->id);
        $student = $this->makeStudent();
        $this->insertAttritionPrediction($runId, $student->id);

        $this->expectException(QueryException::class);

        $student->delete();
    }

    #[DataProvider('invalidForecastValues')]
    public function test_forecasts_reject_invalid_demand_and_confidence_values(array $overrides): void
    {
        $this->expectException(QueryException::class);

        $this->insertForecast(
            $this->makePredictionRun($this->makeTerm()->id),
            $this->makeTerm()->id,
            $this->makeSubject()->id,
            $overrides,
        );
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidForecastValues(): iterable
    {
        yield 'negative predicted demand' => [['predicted_demand' => '-0.01']];
        yield 'negative confidence lower bound' => [['confidence_lower' => '-0.01']];
        yield 'negative confidence upper bound' => [['confidence_upper' => '-0.01']];
        yield 'reversed confidence bounds' => [[
            'confidence_lower' => '20.00',
            'confidence_upper' => '19.99',
        ]];
    }

    /**
     * Forecasts are per curriculum cohort now (`section_demand_forecasts_cohort_unique`), so the
     * duplicate has to repeat the curriculum and year level too. (A unique key ignores rows whose
     * key columns are NULL, so both must be set for the constraint to apply.)
     */
    public function test_a_prediction_run_can_have_only_one_forecast_per_term_subject_curriculum_and_year_level(): void
    {
        $runId = $this->makePredictionRun($this->makeTerm()->id);
        $term = $this->makeTerm();
        $subject = $this->makeSubject();
        $programId = DB::table('programs')->insertGetId([
            'code' => 'UNQ', 'name' => 'Unique test program', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $curriculumId = DB::table('curricula')->insertGetId([
            'program_id' => $programId, 'name' => 'Unique test curriculum',
            'effective_school_year' => '2024-2029', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cohort = ['curriculum_id' => $curriculumId, 'year_level' => 1];

        $this->insertForecast($runId, $term->id, $subject->id, $cohort);

        $this->expectException(QueryException::class);

        $this->insertForecast($runId, $term->id, $subject->id, $cohort);
    }

    #[DataProvider('invalidRiskProbabilities')]
    public function test_attrition_predictions_reject_out_of_range_risk_probabilities(string $riskProbability): void
    {
        $this->expectException(QueryException::class);

        $this->insertAttritionPrediction(
            $this->makePredictionRun($this->makeTerm()->id),
            $this->makeStudent()->id,
            ['risk_probability' => $riskProbability],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRiskProbabilities(): iterable
    {
        yield 'negative risk probability' => ['-0.0001'];
        yield 'risk probability above one' => ['1.0001'];
    }

    public function test_a_prediction_run_can_have_only_one_attrition_prediction_per_student(): void
    {
        $runId = $this->makePredictionRun($this->makeTerm()->id);
        $student = $this->makeStudent();

        $this->insertAttritionPrediction($runId, $student->id);

        $this->expectException(QueryException::class);

        $this->insertAttritionPrediction($runId, $student->id);
    }

    private function makePredictionRun(?int $academicTermId): int
    {
        $this->assertTrue(Schema::hasTable('prediction_runs'));

        return DB::table('prediction_runs')->insertGetId([
            'type' => 'section_demand',
            'academic_term_id' => $academicTermId,
            'model_version' => 'section-demand-v1',
            'feature_schema_version' => 'features-v1',
            'status' => 'succeeded',
            'metrics' => json_encode(['mae' => 1.25], JSON_THROW_ON_ERROR),
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function insertForecast(int $predictionRunId, int $academicTermId, int $subjectId, array $overrides = []): int
    {
        $this->assertTrue(Schema::hasTable('section_demand_forecasts'));

        return DB::table('section_demand_forecasts')->insertGetId(array_merge([
            'prediction_run_id' => $predictionRunId,
            'academic_term_id' => $academicTermId,
            'subject_id' => $subjectId,
            'predicted_demand' => '30.00',
            'suggested_section_count' => 1,
            'confidence_lower' => '25.00',
            'confidence_upper' => '35.00',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function insertAttritionPrediction(int $predictionRunId, int $studentId, array $overrides = []): int
    {
        $this->assertTrue(Schema::hasTable('attrition_predictions'));

        return DB::table('attrition_predictions')->insertGetId(array_merge([
            'prediction_run_id' => $predictionRunId,
            'student_id' => $studentId,
            'risk_probability' => '0.2500',
            'risk_band' => 'low',
            'explanations' => json_encode(['attendance' => 'stable'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function makeTerm(): AcademicTerm
    {
        return AcademicTerm::create([
            'school_year' => '2026-2027',
            'semester' => 'term-'.uniqid(),
            'status' => AcademicTermStatus::SemesterOngoing,
        ]);
    }

    private function makeSubject(): Subject
    {
        return Subject::create([
            'code' => 'SUB-'.uniqid(),
            'title' => 'Analytics Test Subject',
            'units' => 3,
            'status' => SubjectStatus::Active,
        ]);
    }

    private function makeStudent(): StudentProfile
    {
        $program = Program::create([
            'code' => 'PRG-'.uniqid(),
            'name' => 'Analytics Test Program',
            'status' => ProgramStatus::Active,
        ]);
        $curriculum = Curriculum::create([
            'program_id' => $program->id,
            'name' => 'Analytics Test Curriculum',
            'effective_school_year' => '2026-2027',
            'status' => CurriculumStatus::Active,
        ]);
        $user = User::create([
            'name' => 'Analytics Test Student',
            'email' => 'analytics-student-'.uniqid().'@grc.test',
            'password' => 'irrelevant-password',
            'role' => UserRole::Student,
            'status' => UserStatus::Active,
        ]);

        return StudentProfile::create([
            'user_id' => $user->id,
            'student_number' => 'STU-'.uniqid(),
            'program_id' => $program->id,
            'curriculum_id' => $curriculum->id,
            'year_level' => 1,
            'admission_status' => AdmissionStatus::Admitted,
            'academic_standing' => AcademicStanding::Good,
        ]);
    }

    /**
     * @param  list<string>  $expectedIndexNames
     * @return list<string>
     */
    private function indexNamesFor(string $table, array $expectedIndexNames): array
    {
        $placeholders = implode(', ', array_fill(0, count($expectedIndexNames), '?'));

        /** @var list<object{INDEX_NAME: string}> $indexes */
        $indexes = DB::select(
            "select distinct INDEX_NAME from information_schema.statistics where table_schema = ? and table_name = ? and INDEX_NAME in ({$placeholders}) order by INDEX_NAME",
            array_merge([DB::connection()->getDatabaseName(), $table], $expectedIndexNames),
        );

        return array_map(static fn (object $index): string => $index->INDEX_NAME, $indexes);
    }

    /**
     * @return list<array{string, string, string, string, ?int, ?int, ?int, ?int}>
     */
    private function columnMetadataFor(string $table): array
    {
        /** @var list<object{
         *     COLUMN_NAME: string,
         *     DATA_TYPE: string,
         *     COLUMN_TYPE: string,
         *     IS_NULLABLE: string,
         *     CHARACTER_MAXIMUM_LENGTH: ?int,
         *     NUMERIC_PRECISION: ?int,
         *     NUMERIC_SCALE: ?int,
         *     DATETIME_PRECISION: ?int
         * }> $columns
         */
        $columns = DB::select(
            'select COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, DATETIME_PRECISION from information_schema.columns where table_schema = ? and table_name = ? order by ORDINAL_POSITION',
            [DB::connection()->getDatabaseName(), $table],
        );

        return array_map(static fn (object $column): array => [
            $column->COLUMN_NAME,
            $column->DATA_TYPE,
            $column->COLUMN_TYPE,
            $column->IS_NULLABLE,
            $column->CHARACTER_MAXIMUM_LENGTH,
            $column->NUMERIC_PRECISION,
            $column->NUMERIC_SCALE,
            $column->DATETIME_PRECISION,
        ], $columns);
    }

    /**
     * @return list<array{string, string, int, string, int}>
     */
    private function indexDefinitionsFor(): array
    {
        /** @var list<object{
         *     TABLE_NAME: string,
         *     INDEX_NAME: string,
         *     SEQ_IN_INDEX: int,
         *     COLUMN_NAME: string,
         *     NON_UNIQUE: int
         * }> $indexes
         */
        $indexes = DB::select(
            "select TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE from information_schema.statistics where table_schema = ? and ((table_name = 'prediction_runs' and index_name in ('prediction_runs_type_status_created_index', 'prediction_runs_term_type_created_index')) or (table_name = 'section_demand_forecasts' and index_name in ('section_demand_forecasts_cohort_unique', 'section_demand_forecasts_prediction_run_index')) or (table_name = 'attrition_predictions' and index_name = 'attrition_predictions_run_student_unique')) order by TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            [DB::connection()->getDatabaseName()],
        );

        return array_map(static fn (object $index): array => [
            $index->TABLE_NAME,
            $index->INDEX_NAME,
            $index->SEQ_IN_INDEX,
            $index->COLUMN_NAME,
            $index->NON_UNIQUE,
        ], $indexes);
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function checkConstraintsFor(): array
    {
        /** @var list<object{TABLE_NAME: string, CONSTRAINT_NAME: string, CONSTRAINT_TYPE: string}> $constraints */
        $constraints = DB::select(
            "select TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE from information_schema.table_constraints where constraint_schema = ? and CONSTRAINT_NAME in ('attrition_predictions_risk_range', 'section_forecasts_demand_nonnegative', 'section_forecasts_lower_nonnegative', 'section_forecasts_upper_nonnegative', 'section_forecasts_bounds_ordered') order by TABLE_NAME, CONSTRAINT_NAME",
            [DB::connection()->getDatabaseName()],
        );

        return array_map(static fn (object $constraint): array => [
            $constraint->TABLE_NAME,
            $constraint->CONSTRAINT_NAME,
            $constraint->CONSTRAINT_TYPE,
        ], $constraints);
    }
}

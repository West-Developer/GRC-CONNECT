<?php

namespace Database\Seeders;

use App\Domain\Curriculum\SubjectStatus;
use App\Domain\Organization\CollegeCode;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seeds older curriculum subjects (2012-2017 pre-K12 and 2018-2023 transitional)
 * across CCS, COE, CBAE, and COA to support multi-curriculum versioning.
 */
final class GrcOlderSubjectCatalogSeeder extends Seeder
{
    /**
     * @var list<array{college: CollegeCode, code: string, title: string, units: int}>
     */
    private const OLDER_SUBJECTS = [
        // Pre-K12 General Education Subjects (Common to all colleges)
        ['college' => CollegeCode::Ccs, 'code' => 'ENG 1', 'title' => 'Study and Thinking Skills in English', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'ENG 2', 'title' => 'Writing in the Discipline', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'ENG 3', 'title' => 'Speech Communication and Public Speaking', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'FIL 1', 'title' => 'Komunikasyon sa Akademikong Filipino', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'FIL 2', 'title' => 'Pagbasa at Pagsulat Tungo sa Pananaliksik', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'FIL 3', 'title' => 'Masining na Pagpapahayag', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'MATH 1', 'title' => 'College Algebra', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'MATH 2', 'title' => 'Plane Trigonometry', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'NATSCI 1', 'title' => 'General Biology with Earth Science', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'NATSCI 2', 'title' => 'Physical Science (Chemistry & Physics)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'HIST 1', 'title' => 'Philippine History and Geography', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'POLSCI 1', 'title' => 'Politics and Governance with Philippine Constitution', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'PSYCH 1', 'title' => 'General Psychology with Drug Education', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'SOCIO 1', 'title' => 'Society and Culture with Family Planning', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'HUM 1', 'title' => 'Introduction to Humanities and Arts', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'PE 1', 'title' => 'Physical Fitness and Gymnastics', 'units' => 2],
        ['college' => CollegeCode::Ccs, 'code' => 'PE 2', 'title' => 'Rhythmic Activities and Dance', 'units' => 2],
        ['college' => CollegeCode::Ccs, 'code' => 'PE 3', 'title' => 'Individual and Dual Sports', 'units' => 2],
        ['college' => CollegeCode::Ccs, 'code' => 'PE 4', 'title' => 'Team Sports and Games', 'units' => 2],

        // CCS Older Majors (2012-2017 & 2018-2023)
        ['college' => CollegeCode::Ccs, 'code' => 'IT 101', 'title' => 'Introduction to Information Technology', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'PROG 1', 'title' => 'Computer Programming 1 (C/C++)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'PROG 2', 'title' => 'Computer Programming 2 (Java)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'DATASTRUCT', 'title' => 'Data Structures and Algorithms Analysis', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'DATABASE 1', 'title' => 'Database Management Systems 1', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'DATABASE 2', 'title' => 'Advanced Database Systems', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'NET 1', 'title' => 'Networking 1 (Fundamentals)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'NET 2', 'title' => 'Networking 2 (Routing and Switching)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'WEBDES', 'title' => 'Web Design and Development', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'SYSANA', 'title' => 'Systems Analysis and Design', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'SOFENG', 'title' => 'Software Engineering', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'CAPSTONE 1', 'title' => 'Capstone Project 1 (Proposal)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'CAPSTONE 2', 'title' => 'Capstone Project 2 (Defense)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'PRACTICUM', 'title' => 'On-the-Job Training / Industry Internship (486 hrs)', 'units' => 6],
        ['college' => CollegeCode::Ccs, 'code' => 'IT-ELEC1', 'title' => 'IT Elective 1 (Mobile Application Dev)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'IT-ELEC2', 'title' => 'IT Elective 2 (Cloud Computing Basics)', 'units' => 3],
        ['college' => CollegeCode::Ccs, 'code' => 'IT-ELEC3', 'title' => 'IT Elective 3 (Cybersecurity Fundamentals)', 'units' => 3],

        // COE Older Subjects
        ['college' => CollegeCode::Coe, 'code' => 'ENG 1', 'title' => 'Study and Thinking Skills in English', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'ENG 2', 'title' => 'Writing in the Discipline', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'FIL 1', 'title' => 'Komunikasyon sa Akademikong Filipino', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'FIL 2', 'title' => 'Pagbasa at Pagsulat Tungo sa Pananaliksik', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'MATH 1', 'title' => 'College Algebra', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'NATSCI 1', 'title' => 'Biological Science', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'HIST 1', 'title' => 'Philippine History', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'PE 1', 'title' => 'Physical Fitness', 'units' => 2],
        ['college' => CollegeCode::Coe, 'code' => 'PE 2', 'title' => 'Rhythmic Activities', 'units' => 2],
        ['college' => CollegeCode::Coe, 'code' => 'PE 3', 'title' => 'Individual/Dual Sports', 'units' => 2],
        ['college' => CollegeCode::Coe, 'code' => 'PE 4', 'title' => 'Team Sports', 'units' => 2],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 1', 'title' => 'Child and Adolescent Development', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 2', 'title' => 'Facilitating Learning', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 3', 'title' => 'Principles of Teaching 1', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 4', 'title' => 'Principles of Teaching 2', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 5', 'title' => 'Assessment of Student Learning 1', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 6', 'title' => 'Assessment of Student Learning 2', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 7', 'title' => 'Educational Technology 1', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 8', 'title' => 'Educational Technology 2', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'EDUC 9', 'title' => 'Curriculum Development', 'units' => 3],
        ['college' => CollegeCode::Coe, 'code' => 'FS 1', 'title' => 'Field Study 1 (Learner Development)', 'units' => 1],
        ['college' => CollegeCode::Coe, 'code' => 'FS 2', 'title' => 'Field Study 2 (Learning Environment)', 'units' => 1],
        ['college' => CollegeCode::Coe, 'code' => 'FS 3', 'title' => 'Field Study 3 (Technology in the Learning Env)', 'units' => 1],
        ['college' => CollegeCode::Coe, 'code' => 'FS 4', 'title' => 'Field Study 4 (Exploring the Curriculum)', 'units' => 1],
        ['college' => CollegeCode::Coe, 'code' => 'FS 5', 'title' => 'Field Study 5 (Learning Assessment Strategies)', 'units' => 1],
        ['college' => CollegeCode::Coe, 'code' => 'FS 6', 'title' => 'Field Study 6 (On Becoming a Teacher)', 'units' => 1],
        ['college' => CollegeCode::Coe, 'code' => 'PRAC-TEACH', 'title' => 'Practice Teaching (Internship)', 'units' => 6],

        // CBAE Older Subjects
        ['college' => CollegeCode::Cbae, 'code' => 'ENG 1', 'title' => 'Study and Thinking Skills in English', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'ENG 2', 'title' => 'Writing in the Discipline', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'FIL 1', 'title' => 'Komunikasyon sa Akademikong Filipino', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'FIL 2', 'title' => 'Pagbasa at Pagsulat Tungo sa Pananaliksik', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'MATH 1', 'title' => 'College Algebra with Business Applications', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'MATH 2', 'title' => 'Mathematics of Investment', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'PE 1', 'title' => 'Physical Fitness', 'units' => 2],
        ['college' => CollegeCode::Cbae, 'code' => 'PE 2', 'title' => 'Rhythmic Activities', 'units' => 2],
        ['college' => CollegeCode::Cbae, 'code' => 'PE 3', 'title' => 'Individual/Dual Sports', 'units' => 2],
        ['college' => CollegeCode::Cbae, 'code' => 'PE 4', 'title' => 'Team Sports', 'units' => 2],
        ['college' => CollegeCode::Cbae, 'code' => 'BASIC ACCTG', 'title' => 'Basic Accounting and Bookkeeping', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'BUS-ORG', 'title' => 'Business Organization and Management', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'MKTG 1', 'title' => 'Principles of Marketing', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'FIN 1', 'title' => 'Basic Finance and Financial Systems', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'BUS-STAT', 'title' => 'Business Statistics', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'BUS-ETH', 'title' => 'Business Ethics and Social Responsibility', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'FEASIB', 'title' => 'Feasibility Study and Business Plan Preparation', 'units' => 3],
        ['college' => CollegeCode::Cbae, 'code' => 'PRAC-CBAE', 'title' => 'Practicum / Industry Internship (600 hrs)', 'units' => 6],

        // COA Older Subjects
        ['college' => CollegeCode::Coa, 'code' => 'ENG 1', 'title' => 'Study and Thinking Skills in English', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'ENG 2', 'title' => 'Writing in the Discipline', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'FIL 1', 'title' => 'Komunikasyon sa Akademikong Filipino', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'FIL 2', 'title' => 'Pagbasa at Pagsulat Tungo sa Pananaliksik', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'MATH 1', 'title' => 'College Algebra', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'MATH 2', 'title' => 'Mathematics of Investment', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'PE 1', 'title' => 'Physical Fitness', 'units' => 2],
        ['college' => CollegeCode::Coa, 'code' => 'PE 2', 'title' => 'Rhythmic Activities', 'units' => 2],
        ['college' => CollegeCode::Coa, 'code' => 'PE 3', 'title' => 'Individual/Dual Sports', 'units' => 2],
        ['college' => CollegeCode::Coa, 'code' => 'PE 4', 'title' => 'Team Sports', 'units' => 2],
        ['college' => CollegeCode::Coa, 'code' => 'ACTG 1', 'title' => 'Financial Accounting and Reporting 1', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'ACTG 2', 'title' => 'Financial Accounting and Reporting 2', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'ACTG 3', 'title' => 'Financial Accounting and Reporting 3', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'COST-ACC', 'title' => 'Cost Accounting and Control', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'AUD-THEO', 'title' => 'Auditing Theory and Practice', 'units' => 3],
        ['college' => CollegeCode::Coa, 'code' => 'PRAC-COA', 'title' => 'Accountancy Internship / Practicum', 'units' => 6],
    ];

    public function run(): void
    {
        $this->guardEnvironment();

        DB::transaction(function (): void {
            foreach (self::OLDER_SUBJECTS as $row) {
                Subject::updateOrCreate(
                    [
                        'college' => $row['college']->value,
                        'code' => $row['code'],
                    ],
                    [
                        'title' => $row['title'],
                        'units' => $row['units'],
                        'status' => SubjectStatus::Active,
                    ],
                );
            }
        });
    }

    private function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'GrcOlderSubjectCatalogSeeder may only run in local or testing environment.',
            );
        }
    }
}


<?php

namespace App\Models;

use App\Domain\Identity\AcademicStanding;
use App\Domain\Identity\AdmissionStatus;
use App\Domain\Identity\FinancialStatus;
use App\Domain\Identity\Gender;
use App\Domain\Identity\StudentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property string $student_number
 * @property int $program_id
 * @property int $curriculum_id
 * @property ?int $entry_year
 * @property int $year_level
 * @property ?string $enrollment_category
 * @property ?CarbonImmutable $enrollment_category_derived_at
 * @property ?StudentType $student_type
 * @property AdmissionStatus $admission_status
 * @property ?string $graduation_school_year
 * @property AcademicStanding $academic_standing
 * @property ?FinancialStatus $financial_status
 * @property ?string $address
 * @property ?Gender $gender
 * @property ?CarbonImmutable $birth_date
 * @property ?string $citizenship
 * @property ?string $contact_number
 * @property ?string $street_barangay
 * @property ?string $town_city
 * @property ?string $province
 * @property ?list<string> $demographics_demo_fields
 * @property ?CarbonImmutable $requirements_verified_at
 * @property ?int $requirements_verified_by
 * @property ?CarbonImmutable $freshman_payment_confirmed_at
 * @property ?CarbonImmutable $enrollment_deadline_at
 * @property ?numeric-string $full_payment_discount_percentage
 * @property bool $is_demo_account
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Program $program
 * @property-read Curriculum $curriculum
 * @property-read Collection<int, Enrollment> $enrollments
 * @property-read Collection<int, AcademicGrade> $grades
 * @property-read Collection<int, AccountPayment> $accountPayments
 */
final class StudentProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'student_number',
        'program_id',
        'curriculum_id',
        'entry_year',
        'year_level',
        'enrollment_category',
        'enrollment_category_derived_at',
        'student_type',
        'admission_status',
        'graduation_school_year',
        'academic_standing',
        'financial_status',
        'address',
        'gender',
        'birth_date',
        'citizenship',
        'contact_number',
        'street_barangay',
        'town_city',
        'province',
        'demographics_demo_fields',
        'requirements_verified_at',
        'requirements_verified_by',
        'freshman_payment_confirmed_at',
        'enrollment_deadline_at',
        'is_demo_account',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_year' => 'integer',
            'year_level' => 'integer',
            'enrollment_category_derived_at' => 'immutable_datetime',
            'student_type' => StudentType::class,
            'admission_status' => AdmissionStatus::class,
            'academic_standing' => AcademicStanding::class,
            'financial_status' => FinancialStatus::class,
            'gender' => Gender::class,
            'birth_date' => 'immutable_date',
            'demographics_demo_fields' => 'array',
            'requirements_verified_at' => 'immutable_datetime',
            'freshman_payment_confirmed_at' => 'immutable_datetime',
            'enrollment_deadline_at' => 'immutable_datetime',
            'full_payment_discount_percentage' => 'decimal:1',
            'is_demo_account' => 'boolean',
        ];
    }

    /**
     * A freshman who has been admitted but whose first payment the Cashier has
     * not confirmed yet: no enrollment, and no usable account (the setup email
     * is sent only after that payment).
     */
    public function isFreshmanAwaitingPayment(): bool
    {
        return $this->student_type === StudentType::Freshman
            && $this->freshman_payment_confirmed_at === null;
    }

    /**
     * A pay-first student (a Freshman or a Transferee, `StudentType::isPayFirst`)
     * whose first payment the Cashier has not confirmed yet. Unlike a freshman in
     * that state, a transferee already has a working account: they sign in to
     * list their subjects and to choose their enrollment before paying. The first
     * payment is `freshman_payment_confirmed_at` for both types (no migration).
     */
    public function isAwaitingFirstPayment(): bool
    {
        return $this->student_type instanceof StudentType
            && $this->student_type->isPayFirst()
            && $this->freshman_payment_confirmed_at === null;
    }

    /**
     * @return HasOne<QueueTicket, $this>
     */
    public function queueTicket(): HasOne
    {
        return $this->hasOne(QueueTicket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return BelongsTo<Curriculum, $this>
     */
    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    /**
     * @return HasMany<AcademicGrade, $this>
     */
    public function grades(): HasMany
    {
        return $this->hasMany(AcademicGrade::class, 'student_id');
    }

    /**
     * @return HasMany<AccountPayment, $this>
     */
    public function accountPayments(): HasMany
    {
        return $this->hasMany(AccountPayment::class, 'student_id');
    }

    /** @return HasMany<StudentProfileChangeRequest, $this> */
    public function profileChangeRequests(): HasMany
    {
        return $this->hasMany(StudentProfileChangeRequest::class, 'student_id');
    }
}

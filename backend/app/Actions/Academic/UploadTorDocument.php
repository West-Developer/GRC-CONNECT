<?php

namespace App\Actions\Academic;

use App\Domain\Audit\AuditableType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRequestContext;
use App\Domain\Notifications\NotificationType;
use App\Models\StudentProfile;
use App\Models\StudentTorDocument;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Notifications\NotificationRecorder;
use App\Support\Notifications\ProgramChairRecipients;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A transferee or returnee uploads their Transcript of Records so the Program
 * Chair can read it while mapping their previous subjects (ADR 0026). The file
 * goes to the private `local` disk under an unguessable name, never a public
 * URL; the Program Chairs who can see the student are notified.
 *
 * Only students whose enrollment waits on credit mapping upload one (a
 * freshman has no previous school), and a student keeps at most
 * `MAX_FILES` files so the folder cannot grow without bound.
 */
final readonly class UploadTorDocument
{
    public const MAX_FILES = 10;

    public function __construct(
        private EvaluateCreditMappingStatus $creditMapping,
        private AuditRecorder $auditRecorder,
        private NotificationRecorder $notificationRecorder,
    ) {}

    public function execute(User $actor, UploadedFile $file, AuditRequestContext $context): StudentTorDocument
    {
        $student = StudentProfile::query()->with(['program', 'user'])->where('user_id', $actor->id)->first();

        if ($student === null) {
            throw ValidationException::withMessages(['file' => 'Your student record could not be found.']);
        }

        if (! $this->creditMapping->execute($student)->requiresCreditMapping) {
            throw ValidationException::withMessages([
                'file' => 'Only transferees and returnees upload a Transcript of Records.',
            ]);
        }

        $path = null;

        try {
            return DB::transaction(function () use ($actor, $file, $context, $student, &$path): StudentTorDocument {
                // Locks this student's rows so two simultaneous uploads cannot both slip under the limit.
                $existing = StudentTorDocument::query()->where('student_id', $student->id)->lockForUpdate()->count();

                if ($existing >= self::MAX_FILES) {
                    throw ValidationException::withMessages([
                        'file' => 'You can keep up to '.self::MAX_FILES.' TOR files. Remove one before uploading another.',
                    ]);
                }

                $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
                $path = Storage::disk('local')->putFileAs(
                    'tor/'.$student->id,
                    $file,
                    Str::uuid()->toString().'.'.$extension,
                );

                if ($path === false) {
                    throw ValidationException::withMessages(['file' => 'The file could not be saved. Try again.']);
                }

                $document = StudentTorDocument::create([
                    'student_id' => $student->id,
                    'uploaded_by' => $actor->id,
                    'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                    'stored_path' => $path,
                    'mime_type' => (string) ($file->getMimeType() ?? 'application/octet-stream'),
                    'size_bytes' => (int) $file->getSize(),
                ]);

                $this->auditRecorder->record(
                    $actor,
                    AuditAction::TOR_DOCUMENT_UPLOADED,
                    AuditableType::TOR_DOCUMENT,
                    $document->id,
                    null,
                    ['student_id' => $student->id, 'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes],
                    null,
                    $context,
                );

                $this->notificationRecorder->recordMany(
                    ProgramChairRecipients::forStudent($student),
                    NotificationType::TransfereeCreditRequested,
                    "Student {$student->student_number} uploaded a Transcript of Records for credit mapping.",
                );

                return $document->load(['student.user']);
            });
        } catch (\Throwable $e) {
            // The row (and its audit/notification) rolled back; do not leave the file behind.
            if (is_string($path)) {
                Storage::disk('local')->delete($path);
            }

            throw $e;
        }
    }
}

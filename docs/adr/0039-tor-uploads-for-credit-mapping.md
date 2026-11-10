# ADR 0039 — Transcript of Records Uploads for Credit Mapping

**Status:** Accepted
**Date:** 2026-10-03
**Amends:** ADR 0026 (Credit mapping). It adds evidence to the existing route; the route, the roles and the rule that an approved credit counts are unchanged.

## Context

A transferee or returnee cannot enroll until their credit mapping is done (the enrollment gate,
`EvaluateCreditMappingStatus`). Credit mapping is the Program Chair's work (ADR 0026), but the
Chair had only what the student typed in (previous school, subject, grade, units) and nothing to
check it against. Stakeholder feedback asked that the student be able to **upload their TOR** so
the Program Chair can check it.

There was no file storage anywhere in the system, so this is the first uploaded document.

## Decisions

1. **The TOR is evidence, never an automatic credit.** Uploading a TOR grants nothing. The Program
   Chair reads it and records the subjects (or the student requests them), maps and endorses them,
   and Registrar Staff approve, exactly as in ADR 0026. The system does not read the file (no OCR)
   and never infers a credit from it.
2. **Who uploads.** Only a student whose enrollment waits on credit mapping (`requiresCreditMapping`:
   transferee, returnee). A freshman has no previous school and is refused (422). At most 10 files
   per student, so a folder cannot grow without bound.
3. **What is accepted.** PDF, JPG or PNG, at most 8 MB (checked by the server; the browser checks
   first only to save a round trip).
4. **Where it lives, and who can read it.** The file is on the private `local` disk
   (`storage/app/private/tor/{student_id}/{uuid}.{ext}`), never a public URL, under an unguessable
   name. The row `student_tor_documents` holds only metadata (original name, MIME type, size,
   uploader); the stored path is never sent to the client. The file is returned by
   `GET /api/v1/tor-documents/{id}/file` behind `StudentTorDocumentPolicy`: the student (own), the
   Program Chair of the student's college (a chair with no college sees all, the same fallback as
   `TransfereeCredit`), and Registrar Staff / Head. Responses are `Cache-Control: private, no-store`
   with `X-Content-Type-Options: nosniff`. Because the API authenticates with a bearer token, the
   frontend fetches the file as a blob and opens an object URL; a plain link could not send the token.
5. **Removal.** A student may remove their own file (audited, file deleted after the row commits).
   Removing a TOR does not undo credit requests already made from it. Nobody else deletes.
6. **Notification and audit.** Uploading notifies the Program Chairs who can see the student
   (reusing `transferee_credit_requested`, so it links to the credit-mapping page) and writes
   `tor_document.uploaded` / `tor_document.deleted` audit entries (metadata only, no file content).
7. **Returnees get the same path as transferees** in the Enrollment page's "credit mapping required"
   panel (it was shown to transferees only).

## Consequences

- **Deployment needs three things this repo cannot do for you.** (a) The web server and PHP must allow
  the upload: PHP `upload_max_filesize >= 10M` and `post_max_size >= 12M`, and nginx
  `client_max_body_size 10M` (the defaults, 2M and 1M, silently refuse an 8 MB TOR). (b) `storage/app/private`
  must be a **persistent volume** on the VPS container; without one, every redeploy deletes the uploaded
  TORs while the database rows remain (the file endpoint then answers 404). (c) `php artisan migrate`
  (`2026_10_03_000001_create_student_tor_documents_table`).
- The files are personal academic records. They are not in the repo, the dev database export or the
  presentation dump; backups of the volume must be treated like the database.
- Not decided here: a retention period for TORs after the credit is approved, malware scanning of
  uploads, and OCR-assisted suggestions. Each is a separate decision.

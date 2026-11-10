import { z } from "zod"

/** One uploaded Transcript of Records file (metadata only; the file is fetched on demand). */
export const torDocumentSchema = z
  .object({
    type: z.literal("tor_document"),
    id: z.number().int().positive(),
    student_id: z.number().int().positive(),
    student_number: z.string().min(1),
    student_name: z.string().min(1),
    original_name: z.string().min(1),
    mime_type: z.string().min(1),
    size_bytes: z.number().int().nonnegative(),
    uploaded_at: z.iso.datetime().nullable(),
  })
  .strict()

export const torDocumentEnvelopeSchema = z
  .object({ data: torDocumentSchema })
  .strict()

const paginationLinksSchema = z
  .object({
    first: z.string().url(),
    last: z.string().url(),
    prev: z.string().url().nullable(),
    next: z.string().url().nullable(),
  })
  .strict()

const paginationMetaSchema = z
  .object({
    current_page: z.number().int().positive(),
    last_page: z.number().int().positive(),
    per_page: z.number().int().min(1).max(100),
    total: z.number().int().nonnegative(),
  })
  .passthrough()

export const paginatedTorDocumentsSchema = z
  .object({
    data: z.array(torDocumentSchema),
    links: paginationLinksSchema,
    meta: paginationMetaSchema,
  })
  .strict()

export const torDocumentFiltersSchema = z
  .object({
    student_id: z.number().int().positive().optional(),
    page: z.number().int().positive().default(1),
    per_page: z.number().int().min(1).max(100).default(50),
  })
  .strict()

/** What the server accepts: a PDF, JPG or PNG of at most 8 MB. */
export const TOR_ACCEPTED_TYPES = ["application/pdf", "image/jpeg", "image/png"]
export const TOR_MAX_BYTES = 8 * 1024 * 1024

export type TorDocument = z.infer<typeof torDocumentSchema>
export type TorDocumentFilters = z.input<typeof torDocumentFiltersSchema>
export interface PaginatedTorDocuments {
  data: readonly TorDocument[]
  links: z.infer<typeof paginationLinksSchema>
  meta: z.infer<typeof paginationMetaSchema>
}

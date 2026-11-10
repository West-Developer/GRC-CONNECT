import { z } from "zod"

/** The four official reports the Registrar Head hands to CHED (ADR 0049). */
export const chedReportKeys = [
  "enrollment-list",
  "nstp-enrollment",
  "nstp-graduates",
  "nstp-summary",
] as const

export const chedReportKeySchema = z.enum(chedReportKeys)
export type ChedReportKey = z.infer<typeof chedReportKeySchema>

export const chedDownloadFormats = ["csv", "xlsx"] as const
export type ChedDownloadFormat = (typeof chedDownloadFormats)[number]

const reportInfoSchema = z
  .object({
    key: chedReportKeySchema,
    title: z.string().min(1),
    term_label: z.string().min(1),
    school_year: z.string().min(1),
    columns: z.array(
      z.object({ key: z.string().min(1), label: z.string().min(1) }),
    ),
    /** Listed students with no gender on file. */
    missing_gender: z.number().int().nonnegative(),
  })
  .passthrough()

export type ChedReportInfo = z.infer<typeof reportInfoSchema>

const rowSchema = z.record(z.string(), z.union([z.string(), z.number()]))
export type ChedReportRow = z.infer<typeof rowSchema>

export const chedListResponseSchema = z
  .object({
    data: z.array(rowSchema),
    meta: z
      .object({
        current_page: z.number().int().positive(),
        last_page: z.number().int().positive(),
        per_page: z.number().int().positive(),
        total: z.number().int().nonnegative(),
      })
      .passthrough(),
    report: reportInfoSchema,
  })
  .passthrough()

export type ChedListResponse = z.infer<typeof chedListResponseSchema>

const maleFemaleSchema = z.object({
  m: z.number().int().nonnegative(),
  f: z.number().int().nonnegative(),
})

const componentsSchema = z.object({
  rotc: maleFemaleSchema,
  cwts: maleFemaleSchema,
  lts: maleFemaleSchema,
})

export type ChedNstpComponents = z.infer<typeof componentsSchema>

export const chedSummaryResponseSchema = z
  .object({
    data: z
      .object({
        campus: z.string().min(1),
        classification: z.string().min(1),
        academic_year: z.string().min(1),
        semester: z.string().min(1),
        enrollment: z.object({
          "1st": componentsSchema,
          "2nd": componentsSchema,
          summer: componentsSchema,
        }),
        graduates: componentsSchema,
        missing_gender: z.number().int().nonnegative(),
      })
      .passthrough(),
    report: reportInfoSchema,
  })
  .passthrough()

export type ChedSummaryResponse = z.infer<typeof chedSummaryResponseSchema>

import {
  paginatedTorDocumentsSchema,
  torDocumentEnvelopeSchema,
  torDocumentFiltersSchema,
  type PaginatedTorDocuments,
  type TorDocument,
  type TorDocumentFilters,
} from "@/features/schemas/tor-document-schema"
import {
  ApiClientError,
  deleteAuthenticatedJson,
  getAuthenticatedBlob,
  getAuthenticatedJson,
  postAuthenticatedForm,
} from "@/features/services/api-client"

export const TOR_DOCUMENTS_PATH = "/api/v1/tor-documents"

function parse<T>(
  schema: {
    safeParse: (
      value: unknown,
    ) => { success: true; data: T } | { success: false; error: unknown }
  },
  value: unknown,
  label: string,
): T {
  const result = schema.safeParse(value)
  if (result.success) return result.data
  throw new ApiClientError({
    kind: "contract",
    message: `The API responded, but its ${label} did not match the published v1 contract.`,
    cause: result.error,
  })
}

export async function listTorDocuments(
  filters: TorDocumentFilters,
  signal?: AbortSignal,
): Promise<PaginatedTorDocuments> {
  const parsed = parse(torDocumentFiltersSchema, filters, "TOR filter")
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(parsed)) {
    if (value !== undefined) query.set(key, String(value))
  }
  return parse(
    paginatedTorDocumentsSchema,
    await getAuthenticatedJson(
      `${TOR_DOCUMENTS_PATH}?${query.toString()}`,
      signal,
    ),
    "TOR list",
  )
}

/** The signed-in student uploads one TOR file. */
export async function uploadTorDocument(file: File): Promise<TorDocument> {
  const form = new FormData()
  form.append("file", file)
  return parse(
    torDocumentEnvelopeSchema,
    await postAuthenticatedForm(TOR_DOCUMENTS_PATH, form),
    "uploaded TOR",
  ).data
}

export async function deleteTorDocument(id: number): Promise<void> {
  await deleteAuthenticatedJson(`${TOR_DOCUMENTS_PATH}/${id}`)
}

/**
 * Opens a TOR in a new tab. The file endpoint needs the bearer token, so it is
 * fetched as a blob and shown from an object URL (a plain link could not send it).
 */
export async function openTorDocument(document: TorDocument): Promise<void> {
  const blob = await getAuthenticatedBlob(
    `${TOR_DOCUMENTS_PATH}/${document.id}/file`,
  )
  const typed = new Blob([blob], { type: document.mime_type })
  const url = URL.createObjectURL(typed)
  const opened = window.open(url, "_blank", "noopener")
  if (opened === null) {
    // A pop-up blocker stopped the new tab: fall back to a download.
    const link = window.document.createElement("a")
    link.href = url
    link.download = document.original_name
    link.click()
  }
  // The new tab has its own reference to the data; release ours after it loads.
  window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

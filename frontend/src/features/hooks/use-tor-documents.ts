"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import { keepPreviousForSameUser } from "@/features/lib/query-client"
import type { TorDocumentFilters } from "@/features/schemas/tor-document-schema"
import {
  deleteTorDocument,
  listTorDocuments,
  uploadTorDocument,
} from "@/features/services/tor-document-service"

export const torDocumentsQueryKey = (
  userId: string | null,
  filters: TorDocumentFilters,
) => ["tor-documents", userId, filters] as const

export function useTorDocumentsQuery(
  filters: TorDocumentFilters,
  { enabled = true }: { enabled?: boolean } = {},
) {
  const { session } = useAuth()

  return useQuery({
    queryKey: torDocumentsQueryKey(session?.userId ?? null, filters),
    queryFn: ({ signal }) => listTorDocuments(filters, signal),
    placeholderData: keepPreviousForSameUser(session?.userId ?? null),
    enabled: enabled && session !== null,
  })
}

function useInvalidateTorDocumentQueries() {
  const { session } = useAuth()
  const queryClient = useQueryClient()

  return () =>
    queryClient.invalidateQueries({
      queryKey: ["tor-documents", session?.userId ?? null],
    })
}

export function useUploadTorDocumentMutation() {
  const invalidate = useInvalidateTorDocumentQueries()

  return useMutation({
    mutationFn: (file: File) => uploadTorDocument(file),
    onSuccess: () => invalidate(),
  })
}

export function useDeleteTorDocumentMutation() {
  const invalidate = useInvalidateTorDocumentQueries()

  return useMutation({
    mutationFn: (id: number) => deleteTorDocument(id),
    onSuccess: () => invalidate(),
  })
}

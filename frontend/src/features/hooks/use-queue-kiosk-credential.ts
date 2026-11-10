"use client"

import { useCallback, useEffect, useLayoutEffect, useMemo, useRef } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  getQueueKioskCredential,
  updateQueueKioskCredential,
} from "@/features/services/queue-kiosk-credential-service"
import type { UpdateQueueKioskCredentialInput } from "@/features/schemas/queue-kiosk-credential-schema"

export const queueKioskCredentialQueryKey = (accountingUserId: string | null) =>
  ["queue-kiosk-credential", accountingUserId] as const

interface RotationRequest {
  input: UpdateQueueKioskCredentialInput
  accountingUserId: string
}

export class QueueKioskCredentialMutationCancelledError extends Error {
  constructor() {
    super("The queue kiosk credential rotation is no longer active.")
    this.name = "QueueKioskCredentialMutationCancelledError"
  }
}

export function isQueueKioskCredentialMutationCancelledError(
  error: unknown,
): error is QueueKioskCredentialMutationCancelledError {
  return error instanceof QueueKioskCredentialMutationCancelledError
}

export function useQueueKioskCredentialQuery() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const enabled = session?.role === "accounting_staff"
  const accountingUserId = enabled ? (session?.userId ?? null) : null
  const queryKey = useMemo(
    () => queueKioskCredentialQueryKey(accountingUserId),
    [accountingUserId],
  )

  useEffect(() => {
    if (!enabled) {
      queryClient.removeQueries({ queryKey, exact: true })
    }

    return () => {
      queryClient.removeQueries({ queryKey, exact: true })
    }
  }, [enabled, queryClient, queryKey])

  return useQuery({
    queryKey,
    queryFn: ({ signal }) => getQueueKioskCredential(signal),
    enabled,
    gcTime: 0,
  })
}

export function useQueueKioskCredentialMutation() {
  const { session } = useAuth()
  const queryClient = useQueryClient()
  const accountingUserId =
    session?.role === "accounting_staff" ? session.userId : null
  const activeViewerRef = useRef(accountingUserId)
  const controllersRef = useRef(new Map<string, Set<AbortController>>())

  const abortForViewer = useCallback((userId: string) => {
    const controllers = controllersRef.current.get(userId)
    if (!controllers) return

    for (const controller of controllers) {
      controller.abort()
    }
    controllersRef.current.delete(userId)
  }, [])

  useLayoutEffect(() => {
    activeViewerRef.current = accountingUserId

    return () => {
      if (accountingUserId !== null) {
        abortForViewer(accountingUserId)
        if (activeViewerRef.current === accountingUserId) {
          activeViewerRef.current = null
        }
        queryClient.removeQueries({
          queryKey: queueKioskCredentialQueryKey(accountingUserId),
          exact: true,
        })
      }
    }
  }, [abortForViewer, accountingUserId, queryClient])

  const mutation = useMutation({
    gcTime: 0,
    mutationFn: async ({ input, accountingUserId }: RotationRequest) => {
      const controller = new AbortController()
      const controllers =
        controllersRef.current.get(accountingUserId) ?? new Set()
      controllers.add(controller)
      controllersRef.current.set(accountingUserId, controllers)

      try {
        const credential = await updateQueueKioskCredential(
          input,
          controller.signal,
        )

        if (activeViewerRef.current !== accountingUserId) {
          throw new QueueKioskCredentialMutationCancelledError()
        }

        return credential
      } catch (error) {
        if (controller.signal.aborted) {
          throw new QueueKioskCredentialMutationCancelledError()
        }

        throw error
      } finally {
        controllers.delete(controller)
        if (controllers.size === 0) {
          controllersRef.current.delete(accountingUserId)
        }
      }
    },
    onSuccess: (credential, variables) => {
      const queryKey = queueKioskCredentialQueryKey(variables.accountingUserId)
      if (activeViewerRef.current === variables.accountingUserId) {
        queryClient.setQueryData(queryKey, credential)
        return
      }

      queryClient.removeQueries({ queryKey, exact: true })
    },
    onSettled: (_data, _error, variables) => {
      if (activeViewerRef.current !== variables.accountingUserId) {
        queryClient.removeQueries({
          queryKey: queueKioskCredentialQueryKey(variables.accountingUserId),
          exact: true,
        })
      }
    },
  })

  const mutateAsync = useCallback(
    (input: UpdateQueueKioskCredentialInput) => {
      if (accountingUserId === null) {
        return Promise.reject(
          new Error("Queue kiosk credentials require an Accounting viewer."),
        )
      }

      return mutation.mutateAsync({ input, accountingUserId })
    },
    [accountingUserId, mutation],
  )

  return { ...mutation, mutateAsync }
}

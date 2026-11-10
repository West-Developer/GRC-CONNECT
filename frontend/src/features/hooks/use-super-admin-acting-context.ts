"use client"

import { useCallback, useEffect, useState } from "react"
import { useRouter } from "next/navigation"
import { useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

import { useAuth } from "@/features/auth/use-auth"
import {
  clearActingContext,
  switchActingContext,
} from "@/features/services/super-admin-service"
import { fetchCurrentUser } from "@/features/services/auth-service"
import { setActingContextConflictHandler } from "@/features/services/api-client"
import type { UpdateActingContextPayload } from "@/features/schemas/super-admin-schema"

export function useSuperAdminActingContext() {
  const { session, replaceSession } = useAuth()
  const queryClient = useQueryClient()
  const router = useRouter()
  const [isPending, setIsPending] = useState(false)

  const isSuperAdmin = Boolean(session?.superAdmin)
  const isActing = Boolean(session?.superAdmin?.actingContext)
  const actingContext = session?.superAdmin?.actingContext ?? null

  const switchTo = useCallback(
    async (payload: UpdateActingContextPayload) => {
      setIsPending(true)
      try {
        const response = await switchActingContext(payload)
        replaceSession(response.data)
        await queryClient.cancelQueries()
        queryClient.clear()
        router.replace("/portal")
      } finally {
        setIsPending(false)
      }
    },
    [replaceSession, queryClient, router],
  )

  const exit = useCallback(async () => {
    setIsPending(true)
    try {
      const response = await clearActingContext()
      replaceSession(response.data)
      await queryClient.cancelQueries()
      queryClient.clear()
      router.replace("/portal")
    } finally {
      setIsPending(false)
    }
  }, [replaceSession, queryClient, router])

  useEffect(() => {
    if (!isSuperAdmin) {
      return
    }

    setActingContextConflictHandler(async () => {
      try {
        const user = await fetchCurrentUser()
        replaceSession(user)
        await queryClient.cancelQueries()
        queryClient.clear()
        toast.info("Your workspace changed in another tab — refreshed.")
      } catch {
        // Ignore transient error during conflict resync
      }
    })

    return () => {
      setActingContextConflictHandler(() => undefined)
    }
  }, [isSuperAdmin, replaceSession, queryClient])

  return {
    isSuperAdmin,
    isActing,
    actingContext,
    isPending,
    switchTo,
    exit,
  }
}

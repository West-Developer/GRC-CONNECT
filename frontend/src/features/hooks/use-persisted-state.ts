"use client"

import { useCallback, useState } from "react"

import { useAuth } from "@/features/auth/use-auth"
import { browserFormDraftStore } from "@/features/services/form-draft-store"

/**
 * `useState` for one plain value (a search box, a filter) that should still be there when the
 * person switches tabs or opens another page and comes back. Saved per user in `sessionStorage`
 * through the same store as `useDraftForm`, so it expires and is wiped when the session ends.
 * Name the type at the call site — `usePersistedState<string>(key, "")` — or TypeScript narrows it
 * to the literal you pass. Nothing is saved once the value is back to `initial`, or without a
 * signed-in user.
 */
export function usePersistedState<TValue extends string | number | boolean>(
  key: string,
  initial: TValue,
): [TValue, (value: TValue) => void] {
  const { session } = useAuth()
  const userId = session?.userId ?? null

  const [value, setValue] = useState<TValue>(() => {
    if (userId === null) {
      return initial
    }

    const saved = browserFormDraftStore.read<{ value?: unknown }>(userId, key)

    return saved !== null && typeof saved.value === typeof initial
      ? (saved.value as TValue)
      : initial
  })

  const update = useCallback(
    (next: TValue) => {
      setValue(next)

      if (userId === null) {
        return
      }

      if (next === initial) {
        browserFormDraftStore.clear(userId, key)
      } else {
        browserFormDraftStore.write(userId, key, { value: next })
      }
    },
    [initial, key, userId],
  )

  return [value, update]
}

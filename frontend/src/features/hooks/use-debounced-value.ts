"use client"

import { useEffect, useRef, useState } from "react"

/**
 * Returns a debounced copy of `value` that only updates after `delayMs`
 * milliseconds of inactivity. Use this to prevent a search box from firing
 * a server query on every keystroke.
 *
 * @example
 *   const [search, setSearch] = useState("")
 *   const debouncedSearch = useDebouncedValue(search, 300)
 *   // pass debouncedSearch to the query, setSearch to the input
 */
export function useDebouncedValue<T>(value: T, delayMs = 300): T {
  const [debouncedValue, setDebouncedValue] = useState<T>(value)
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    if (timerRef.current !== null) {
      clearTimeout(timerRef.current)
    }
    timerRef.current = setTimeout(() => {
      setDebouncedValue(value)
    }, delayMs)

    return () => {
      if (timerRef.current !== null) {
        clearTimeout(timerRef.current)
      }
    }
  }, [value, delayMs])

  return debouncedValue
}


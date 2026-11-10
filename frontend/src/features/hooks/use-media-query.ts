"use client"

import { useSyncExternalStore } from "react"

/**
 * Whether a CSS media query currently matches, kept in step with the viewport.
 *
 * Built on `useSyncExternalStore` (rather than state set from an effect) so it
 * is correct on the first client render and stays clear of the React Compiler's
 * set-state-in-effect rule. The server snapshot is `false`: the portal only
 * renders after session restore, so there is no server-rendered markup to
 * mismatch.
 */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (onChange) => {
      const list = window.matchMedia(query)
      list.addEventListener("change", onChange)

      return () => list.removeEventListener("change", onChange)
    },
    () => window.matchMedia(query).matches,
    () => false,
  )
}

/**
 * Below Tailwind's `md` breakpoint (48rem): phones and small tablets in
 * portrait. Use it to swap a wide layout for a stacked/progressive one; keep
 * pure-CSS `md:` classes for anything that does not change what is rendered.
 */
export const PHONE_QUERY = "(max-width: 47.99rem)"

export function useIsPhone(): boolean {
  return useMediaQuery(PHONE_QUERY)
}

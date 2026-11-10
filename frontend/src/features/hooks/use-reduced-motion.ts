"use client"

import { useEffect, useState } from "react"

/**
 * Returns true when the user has requested reduced motion via
 * `prefers-reduced-motion: reduce`. All GSAP animation hooks in this app
 * check this before running any animation, so the OS/browser preference is
 * always respected.
 *
 * The lazy initialiser reads matchMedia synchronously so the initial value
 * is immediately correct on the client — this matters because useGSAP
 * runs via useLayoutEffect (before useEffect), so a deferred setState
 * would always give GSAP the wrong value on the first render.
 */
export function useReducedMotion(): boolean {
  const [reducedMotion, setReducedMotion] = useState<boolean>(() => {
    if (typeof window === "undefined") return false
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches
  })

  useEffect(() => {
    const mq = window.matchMedia("(prefers-reduced-motion: reduce)")
    // Keep in sync if the OS preference changes after mount
    function handleChange(event: MediaQueryListEvent) {
      setReducedMotion(event.matches)
    }
    mq.addEventListener("change", handleChange)
    return () => mq.removeEventListener("change", handleChange)
  }, [])

  return reducedMotion
}

"use client"

import { useEffect, useRef, useState } from "react"

/**
 * The current width of an element's box, in pixels, kept up to date as it is resized (the sidebar opening or
 * collapsing included). It is 0 until the element has been measured, and wherever there is no layout
 * (tests), so a component can treat 0 as "not known" and show its normal layout.
 *
 * Use it for a layout that must change by the room it is given, not by the screen: a screen-width media query
 * cannot see the sidebar, and a fixed-width grid inside a box that is narrower than the grid has to scroll
 * sideways.
 */
export function useElementWidth<T extends HTMLElement>() {
  const ref = useRef<T | null>(null)
  const [width, setWidth] = useState(0)

  useEffect(() => {
    const element = ref.current
    if (!element || typeof ResizeObserver === "undefined") return

    // The observer reports the size once as soon as it starts watching, then on every change.
    const observer = new ResizeObserver(() => setWidth(element.clientWidth))
    observer.observe(element)

    return () => observer.disconnect()
  }, [])

  return [ref, width] as const
}

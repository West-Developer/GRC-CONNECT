"use client"

import { useCallback, useState } from "react"

/**
 * Per-viewer convenience only (stakeholder Doc 14): the desktop portal sidebar
 * can fold into an icon rail. Storage can be blocked or throw (private window,
 * cleared site data), so every access is guarded and the sidebar simply starts
 * expanded when nothing can be read.
 */
export const SIDEBAR_COLLAPSED_STORAGE_KEY = "grc.portal.sidebar-collapsed"

function readStoredCollapsed(): boolean {
  if (typeof window === "undefined") {
    return false
  }

  try {
    return window.localStorage.getItem(SIDEBAR_COLLAPSED_STORAGE_KEY) === "1"
  } catch {
    return false
  }
}

function writeStoredCollapsed(collapsed: boolean): void {
  try {
    window.localStorage.setItem(
      SIDEBAR_COLLAPSED_STORAGE_KEY,
      collapsed ? "1" : "0",
    )
  } catch {
    // Not persisted; the choice still applies until the page is reloaded.
  }
}

export function useSidebarCollapsed() {
  const [collapsed, setCollapsed] = useState<boolean>(readStoredCollapsed)

  const toggle = useCallback(() => {
    const next = !collapsed

    setCollapsed(next)
    writeStoredCollapsed(next)
  }, [collapsed])

  return { collapsed, toggle }
}

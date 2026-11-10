"use client"

import { QueryClientProvider } from "@tanstack/react-query"
import { useState, type ReactNode } from "react"
import { usePathname } from "next/navigation"

import { createApiAuthGateway } from "@/features/auth/api-auth-gateway"
import { AuthProvider } from "@/features/auth/auth-context"
import { createBrowserAuthTokenStore } from "@/features/auth/auth-token"
import { createAppQueryClient } from "@/features/lib/query-client"
import { Toaster } from "@/features/components/ui/toaster"
import { browserActingContextStore } from "@/features/auth/acting-context-store"
import {
  setActingContextProvider,
  setAuthTokenProvider,
} from "@/features/services/api-client"

/**
 * The application's composition root, replacing Vite's `main.tsx`.
 *
 * Everything here is created inside `useState` initializers rather than at
 * module scope. Under Next.js a module is shared across every request the
 * server handles, so a module-scope QueryClient or token provider would leak
 * one user's cache and credentials into another's request. `"use client"`
 * keeps this file out of the server bundle, and the lazy initializers keep it
 * correct even so.
 */
function PortalAuthBoundary({ children }: { children: ReactNode }) {
  const pathname = usePathname()
  const normalizedPathname = pathname.replace(/\/+$/, "") || "/"

  // `/queue` is the Cashier kiosk; `/queue/live` is the public live-queue link a
  // freshman is emailed (ADR 0044). Neither is a portal session.
  if (normalizedPathname === "/queue" || normalizedPathname === "/queue/live") {
    return children
  }

  return <PortalAuthProvider>{children}</PortalAuthProvider>
}

function PortalAuthProvider({ children }: { children: ReactNode }) {
  const [gateway] = useState(() => {
    const tokenStore = createBrowserAuthTokenStore()

    // The API client reaches the token through this provider so
    // `auth-token.ts` remains the only module that touches token storage
    // (PRD §9.1). The unauthorized handler is registered by `AuthProvider`
    // itself (auth-context.tsx), since clearing the token alone would leave a
    // stale authenticated view rendered — it also needs to flip React state.
    setAuthTokenProvider(() => tokenStore.read())
    setActingContextProvider(() => browserActingContextStore.getHeaderValue())

    return createApiAuthGateway(tokenStore)
  })

  return <AuthProvider gateway={gateway}>{children}</AuthProvider>
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(createAppQueryClient)

  return (
    <QueryClientProvider client={queryClient}>
      <PortalAuthBoundary>{children}</PortalAuthBoundary>
      <Toaster />
    </QueryClientProvider>
  )
}

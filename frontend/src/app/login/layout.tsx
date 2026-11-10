import { Suspense, type ReactNode } from "react"

import { AnonymousOnly } from "@/features/auth/auth-route-guards"

// `AnonymousOnly` reads `useSearchParams()` to honour `?returnTo=`, which Next
// requires be wrapped in a Suspense boundary.
export default function LoginLayout({ children }: { children: ReactNode }) {
  return (
    <Suspense>
      <AnonymousOnly>{children}</AnonymousOnly>
    </Suspense>
  )
}

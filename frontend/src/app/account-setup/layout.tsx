import { Suspense, type ReactNode } from "react"

import { AnonymousOnly } from "@/features/auth/auth-route-guards"

export default function AccountSetupLayout({
  children,
}: {
  children: ReactNode
}) {
  return (
    <Suspense>
      <AnonymousOnly>{children}</AnonymousOnly>
    </Suspense>
  )
}

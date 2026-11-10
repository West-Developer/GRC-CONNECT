import { Suspense } from "react"

import { UserGuidePage } from "@/features/components/pages/user-guide-page"

// `UserGuidePage` reads `useSearchParams()` for `?role=`, which Next requires
// be wrapped in a Suspense boundary.
export default function Page() {
  return (
    <Suspense>
      <UserGuidePage />
    </Suspense>
  )
}

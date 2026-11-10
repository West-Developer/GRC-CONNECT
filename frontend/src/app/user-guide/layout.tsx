import type { Metadata } from "next"
import type { ReactNode } from "react"

import "./guide.css"

export const metadata: Metadata = {
  title: "User manual and guide | GRC Connect",
  description:
    "Step-by-step help for every role in the GRC enrollment system, with screenshots of the real pages and a PDF to download.",
}

// A public page: it holds no student data and needs no sign-in, so the same
// link works before and after login and for every role.
export default function UserGuideLayout({ children }: { children: ReactNode }) {
  return children
}

import type { Metadata } from "next"
import type { ReactNode } from "react"

export const metadata: Metadata = {
  title: "Data Privacy Notice | GRC Connect",
  description:
    "What personal data the GRC enrollment system keeps, why, who can see it, and your rights under the Data Privacy Act of 2012.",
}

// A public page: it holds no student data and needs no sign-in, so the same
// link works from every form and before anyone has signed in.
export default function PrivacyLayout({ children }: { children: ReactNode }) {
  return children
}

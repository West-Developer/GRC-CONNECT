import type { Metadata, Viewport } from "next"
import type { ReactNode } from "react"

import { Providers } from "@/app/providers"

import "./globals.css"

export const metadata: Metadata = {
  title: "GRC Automated Enrollment System",
  description:
    "Role-guided enrollment for Global Reciprocal Colleges.",
  icons: { icon: "/favicon.svg" },
}

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  themeColor: "#c8102e",
}

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en-PH">
      <body>
        <Providers>{children}</Providers>
      </body>
    </html>
  )
}

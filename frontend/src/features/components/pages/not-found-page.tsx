import Link from "next/link"

import { Button } from "@/features/components/ui/button"

export function NotFoundPage() {
  return (
    <main className="grid min-h-svh place-items-center bg-background px-6 text-foreground">
      <section className="flex max-w-xl flex-col items-start gap-5">
        <p className="text-sm font-medium tracking-[0.2em] uppercase">
          GRC Enrollment
        </p>
        <h1 className="font-heading text-5xl tracking-tight">Page not found</h1>
        <p className="text-muted-foreground">
          The requested page is not part of the enrollment portal.
        </p>
        <Button asChild>
          <Link href="/">Return home</Link>
        </Button>
      </section>
    </main>
  )
}

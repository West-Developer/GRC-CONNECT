import { ArrowRight, ExternalLink, Menu } from "lucide-react"
import Link from "next/link"

import { GrcBrand } from "@/features/components/common/grc-brand"
import { Button } from "@/features/components/ui/button"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/features/components/ui/sheet"

const navigationItems = [
  { href: "#about-grc", label: "About GRC" },
  { href: "#academics", label: "Academics" },
  { href: "#student-services", label: "Student Services" },
  { href: "#enrollment", label: "Enrollment" },
  { href: "/user-guide", label: "User Guide" },
] as const

function PublicNavigation({ mobile = false }: { mobile?: boolean }) {
  return (
    <nav
      className={mobile ? "public-navigation public-navigation--mobile" : "public-navigation"}
      aria-label="Public navigation"
    >
      {navigationItems.map((item) => (
        <a key={item.href} href={item.href}>
          {item.label}
        </a>
      ))}
      <a href="https://grc.edu.ph/">
        Visit GRC Website
        <ExternalLink data-icon="inline-end" aria-hidden="true" />
      </a>
    </nav>
  )
}

export function PublicHeader() {
  return (
    <header className="site-masthead public-masthead">
      <Sheet>
        <SheetTrigger asChild>
          <Button
            className="public-mobile-trigger"
            type="button"
            variant="outline"
            size="icon"
            aria-label="Open public navigation"
          >
            <Menu aria-hidden="true" />
          </Button>
        </SheetTrigger>
        <SheetContent side="left" className="public-mobile-sheet">
          <SheetHeader>
            <SheetTitle>Explore GRC</SheetTitle>
            <SheetDescription>
              Learn more about Global Reciprocal Colleges and access the enrollment portal.
            </SheetDescription>
          </SheetHeader>
          <div className="public-mobile-sheet__body">
            <PublicNavigation mobile />
          </div>
        </SheetContent>
      </Sheet>

      <Link
        className="institutional-identity rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
        href="/"
      >
        <GrcBrand />
      </Link>

      <PublicNavigation />

      <div className="public-masthead__actions">
        <Button asChild>
          <Link href="/login">
            Sign in to portal
            <ArrowRight data-icon="inline-end" aria-hidden="true" />
          </Link>
        </Button>
      </div>
    </header>
  )
}

"use client"

import { ArrowRight, Download } from "lucide-react"
import Image from "next/image"
import Link from "next/link"
import { useSearchParams } from "next/navigation"
import { useEffect } from "react"

import { GuideChapterView } from "@/features/components/guide/guide-chapter"
import { GuideContents } from "@/features/components/guide/guide-contents"
import { GuideRail } from "@/features/components/guide/guide-rail"
import { GrcBrand } from "@/features/components/common/grc-brand"
import { PublicFooter } from "@/features/components/layouts/public-footer"
import { Button } from "@/features/components/ui/button"
import {
  chaptersForSelection,
  guidePdfPath,
  guideRoleLabel,
  parseGuideSelection,
} from "@/features/lib/user-guide"
import { userGuideShots } from "@/features/lib/user-guide/shots"

export const GUIDE_EDITION = "Edition 1.0, October 2026"

export function UserGuidePage() {
  const params = useSearchParams()
  const selection = parseGuideSelection(params.get("role"))
  const chapters = chaptersForSelection(selection)
  const heroShot = userGuideShots["hero-portal"]
  const roleSelected = selection !== null && selection !== "all"

  // The chapters are drawn after the page loads, so the browser's own jump to
  // an address like /user-guide?role=student#student-enrollment finds nothing
  // yet. Jump once the chosen chapters exist.
  useEffect(() => {
    const target = decodeURIComponent(window.location.hash.slice(1))
    if (target) {
      document.getElementById(target)?.scrollIntoView()
    }
  }, [selection])

  return (
    <div className="guide-page">
      <section className="guide-cover" aria-hidden="true">
        <Image
          src="/grc-logo-white.png"
          alt=""
          width={281}
          height={108}
          priority
        />
        <p className="guide-cover__title">GRC Enrollment System</p>
        <p className="guide-cover__subtitle">User manual and guide</p>
        <p className="guide-cover__for">
          {roleSelected
            ? `${guideRoleLabel(selection)} edition, for Global Reciprocal Colleges`
            : "For the students, faculty and staff of Global Reciprocal Colleges"}
        </p>
        <p className="guide-cover__edition">{GUIDE_EDITION}</p>
      </section>

      <header className="site-masthead public-masthead guide-masthead">
        <Link
          className="institutional-identity rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
          href="/"
        >
          <GrcBrand />
        </Link>
        <div className="public-masthead__actions">
          <Button asChild variant="outline">
            <Link href="/">Home</Link>
          </Button>
          <Button asChild>
            <Link href="/login">
              Sign in to portal
              <ArrowRight data-icon="inline-end" aria-hidden="true" />
            </Link>
          </Button>
        </div>
      </header>

      <main id="guide-main" className="guide-main-landmark">
        <section className="guide-hero" aria-labelledby="guide-title">
          <div className="guide-hero__copy">
            <h1 id="guide-title">How to use GRC Connect</h1>
            <p className="guide-hero__lede">
              A guide and manual for every role at Global Reciprocal Colleges:
              what each page is for, how to finish each task, and what to check
              when something looks wrong.
            </p>
            <p className="guide-hero__edition">{GUIDE_EDITION}</p>
            <div className="guide-hero__actions">
              <Button asChild size="lg">
                <a href={guidePdfPath(selection)} download>
                  <Download data-icon="inline-start" aria-hidden="true" />
                  {roleSelected
                    ? `Download the ${guideRoleLabel(selection)} PDF`
                    : "Download the complete PDF"}
                </a>
              </Button>
              {roleSelected ? (
                <Button asChild size="lg" variant="outline">
                  <a href={guidePdfPath(null)} download>
                    Complete PDF
                  </a>
                </Button>
              ) : null}
              <Button asChild size="lg" variant="outline">
                <a href="#contents">Choose your role</a>
              </Button>
            </div>
          </div>
          {heroShot ? (
            <div className="guide-hero__shot">
              {/* Plain img: fixed, already-optimised JPEG. */}
              <img
                src={heroShot.src}
                width={heroShot.width}
                height={heroShot.height}
                alt={heroShot.alt}
                decoding="async"
              />
            </div>
          ) : null}
        </section>

        <section id="contents" className="guide-contents-section">
          <GuideContents selection={selection} />
        </section>

        <div className="guide-body">
          <GuideRail chapters={chapters} selection={selection} />
          <div className="guide-chapters">
            {chapters.map((chapter) => (
              <GuideChapterView key={chapter.id} chapter={chapter} />
            ))}
          </div>
        </div>

        {selection === null ? (
          <section className="guide-prompt" aria-label="Next step">
            <p>
              The pages above work for everyone. To see the pages and tasks of
              your own role, choose it in the list at the top, or{" "}
              <Link href="/user-guide?role=all#contents">
                read every role&apos;s guide on one page
              </Link>
              .
            </p>
          </section>
        ) : null}

        <p className="guide-allroles">
          {selection === "all" ? (
            <Link href="/user-guide#contents">Show only my role</Link>
          ) : (
            <Link href="/user-guide?role=all#contents">
              Show every role&apos;s guide on one page
            </Link>
          )}
        </p>
      </main>

      <PublicFooter />
    </div>
  )
}

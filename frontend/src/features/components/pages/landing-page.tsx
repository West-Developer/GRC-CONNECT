"use client"

import { useGSAP } from "@gsap/react"
import {
  ArrowRight,
  BookOpenCheck,
  Building2,
  CheckCheck,
  ClipboardList,
  ExternalLink,
  GraduationCap,
  HeartHandshake,
  Landmark,
  LibraryBig,
  School,
  Sparkles,
} from "lucide-react"
import Link from "next/link"
import { useRef } from "react"

import { PublicFooter } from "@/features/components/layouts/public-footer"
import { PublicHeader } from "@/features/components/layouts/public-header"
import { Button } from "@/features/components/ui/button"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/features/components/ui/card"
import { useReducedMotion } from "@/features/hooks/use-reduced-motion"
import { gsap, ScrollTrigger } from "@/features/lib/gsap"

const academics = [
  {
    href: "https://grc.edu.ph/college-of-business-administration/",
    label: "College of Business Administration",
  },
  {
    href: "https://grc.edu.ph/college-of-entrepreneurship/",
    label: "College of Entrepreneurship",
  },
  {
    href: "https://grc.edu.ph/college-of-accountancy/",
    label: "College of Accountancy",
  },
  {
    href: "https://grc.edu.ph/college-of-education/",
    label: "College of Education",
  },
  {
    href: "https://grc.edu.ph/college-of-computer-studies/",
    label: "College of Computer Studies",
  },
] as const

const studentServices = [
  {
    description:
      "Learn about admission requirements and the GRC admission process.",
    href: "https://grc.edu.ph/grc-admission/",
    icon: GraduationCap,
    label: "Admissions",
  },
  {
    description:
      "Explore scholarship information and application guidance from GRC.",
    href: "https://grc.edu.ph/grc-scholarship/",
    icon: HeartHandshake,
    label: "Scholarship",
  },
  {
    description: "Access GRC Library information and learning resources.",
    href: "https://grc.edu.ph/grc-library/",
    icon: LibraryBig,
    label: "GRC Library",
  },
] as const

const enrollmentJourney = [
  {
    title: "Schedule preparation",
    description:
      "Academic teams prepare curriculum, sections, faculty assignments, and reviewable schedules.",
    icon: ClipboardList,
  },
  {
    title: "Student subject selection and submission",
    description:
      "Students review eligible sections or subjects and submit an enrollment request.",
    icon: BookOpenCheck,
  },
  {
    title: "Authorized review and approval",
    description:
      "The right offices review each request through role-based enrollment controls.",
    icon: CheckCheck,
  },
  {
    title: "Payment confirmation and COR finalization",
    description:
      "Accounting confirms payment before the Certificate of Registration is finalized.",
    icon: Building2,
  },
] as const

export function LandingPage() {
  const reducedMotion = useReducedMotion()
  const containerRef = useRef<HTMLDivElement>(null)

  useGSAP(
    () => {
      if (reducedMotion) return

      // ── Hero: stagger-reveal copy elements ───────────────────────────────
      const heroTl = gsap.timeline({ defaults: { ease: "power2.out" } })

      heroTl
        .from(".landing-tagline", {
          opacity: 0,
          y: 18,
          duration: 0.55,
        })
        .from(
          ".landing-hero h1",
          {
            opacity: 0,
            y: 28,
            duration: 0.65,
          },
          "-=0.3",
        )
        .from(
          ".landing-hero__summary",
          {
            opacity: 0,
            y: 18,
            duration: 0.55,
          },
          "-=0.35",
        )
        .from(
          ".landing-actions",
          {
            opacity: 0,
            y: 14,
            duration: 0.45,
          },
          "-=0.3",
        )

      // ── Hero panel: slide in from the right ───────────────────────────────
      gsap.from(".landing-hero__panel", {
        opacity: 0,
        x: 40,
        duration: 0.8,
        ease: "power2.out",
        delay: 0.25,
      })

      // ── About section: heading fade-up on scroll ──────────────────────────
      gsap.from("#about-grc .section-heading", {
        scrollTrigger: {
          trigger: "#about-grc",
          start: "top 82%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        y: 30,
        duration: 0.65,
        ease: "power2.out",
      })

      gsap.from(".landing-values-grid article", {
        scrollTrigger: {
          trigger: ".landing-values-grid",
          start: "top 80%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        y: 24,
        duration: 0.55,
        ease: "power2.out",
        stagger: 0.15,
      })

      // ── Academics section: heading + list stagger on scroll ───────────────
      gsap.from("#academics .section-heading", {
        scrollTrigger: {
          trigger: "#academics",
          start: "top 82%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        y: 30,
        duration: 0.6,
        ease: "power2.out",
      })

      gsap.from(".academics-list li", {
        scrollTrigger: {
          trigger: ".academics-list",
          start: "top 80%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        x: -20,
        duration: 0.45,
        ease: "power2.out",
        stagger: 0.1,
      })

      // ── Student services: heading + cards stagger on scroll ───────────────
      gsap.from("#student-services .section-heading", {
        scrollTrigger: {
          trigger: "#student-services",
          start: "top 82%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        y: 30,
        duration: 0.6,
        ease: "power2.out",
      })

      gsap.from(".student-services-grid [data-slot='card']", {
        scrollTrigger: {
          trigger: ".student-services-grid",
          start: "top 80%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        y: 28,
        duration: 0.5,
        ease: "power2.out",
        stagger: 0.13,
      })

      // ── Journey section: heading + steps stagger on scroll ────────────────
      gsap.from("#enrollment .section-heading", {
        scrollTrigger: {
          trigger: "#enrollment",
          start: "top 82%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        y: 30,
        duration: 0.6,
        ease: "power2.out",
      })

      gsap.from(".journey-list li", {
        scrollTrigger: {
          trigger: ".journey-list",
          start: "top 78%",
          toggleActions: "play none none none",
        },
        opacity: 0,
        y: 32,
        duration: 0.5,
        ease: "power2.out",
        stagger: 0.12,
      })

      return () => {
        ScrollTrigger.getAll().forEach((t) => t.kill())
      }
    },
    { scope: containerRef, dependencies: [reducedMotion] },
  )

  return (
    <div className="institutional-shell" ref={containerRef}>
      <a className="skip-link" href="#main-content">
        Skip to main content
      </a>
      <PublicHeader />

      <main id="main-content" tabIndex={-1}>
        <section className="landing-hero" aria-labelledby="landing-title">
          <div className="landing-hero__copy">
            <p className="landing-tagline">
              <Sparkles aria-hidden="true" />
              TOUCHING HEARTS, RENEWING MINDS, TRANSFORMING LIVES
            </p>
            <h1 id="landing-title">Your GRC enrollment journey starts here.</h1>
            <p className="landing-hero__summary">
              The GRC Automated Enrollment System brings schedules, subject
              selection, approvals, payment confirmation, and your Certificate
              of Registration together in one role-guided experience.
            </p>
            <div className="landing-actions">
              <Button asChild size="lg">
                <Link href="/login">
                  Sign in to portal
                  <ArrowRight data-icon="inline-end" aria-hidden="true" />
                </Link>
              </Button>
              <Button asChild size="lg" variant="outline">
                <a href="#enrollment">How enrollment works</a>
              </Button>
            </div>
          </div>

          <aside
            className="landing-hero__panel"
            aria-label="GRC enrollment portal"
          >
            <School aria-hidden="true" />
            <p>GRC Connect</p>
            <strong>
              Your role. Your next enrollment task. One connected portal.
            </strong>
            <span>
              Sign in to continue with the enrollment work assigned to your
              role.
            </span>
          </aside>
        </section>

        <section
          id="about-grc"
          className="landing-values-section"
          aria-label="About Global Reciprocal Colleges"
        >
          <div className="section-heading">
            <div>
              <p className="eyebrow">
                <Landmark aria-hidden="true" />
                About GRC
              </p>
              <h2 id="about-grc-title">Education with values at the center.</h2>
            </div>
            <p>
              The enrollment experience reflects the same clarity, care, and
              responsibility that guide Global Reciprocal Colleges.
            </p>
          </div>
          <div
            className="landing-values-grid"
            aria-label="About Global Reciprocal Colleges"
          >
            <article className="landing-value-card">
              <h3 className="eyebrow">Vision</h3>
              <p className="landing-value-card__statement">
                A global community of excellent individuals with values.
              </p>
            </article>
            <article className="landing-value-card">
              <h3 className="eyebrow">Mission</h3>
              <p className="landing-value-card__statement">
                Values-based quality education that develops successful,
                socially responsible, morally upright, skilled workers and
                highly competent professionals.
              </p>
            </article>
          </div>
        </section>

        <section
          id="academics"
          className="academics-section"
          aria-labelledby="academics-title"
        >
          <div className="section-heading">
            <div>
              <p className="eyebrow">Academics</p>
              <h2 id="academics-title">
                Explore GRC&apos;s academic colleges.
              </h2>
            </div>
            <p>
              Learn about the programs and academic communities offered by
              Global Reciprocal Colleges.
            </p>
          </div>
          <ul className="academics-list" aria-label="GRC academics">
            {academics.map((college, index) => (
              <li key={college.href}>
                <a href={college.href}>
                  <span>{String(index + 1).padStart(2, "0")}</span>
                  <strong>{college.label}</strong>
                  <ExternalLink aria-hidden="true" />
                </a>
              </li>
            ))}
          </ul>
        </section>

        <section
          id="student-services"
          className="student-services-section"
          aria-label="Student services"
        >
          <div className="section-heading">
            <div>
              <p className="eyebrow">Student services</p>
              <h2 id="student-services-title">Find the support you need.</h2>
            </div>
            <p>
              Official GRC services remain available alongside the enrollment
              portal whenever you need them.
            </p>
          </div>
          <div className="student-services-grid" aria-label="Student services">
            {studentServices.map((service) => {
              const Icon = service.icon

              return (
                <Card key={service.href} className="student-service-card">
                  <CardHeader>
                    <Icon aria-hidden="true" />
                    <CardTitle level={3}>{service.label}</CardTitle>
                    <CardDescription>{service.description}</CardDescription>
                  </CardHeader>
                  <CardContent>
                    <a href={service.href}>
                      Visit {service.label}
                      <ExternalLink data-icon="inline-end" aria-hidden="true" />
                    </a>
                  </CardContent>
                </Card>
              )
            })}
          </div>
        </section>

        <section
          id="enrollment"
          className="journey-section"
          aria-labelledby="journey-title"
        >
          <div className="section-heading">
            <div>
              <p className="eyebrow">Enrollment at GRC</p>
              <h2 id="journey-title">How enrollment works</h2>
            </div>
            <p>
              Every handoff is assigned to an authorized role, making the
              process clear for students and accountable for GRC offices.
            </p>
          </div>

          <ol className="journey-list">
            {enrollmentJourney.map((step, index) => {
              const Icon = step.icon

              return (
                <li key={step.title}>
                  <div className="journey-list__index">
                    <span>{String(index + 1).padStart(2, "0")}</span>
                    <Icon aria-hidden="true" />
                  </div>
                  <div>
                    <h3>{step.title}</h3>
                    <p>{step.description}</p>
                  </div>
                </li>
              )
            })}
          </ol>
        </section>
      </main>

      <PublicFooter />
    </div>
  )
}

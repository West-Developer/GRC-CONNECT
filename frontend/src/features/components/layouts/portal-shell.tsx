"use client"

import { useGSAP } from "@gsap/react"
import {
  BookOpen,
  LayoutDashboard,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  ShieldCheck,
} from "lucide-react"
import Link from "next/link"
import { useParams, usePathname, useRouter } from "next/navigation"
import {
  startTransition,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from "react"
import { createPortal } from "react-dom"

import { useAuth } from "@/features/auth/use-auth"
import { Breadcrumb } from "@/features/components/common/breadcrumb"
import { GrcBrand } from "@/features/components/common/grc-brand"
import { Alert, AlertDescription } from "@/features/components/ui/alert"
import { Avatar, AvatarFallback } from "@/features/components/ui/avatar"
import { Badge } from "@/features/components/ui/badge"
import { Button } from "@/features/components/ui/button"
import { Separator } from "@/features/components/ui/separator"
import {
  Sheet,
  SheetClose,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/features/components/ui/sheet"
import { EnrollmentDeadlineBanner } from "@/features/components/portal/enrollment-deadline-banner"
import { PortalNotificationSheet } from "@/features/components/portal/portal-notification-sheet"
import { useAcademicTermsQuery } from "@/features/hooks/use-reference-data"
import { useScheduleProposalsQuery } from "@/features/hooks/use-scheduling"
import { useOwnStudentProfileQuery } from "@/features/hooks/use-student-records"
import {
  isPathOpenWhileRestricted,
  isPortalRestricted,
  RESTRICTED_STUDENT_MODULE_ID,
  RESTRICTED_STUDENT_MODULE_IDS,
} from "@/features/lib/enrollment-deadline"
import { isGuideRole } from "@/features/lib/user-guide/roles"
import { cn } from "@/features/lib/utils"
import {
  formatAcademicTerm,
  getActiveAcademicTerm,
} from "@/features/services/reference-data-service"
import {
  getRoleModule,
  rolePortalDefinitions,
  type RolePortalDefinition,
} from "@/features/portal/role-capabilities"
import { useReducedMotion } from "@/features/hooks/use-reduced-motion"
import { useProgramChairReviewCountsQuery } from "@/features/hooks/use-program-chair-review-counts"
import { useSidebarCollapsed } from "@/features/hooks/use-sidebar-collapsed"
import { gsap } from "@/features/lib/gsap"
import { isConnectedModuleId } from "@/features/portal/module-registry"
import {
  SuperAdminBanner,
  SuperAdminSwitcher,
} from "@/features/components/portal/super-admin-switcher"

interface PortalNavigationProps {
  definition: RolePortalDefinition
  mobile?: boolean
  /** A newly created Transferee or Returnee: Enrollment and Grades are the only options until they are enrolled and paid. */
  restricted?: boolean
}

function NavigationLink({
  children,
  icon,
  label,
  badge,
  href,
  alsoActiveOn = [],
  mobile = false,
  attention = false,
  collapsed = false,
}: {
  children: ReactNode
  icon?: ReactNode
  label?: string
  badge?: ReactNode
  href: string
  /** Other paths that belong to this entry: its nested pages, for which it shows as the current page. */
  alsoActiveOn?: readonly string[]
  mobile?: boolean
  /** Shows a dot on the icon while the labels are folded away (a returned
      schedule would otherwise lose its "Returned" badge). */
  attention?: boolean
  collapsed?: boolean
}) {
  const pathname = usePathname()
  // react-router's NavLink used `end` only for "/portal"; module routes have no
  // children, so an exact comparison reproduces both cases.
  const isActive = pathname === href || alsoActiveOn.includes(pathname)
  const linkRef = useRef<HTMLAnchorElement>(null)
  const [hoverRect, setHoverRect] = useState<{
    top: number
    left: number
    width: number
    height: number
  } | null>(null)

  function handleOpenPill() {
    if (!collapsed || mobile || !linkRef.current) return
    const rect = linkRef.current.getBoundingClientRect()
    setHoverRect({
      top: rect.top,
      left: rect.left,
      width: rect.width,
      height: rect.height,
    })
  }

  function handleClosePill() {
    setHoverRect(null)
  }

  useEffect(() => {
    if (!hoverRect) return
    function handleScroll() {
      setHoverRect(null)
    }
    window.addEventListener("scroll", handleScroll, true)
    return () => window.removeEventListener("scroll", handleScroll, true)
  }, [hoverRect])

  // No `title` attribute: the CSS wipe-reveal pill (see globals.css) is the
  // only hover hint now. A native title tooltip would layer its own
  // OS-styled gray/black box on top after the browser's own hover delay,
  // duplicating the pill with a second, differently-styled popup.
  const link = (
    <Link
      ref={linkRef}
      href={href}
      data-attention={attention ? "true" : undefined}
      aria-current={isActive ? "page" : undefined}
      className={cn("portal-nav-link", isActive && "portal-nav-link--active")}
      onMouseEnter={handleOpenPill}
      onMouseLeave={handleClosePill}
      onFocus={handleOpenPill}
      onBlur={handleClosePill}
    >
      {children}
    </Link>
  )

  const portalPill =
    collapsed && !mobile && hoverRect && typeof document !== "undefined"
      ? createPortal(
          <div
            className="portal-collapsed-pill"
            style={{
              top: hoverRect.top,
              left: hoverRect.left,
              height: hoverRect.height,
            }}
          >
            <span
              className="portal-collapsed-pill__icon"
              style={{ width: hoverRect.width }}
            >
              {icon}
            </span>
            <span className="portal-collapsed-pill__label">
              <span>{label}</span>
              {badge}
            </span>
          </div>,
          document.body,
        )
      : null

  return (
    <>
      {mobile ? <SheetClose asChild>{link}</SheetClose> : link}
      {portalPill}
    </>
  )
}

function PortalNavigation({
  definition,
  mobile = false,
  hasReturnedSchedule = false,
  collapsed = false,
  restricted = false,
}: PortalNavigationProps & {
  hasReturnedSchedule?: boolean
  collapsed?: boolean
}) {
  // The Program Head's Irregular Advising and Credit Mappings live inside Enrollment, so the number waiting for
  // them shows on the Enrollment entry too (and per tab, on the page).
  const reviewCounts = useProgramChairReviewCountsQuery().data
  const waitingForChair =
    (reviewCounts?.irregular_advising ?? 0) +
    (reviewCounts?.credit_mappings ?? 0)

  return (
    <nav
      className="portal-navigation"
      aria-label={
        mobile ? "Mobile role portal navigation" : "Role portal navigation"
      }
    >
      {!restricted && (
        <NavigationLink
          href="/portal"
          mobile={mobile}
          collapsed={collapsed}
          label="GRC Connect"
          icon={<LayoutDashboard data-icon="inline-start" aria-hidden="true" />}
        >
          <LayoutDashboard data-icon="inline-start" aria-hidden="true" />
          <span>GRC Connect</span>
        </NavigationLink>
      )}
      {definition.modules
        .filter((module) => module.nestedUnder === undefined)
        .filter((module) => isConnectedModuleId(module.id))
        .filter(
          (module) =>
            !restricted ||
            (RESTRICTED_STUDENT_MODULE_IDS as readonly string[]).includes(
              module.id,
            ),
        )
        .map((module) => {
          const Icon = module.icon
          const isReturned =
            hasReturnedSchedule && module.id === "program-chair-enrollment"
          const waiting =
            module.id === "program-chair-enrollment" && !isReturned
              ? waitingForChair
              : 0
          const nestedHrefs = definition.modules
            .filter((candidate) => candidate.nestedUnder === module.id)
            .map((candidate) => `/portal/${candidate.id}`)

          return (
            <NavigationLink
              key={module.id}
              href={`/portal/${module.id}`}
              mobile={mobile}
              collapsed={collapsed}
              label={module.label}
              alsoActiveOn={nestedHrefs}
              icon={<Icon data-icon="inline-start" aria-hidden="true" />}
              badge={
                isReturned ? (
                  <Badge variant="destructive" className="shrink-0">
                    Returned
                  </Badge>
                ) : waiting > 0 ? (
                  <Badge variant="destructive" className="shrink-0">
                    {waiting > 99 ? "99+" : waiting}
                  </Badge>
                ) : undefined
              }
              attention={isReturned}
            >
              <Icon data-icon="inline-start" aria-hidden="true" />
              <span className="flex min-w-0 items-center justify-between gap-2">
                {module.label}
                {isReturned && (
                  <Badge variant="destructive" className="shrink-0">
                    Returned
                  </Badge>
                )}
                {waiting > 0 && (
                  <Badge variant="destructive" className="shrink-0">
                    <span aria-hidden="true">
                      {waiting > 99 ? "99+" : waiting}
                    </span>
                    <span className="sr-only">
                      {waiting} waiting for your review
                    </span>
                  </Badge>
                )}
              </span>
            </NavigationLink>
          )
        })}
    </nav>
  )
}

function PortalIdentity() {
  return (
    <div className="portal-brand">
      <GrcBrand compact />
    </div>
  )
}

export function PortalShell({ children }: { children: ReactNode }) {
  const { session, signOut, storageAvailable } = useAuth()
  const academicTermsQuery = useAcademicTermsQuery({
    enabled: session !== null,
  })
  const activeAcademicTerm = getActiveAcademicTerm(academicTermsQuery.data)
  const scheduleProposalsQuery = useScheduleProposalsQuery({
    enabled: session?.role === "program_chair",
  })
  const router = useRouter()
  const pathname = usePathname()
  // A newly created Transferee or Returnee sees Enrollment and Grades only until they are enrolled and have
  // paid (ADR 0048, amended by ADR 0050). Until the profile has loaded, or if it cannot be read, nobody is
  // restricted.
  const ownProfileQuery = useOwnStudentProfileQuery()
  const enrollmentDeadlineAt =
    session?.role === "student"
      ? (ownProfileQuery.data?.enrollment_deadline_at ?? null)
      : null
  const restricted = isPortalRestricted({
    enrollment_deadline_at: enrollmentDeadlineAt,
  })
  const mustOpenEnrollment = restricted && !isPathOpenWhileRestricted(pathname)

  useEffect(() => {
    if (mustOpenEnrollment) {
      router.replace(`/portal/${RESTRICTED_STUDENT_MODULE_ID}`)
    }
  }, [mustOpenEnrollment, router])
  const params = useParams<{ moduleId?: string }>()
  const reducedMotion = useReducedMotion()
  const sidebarRef = useRef<HTMLElement>(null)
  const contentRef = useRef<HTMLDivElement>(null)
  const { collapsed: sidebarCollapsed, toggle: toggleSidebar } =
    useSidebarCollapsed()

  // ── Sidebar nav links: stagger-in on first mount ─────────────────────────
  useGSAP(
    () => {
      if (reducedMotion || !session) return
      gsap.from(".portal-nav-link", {
        opacity: 0,
        x: -10,
        duration: 0.35,
        ease: "power2.out",
        stagger: 0.06,
        delay: 0.1,
      })
    },
    { scope: sidebarRef, dependencies: [reducedMotion, Boolean(session)] },
  )

  // ── Portal content area: fade-in on route change ─────────────────────────
  useGSAP(
    () => {
      if (reducedMotion || !session) return
      gsap.fromTo(
        ".portal-content",
        { opacity: 0, y: 10 },
        { opacity: 1, y: 0, duration: 0.4, ease: "power2.out" },
      )
    },
    {
      scope: contentRef,
      dependencies: [pathname, reducedMotion, Boolean(session)],
    },
  )

  if (!session) {
    return null
  }

  const moduleId = typeof params.moduleId === "string" ? params.moduleId : null
  const definition = rolePortalDefinitions[session.role]
  const activeModule = moduleId ? getRoleModule(session.role, moduleId) : null
  const hasReturnedSchedule =
    session.role === "program_chair" &&
    (scheduleProposalsQuery.data ?? []).some((proposal) => proposal.is_returned)
  const currentPageLabel =
    pathname === "/portal"
      ? "GRC Connect"
      : (activeModule?.label ?? "Unavailable workspace")
  const initials = session.displayName
    .split(/\s+/)
    .map((part) => part[0])
    .join("")
    .slice(0, 2)
    .toUpperCase()

  function handleSignOut() {
    // Navigate away before clearing the session, so RequireSession never sees
    // an anonymous user still sitting on /portal and bounces them to /login.
    // `router.replace` no longer returns a promise the way react-router's
    // `navigate` did, so a transition is what keeps the two batched together.
    startTransition(() => {
      router.replace("/")
      signOut()
    })
  }

  return (
    <div
      className="portal-app"
      data-sidebar={sidebarCollapsed ? "collapsed" : "expanded"}
    >
      <a className="skip-link" href="#portal-content">
        Skip to portal content
      </a>

      <aside id="portal-sidebar" className="portal-sidebar" ref={sidebarRef}>
        <div>
          {!sidebarCollapsed && <PortalIdentity />}
          <div className="portal-sidebar__toolbar">
            {!sidebarCollapsed && (
              <Badge variant="secondary">GRC Connect</Badge>
            )}
            <Button
              type="button"
              variant="outline"
              size="icon"
              className="portal-sidebar__toggle"
              onClick={toggleSidebar}
              aria-controls="portal-sidebar"
              aria-expanded={!sidebarCollapsed}
              aria-label={
                sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"
              }
              title={sidebarCollapsed ? "Expand sidebar" : "Collapse sidebar"}
            >
              {sidebarCollapsed ? (
                <PanelLeftOpen aria-hidden="true" />
              ) : (
                <PanelLeftClose aria-hidden="true" />
              )}
            </Button>
          </div>
        </div>

        <PortalNavigation
          definition={definition}
          hasReturnedSchedule={hasReturnedSchedule}
          collapsed={sidebarCollapsed}
          restricted={restricted}
        />

        <div className="portal-sidebar__footer">
          <p>
            {activeAcademicTerm
              ? formatAcademicTerm(activeAcademicTerm)
              : academicTermsQuery.isPending
                ? "Loading academic term…"
                : "No active academic term"}
          </p>
          <Separator />
          <div className="portal-profile" title={session.displayName}>
            <Avatar>
              <AvatarFallback>{initials}</AvatarFallback>
            </Avatar>
            <div>
              <strong>{session.displayName}</strong>
              <span>{definition.roleLabel}</span>
            </div>
          </div>
        </div>
      </aside>

      <div className="portal-main-column" ref={contentRef}>
        <header className="portal-topbar">
          <div className="portal-topbar__context">
            <Sheet>
              <SheetTrigger asChild>
                <Button
                  className="portal-mobile-trigger"
                  type="button"
                  variant="outline"
                  size="icon"
                  aria-label="Open portal navigation"
                >
                  <Menu aria-hidden="true" />
                </Button>
              </SheetTrigger>
              <SheetContent side="left" className="portal-mobile-sheet">
                <SheetHeader>
                  <SheetTitle>GRC Connect navigation</SheetTitle>
                  <SheetDescription>
                    {definition.roleLabel} workspace
                  </SheetDescription>
                </SheetHeader>
                <div className="portal-mobile-sheet__body">
                  <PortalIdentity />
                  <Badge variant="secondary">GRC Connect</Badge>
                  <SuperAdminSwitcher />
                  <PortalNavigation
                    definition={definition}
                    mobile
                    hasReturnedSchedule={hasReturnedSchedule}
                    restricted={restricted}
                  />
                  {isGuideRole(session.role) && (
                    <SheetClose asChild>
                      <Link
                        className="portal-mobile-guide"
                        href={`/user-guide?role=${session.role}`}
                      >
                        <BookOpen aria-hidden="true" />
                        User guide
                      </Link>
                    </SheetClose>
                  )}
                  <SheetClose asChild>
                    <Link className="portal-mobile-guide" href="/privacy">
                      <ShieldCheck aria-hidden="true" />
                      Data privacy
                    </Link>
                  </SheetClose>
                </div>
                <SheetFooter className="portal-mobile-sheet__footer">
                  <div className="portal-profile">
                    <Avatar>
                      <AvatarFallback>{initials}</AvatarFallback>
                    </Avatar>
                    <div>
                      <strong>{session.displayName}</strong>
                      <span>{definition.roleLabel}</span>
                    </div>
                  </div>
                  <SheetClose asChild>
                    <Button
                      type="button"
                      variant="outline"
                      className="portal-mobile-signout"
                      onClick={handleSignOut}
                      aria-label="Sign out"
                    >
                      <LogOut data-icon="inline-start" aria-hidden="true" />
                      Sign out
                    </Button>
                  </SheetClose>
                </SheetFooter>
              </SheetContent>
            </Sheet>

            <Breadcrumb
              items={
                pathname === "/portal"
                  ? [{ label: "GRC Connect" }]
                  : [
                      { label: "GRC Connect", href: "/portal" },
                      { label: currentPageLabel },
                    ]
              }
            />
          </div>

          <div className="portal-topbar__actions">
            <SuperAdminSwitcher />
            {isGuideRole(session.role) && (
              <Button
                asChild
                variant="ghost"
                size="sm"
                className="portal-topbar__help"
              >
                <Link href={`/user-guide?role=${session.role}`}>
                  <BookOpen data-icon="inline-start" aria-hidden="true" />
                  User guide
                </Link>
              </Button>
            )}
            <Button
              asChild
              variant="ghost"
              size="sm"
              className="portal-topbar__help"
            >
              <Link href="/privacy">
                <ShieldCheck data-icon="inline-start" aria-hidden="true" />
                Data privacy
              </Link>
            </Button>
            <PortalNotificationSheet />
            {/* Desktop only: whenever the hamburger drawer exists (<= 64rem) it
                carries Sign out at its bottom instead, so the bell alone takes
                the right-hand spot (stakeholder Doc 13). */}
            <Separator
              orientation="vertical"
              className="portal-topbar__signout"
            />
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="portal-topbar__signout"
              onClick={handleSignOut}
              aria-label="Sign out"
            >
              <LogOut data-icon="inline-start" aria-hidden="true" />
              Sign out
            </Button>
          </div>
        </header>

        <SuperAdminBanner />

        {enrollmentDeadlineAt !== null && (
          <EnrollmentDeadlineBanner deadlineAt={enrollmentDeadlineAt} />
        )}

        {!storageAvailable && (
          <Alert className="portal-storage-alert">
            <AlertDescription>
              Your session cannot be restored after refresh on this browser.
            </AlertDescription>
          </Alert>
        )}

        <div id="portal-content" className="portal-content" tabIndex={-1}>
          {mustOpenEnrollment ? null : children}
        </div>
      </div>
    </div>
  )
}

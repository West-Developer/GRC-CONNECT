"use client"

import type { ReactNode } from "react"

import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/features/components/ui/accordion"
import { Card, CardContent, CardHeader } from "@/features/components/ui/card"
import { useIsPhone } from "@/features/hooks/use-media-query"
import { cn } from "@/features/lib/utils"

interface AccordionCardProps {
  /** Stable id; also the accordion item value. */
  id: string
  /** The section heading (an h2). Keep it short: it is the accessible name. */
  title: ReactNode
  /** Shown at the top of the expanded content, hidden while collapsed. */
  description?: ReactNode
  /** Status badges shown beside the title, always visible. */
  badges?: ReactNode
  /** Whether the card starts expanded. Only read on mount. */
  defaultOpen?: boolean
  /**
   * Start collapsed on a phone even when `defaultOpen` is true, so a long page
   * opens as a short list of headings instead of every section at once
   * (stakeholder Doc 13). Wider screens are unaffected. Like `defaultOpen`, it
   * is only read on mount.
   */
  collapseOnMobile?: boolean
  className?: string
  contentClassName?: string
  children: ReactNode
}

/**
 * A `Card` whose whole body collapses behind its title, the same
 * Card + Accordion pairing already used for "Enrolled class schedule" and
 * "Your enrollments", extracted so every section of a long page can share one
 * shape. The trigger is the heading (h2), so each card still contributes one
 * navigable heading; the description lives in the content so it never leaks
 * into that heading's accessible name.
 */
export function AccordionCard({
  id,
  title,
  description,
  badges,
  defaultOpen = true,
  collapseOnMobile = false,
  className,
  contentClassName,
  children,
}: AccordionCardProps) {
  const isPhone = useIsPhone()
  const startsOpen = defaultOpen && !(collapseOnMobile && isPhone)

  return (
    <Card className={className}>
      <Accordion
        type="single"
        collapsible
        defaultValue={startsOpen ? id : undefined}
      >
        <AccordionItem value={id} className="border-none">
          <CardHeader>
            <AccordionTrigger
              headingLevel={2}
              className="py-0 text-left hover:no-underline"
            >
              <span className="flex min-w-0 flex-wrap items-center gap-2">
                <span className="font-heading text-base leading-snug font-medium">
                  {title}
                </span>
                {badges}
              </span>
            </AccordionTrigger>
          </CardHeader>
          <AccordionContent className="pb-0">
            <CardContent className={cn("grid gap-4 pt-4", contentClassName)}>
              {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
              )}
              {children}
            </CardContent>
          </AccordionContent>
        </AccordionItem>
      </Accordion>
    </Card>
  )
}

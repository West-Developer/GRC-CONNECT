"use client"

import {
  AnimatePresence,
  motion,
  useReducedMotion,
  type Variants,
} from "motion/react"
import type { ReactNode } from "react"

/**
 * Thin wrapper around `motion` (framer-motion) so workspaces never import the
 * library directly — reduced-motion handling lives in one place instead of
 * nineteen, and swapping the library later is a one-file change. The
 * existing pure-CSS `.reveal`/`ledger-enter` system (globals.css) stays for
 * simple mount-time entrances on the landing page; this module exists for
 * what CSS keyframes handle poorly: presence/exit animation and staggered
 * lists driven by dynamic data. See ADR 0015 for the tradeoffs behind
 * choosing a library here.
 *
 * The existing global `prefers-reduced-motion` CSS rule (globals.css) can't
 * reach these JS-driven transforms, so every export below checks
 * `useReducedMotion()` itself and renders motion-free when the user has
 * requested it.
 */

const HOUSE_EASE = [0.2, 0.75, 0.2, 1] as const

const REVEAL_STAGGER_DELAYS_MS = [0, 60, 150, 230, 310] as const

export interface RevealProps {
  children: ReactNode
  /** Matches the `.reveal--one..four` stagger ladder's delays; 0 = no delay. */
  index?: 0 | 1 | 2 | 3 | 4
  className?: string
}

/** A single element's entrance — the JS-driven equivalent of `.reveal`. */
export function Reveal({ children, index = 0, className }: RevealProps) {
  const reducedMotion = useReducedMotion()

  if (reducedMotion) {
    return <div className={className}>{children}</div>
  }

  return (
    <motion.div
      className={className}
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{
        duration: 0.65,
        delay: REVEAL_STAGGER_DELAYS_MS[index] / 1000,
        ease: HOUSE_EASE,
      }}
    >
      {children}
    </motion.div>
  )
}

const staggerContainerVariants: Variants = {
  hidden: {},
  visible: {
    transition: { staggerChildren: 0.06, delayChildren: 0.04 },
  },
}

const staggerItemVariants: Variants = {
  hidden: { opacity: 0, y: 12 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.4, ease: HOUSE_EASE },
  },
}

export interface StaggerListProps {
  children: ReactNode
  className?: string
}

/** Container for `StaggerItem` children — animates them in with a small stagger. */
export function StaggerList({ children, className }: StaggerListProps) {
  const reducedMotion = useReducedMotion()

  if (reducedMotion) {
    return <div className={className}>{children}</div>
  }

  return (
    <motion.div
      className={className}
      initial="hidden"
      animate="visible"
      variants={staggerContainerVariants}
    >
      {children}
    </motion.div>
  )
}

export interface StaggerItemProps {
  children: ReactNode
  className?: string
}

/** One item inside a `StaggerList` — must be a direct child to inherit the stagger. */
export function StaggerItem({ children, className }: StaggerItemProps) {
  const reducedMotion = useReducedMotion()

  if (reducedMotion) {
    return <div className={className}>{children}</div>
  }

  return (
    <motion.div className={className} variants={staggerItemVariants}>
      {children}
    </motion.div>
  )
}

export interface FadePresenceProps {
  /** A key that changes identity whenever the content being shown changes,
   * so `AnimatePresence` knows to exit the old content and enter the new. */
  presenceKey: string
  children: ReactNode
  className?: string
}

/**
 * Crossfades between mutually-exclusive content — an alert or receipt
 * appearing and later being dismissed. `AnimatePresence`'s exit animation is
 * exactly what plain CSS handles poorly (the element must stay mounted
 * during its own removal).
 *
 * Deliberately NOT wired into `AsyncBoundary`'s loading/error/empty/success
 * states: an attempted version of that made every refetch (filter change,
 * pagination) wait for a real exit animation before the new content
 * mounted, which broke synchronous test assertions across the workspace
 * suite — `AsyncBoundary` backs ~26 query sites, too broad a surface for one
 * shared crossfade tuning. Use this for a specific, narrower moment in a
 * single workspace instead (a receipt banner, a dismissible notice).
 */
export function FadePresence({
  presenceKey,
  children,
  className,
}: FadePresenceProps) {
  const reducedMotion = useReducedMotion()

  if (reducedMotion) {
    return <div className={className}>{children}</div>
  }

  return (
    <AnimatePresence mode="wait" initial={false}>
      <motion.div
        key={presenceKey}
        className={className}
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.2, ease: HOUSE_EASE }}
      >
        {children}
      </motion.div>
    </AnimatePresence>
  )
}

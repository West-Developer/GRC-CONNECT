import { cn } from "@/features/lib/utils"

interface GrcLoadingLogoProps {
  label?: string
  fullPage?: boolean
  layout?: "horizontal" | "vertical"
  size?: "sm" | "md" | "lg"
  className?: string
}

/**
 * The shared visual identity for route and default workspace loading states.
 * Callers keep control of data-dense skeleton layouts through AsyncBoundary's
 * `loadingFallback`; this component covers the otherwise unbranded default.
 */
export function GrcLoadingLogo({
  label = "Loading…",
  fullPage = false,
  layout = "horizontal",
  size = "md",
  className,
}: GrcLoadingLogoProps) {
  const isVertical = layout === "vertical"

  const sizeClasses = {
    sm: {
      box: "size-9 rounded-lg",
      ring: "-inset-1 rounded-xl",
      text: "text-xs tracking-[0.1em]",
      label: "text-xs",
      gap: isVertical ? "gap-3" : "gap-2.5",
    },
    md: {
      box: "size-11 rounded-xl",
      ring: "-inset-1.5 rounded-2xl",
      text: "text-sm tracking-[0.12em]",
      label: "text-sm",
      gap: isVertical ? "gap-4" : "gap-3",
    },
    lg: {
      box: "size-14 rounded-2xl",
      ring: "-inset-2 rounded-3xl border-2",
      text: "text-base tracking-[0.14em]",
      label: "text-base",
      gap: isVertical ? "gap-4" : "gap-3.5",
    },
  }[size]

  return (
    <div
      role="status"
      aria-live="polite"
      aria-label={label}
      className={cn(
        "flex items-center justify-center text-muted-foreground",
        isVertical ? "flex-col text-center" : "flex-row",
        sizeClasses.gap,
        fullPage && "min-h-svh bg-background px-6",
        className,
      )}
    >
      <div
        className={cn(
          "relative grid shrink-0 place-items-center bg-primary text-primary-foreground shadow-[0_10px_28px_color-mix(in_srgb,var(--primary)_28%,transparent)]",
          sizeClasses.box,
        )}
      >
        <span
          aria-hidden="true"
          className={cn(
            "absolute border border-institutional-gold/75 motion-safe:animate-[spin_3s_linear_infinite] motion-reduce:animate-none",
            sizeClasses.ring,
          )}
        />
        <span
          aria-hidden="true"
          className={cn("font-heading font-semibold", sizeClasses.text)}
        >
          GRC
        </span>
      </div>
      <p
        className={cn(
          "font-medium tracking-[0.01em] motion-safe:animate-pulse",
          sizeClasses.label,
        )}
      >
        {label}
      </p>
    </div>
  )
}

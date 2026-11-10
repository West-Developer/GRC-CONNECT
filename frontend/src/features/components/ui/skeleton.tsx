import { cn } from "@/features/lib/utils"

// Purely a decorative shape placeholder — it conveys no content of its own,
// so it stays out of the accessibility tree. A caller rendering several of
// these together announces loading once via a single status region (see
// AsyncBoundary), not once per skeleton.
function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="skeleton"
      aria-hidden="true"
      className={cn("animate-pulse rounded-md bg-muted", className)}
      {...props}
    />
  )
}

export { Skeleton }

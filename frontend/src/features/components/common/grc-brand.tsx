import Image from "next/image"

import { cn } from "@/features/lib/utils"

interface GrcBrandProps {
  className?: string
  compact?: boolean
  showSystemName?: boolean
}

export function GrcBrand({
  className,
  compact = false,
  showSystemName = true,
}: GrcBrandProps) {
  return (
    <span className={cn("grc-brand", compact && "grc-brand--compact", className)}>
      <Image
        src="/grc-logo-white.png"
        alt="Global Reciprocal Colleges"
        width={281}
        height={108}
        priority
      />
      {showSystemName && !compact && (
        <span className="grc-brand__system">Automated Enrollment System</span>
      )}
    </span>
  )
}

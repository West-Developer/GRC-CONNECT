"use client"

import { Eye, EyeOff } from "lucide-react"
import { useState } from "react"

import { Button } from "@/features/components/ui/button"
import { Input } from "@/features/components/ui/input"
import { cn } from "@/features/lib/utils"

/**
 * An `Input` with a show/hide toggle, matching the pattern already used on
 * Login and the Queue Kiosk — extracted here so a third usage (Account
 * Setup) doesn't copy the same ~15 lines a third time.
 */
function PasswordInput({
  className,
  wrapperClassName,
  ...props
}: React.ComponentProps<"input"> & { wrapperClassName?: string }) {
  const [visible, setVisible] = useState(false)

  return (
    <div className={cn("flex gap-2", wrapperClassName)}>
      <Input
        type={visible ? "text" : "password"}
        className={className}
        {...props}
      />
      <Button
        type="button"
        variant="outline"
        size="icon"
        aria-label={visible ? "Hide password" : "Show password"}
        disabled={props.disabled}
        onClick={() => setVisible((current) => !current)}
      >
        {visible ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
      </Button>
    </div>
  )
}

export { PasswordInput }

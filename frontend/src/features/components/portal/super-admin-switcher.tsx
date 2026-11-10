"use client"

import { useState, type ReactNode } from "react"
import { Building2, ArrowLeft, ShieldAlert } from "lucide-react"

import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { useSuperAdminActingContext } from "@/features/hooks/use-super-admin-acting-context"
import type { SwitchableRole } from "@/features/schemas/super-admin-schema"

export const SWITCHABLE_OFFICES: readonly {
  role: SwitchableRole
  label: string
  requiresCollege: boolean
}[] = [
  { role: "admission_staff", label: "Admission Staff", requiresCollege: false },
  { role: "program_chair", label: "Program Head", requiresCollege: true },
  { role: "dean", label: "Dean", requiresCollege: true },
  {
    role: "executive_director",
    label: "Executive Director",
    requiresCollege: false,
  },
  { role: "registrar_head", label: "Registrar Head", requiresCollege: false },
  { role: "registrar_staff", label: "Registrar Staff", requiresCollege: false },
  {
    role: "accounting_staff",
    label: "Accounting Staff",
    requiresCollege: false,
  },
  { role: "it_admin", label: "IT Control", requiresCollege: false },
] as const

export const COLLEGES = [
  { code: "ccs", label: "College of Computer Studies (CCS)" },
  { code: "coe", label: "College of Education (COE)" },
  { code: "coa", label: "College of Accountancy (COA)" },
  {
    code: "cbae",
    label: "College of Business Administration and Entrepreneurship (CBAE)",
  },
] as const

interface SuperAdminSwitcherProps {
  open?: boolean
  onOpenChange?: (open: boolean) => void
  trigger?: ReactNode
}

export function SuperAdminSwitcher({
  open: controlledOpen,
  onOpenChange: setControlledOpen,
  trigger,
}: SuperAdminSwitcherProps) {
  const { isSuperAdmin, switchTo, isPending, actingContext } =
    useSuperAdminActingContext()

  const [internalOpen, setInternalOpen] = useState(false)
  const isControlled = controlledOpen !== undefined
  const open = isControlled ? controlledOpen : internalOpen
  const setOpen = (nextOpen: boolean) => {
    if (isControlled) {
      setControlledOpen?.(nextOpen)
    } else {
      setInternalOpen(nextOpen)
    }
  }

  const initialRole =
    (actingContext?.role as SwitchableRole | undefined) ?? "dean"
  const [selectedRole, setSelectedRole] = useState<SwitchableRole>(initialRole)
  const [selectedCollege, setSelectedCollege] = useState<string>(
    actingContext?.college ?? "ccs",
  )

  if (!isSuperAdmin) {
    return null
  }

  const currentOffice = SWITCHABLE_OFFICES.find((o) => o.role === selectedRole)
  const requiresCollege = currentOffice?.requiresCollege ?? false

  const handleSwitch = async () => {
    await switchTo({
      role: selectedRole,
      college: requiresCollege
        ? (selectedCollege as "ccs" | "coe" | "coa" | "cbae")
        : null,
    })
    setOpen(false)
  }

  return (
    <>
      {trigger !== undefined ? (
        <span onClick={() => setOpen(true)}>{trigger}</span>
      ) : (
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="flex items-center gap-1.5"
          onClick={() => setOpen(true)}
          aria-label="Switch department"
        >
          <Building2 className="size-4 shrink-0" aria-hidden="true" />
          <span>Switch Department</span>
        </Button>
      )}

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Switch Department</DialogTitle>
            <DialogDescription>
              Select an office to access its workspace under your own identity.
            </DialogDescription>
          </DialogHeader>

          <div className="grid gap-4 py-4">
            <div className="grid gap-2">
              <label
                htmlFor="super-admin-office-select"
                className="text-sm font-medium"
              >
                Department / Office
              </label>
              <select
                id="super-admin-office-select"
                aria-label="Department / Office"
                value={selectedRole}
                onChange={(e) => {
                  const nextRole = e.target.value as SwitchableRole
                  setSelectedRole(nextRole)
                  const targetOffice = SWITCHABLE_OFFICES.find(
                    (o) => o.role === nextRole,
                  )
                  if (targetOffice?.requiresCollege && !selectedCollege) {
                    setSelectedCollege("ccs")
                  }
                }}
                className="h-10 w-full rounded-md border bg-background px-3 py-2 text-sm"
              >
                {SWITCHABLE_OFFICES.map((office) => (
                  <option key={office.role} value={office.role}>
                    {office.label}
                  </option>
                ))}
              </select>
            </div>

            {requiresCollege && (
              <div className="grid gap-2">
                <label
                  htmlFor="super-admin-college-select"
                  className="text-sm font-medium"
                >
                  College
                </label>
                <select
                  id="super-admin-college-select"
                  aria-label="College"
                  value={selectedCollege}
                  onChange={(e) => setSelectedCollege(e.target.value)}
                  className="h-10 w-full rounded-md border bg-background px-3 py-2 text-sm"
                >
                  <option value="" disabled>
                    Select a college
                  </option>
                  {COLLEGES.map((c) => (
                    <option key={c.code} value={c.code}>
                      {c.label}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={() => setOpen(false)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={handleSwitch}
              disabled={isPending || (requiresCollege && !selectedCollege)}
            >
              {isPending ? "Switching…" : "Switch Workspace"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}

export function SuperAdminBanner() {
  const { isSuperAdmin, isActing, actingContext, exit, isPending } =
    useSuperAdminActingContext()
  const [switcherOpen, setSwitcherOpen] = useState(false)

  if (!isSuperAdmin || !isActing || !actingContext) {
    return null
  }

  const office = SWITCHABLE_OFFICES.find((o) => o.role === actingContext.role)
  const roleLabel = office?.label ?? actingContext.role
  const collegeLabel = actingContext.college
    ? ` (${actingContext.college.toUpperCase()})`
    : ""

  return (
    <div
      role="status"
      aria-label="Super Admin acting context banner"
      className="flex flex-wrap items-center justify-between gap-3 border-b bg-amber-500/10 px-4 py-2.5 text-sm font-medium text-amber-950 dark:bg-amber-950/30 dark:text-amber-200"
    >
      <div className="flex items-center gap-2">
        <ShieldAlert className="size-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true" />
        <span>
          <strong>Super Admin</strong> · Acting as {roleLabel}
          {collegeLabel}
        </span>
      </div>

      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-7 border-amber-300 bg-white/80 text-xs hover:bg-white dark:border-amber-700 dark:bg-amber-900/40"
          onClick={() => setSwitcherOpen(true)}
        >
          Switch
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 text-xs hover:bg-amber-500/20"
          onClick={() => exit()}
          disabled={isPending}
        >
          <ArrowLeft className="mr-1 size-3" aria-hidden="true" />
          Back to Admin Console
        </Button>
      </div>

      <SuperAdminSwitcher
        open={switcherOpen}
        onOpenChange={setSwitcherOpen}
        trigger={<span className="sr-only">Open switcher</span>}
      />
    </div>
  )
}

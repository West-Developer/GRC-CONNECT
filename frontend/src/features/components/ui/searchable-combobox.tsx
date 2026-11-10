"use client"

import { useState } from "react"
import { Combobox as ComboboxPrimitive } from "@base-ui/react"

import { Input } from "@/features/components/ui/input"
import { cn } from "@/features/lib/utils"

export interface SearchableComboboxOption {
  value: string
  label: string
}

function isSearchableComboboxOption(value: unknown): value is SearchableComboboxOption {
  return typeof value === "object" && value !== null
    && "value" in value && typeof value.value === "string"
    && "label" in value && typeof value.label === "string"
}

interface SearchableComboboxProps {
  id: string
  label: string
  options: readonly SearchableComboboxOption[]
  value: string
  onValueChange: (value: string) => void
  placeholder: string
  emptyMessage: string
  disabled?: boolean
}

export function SearchableCombobox({
  id,
  label,
  options,
  value,
  onValueChange,
  placeholder,
  emptyMessage,
  disabled = false,
}: SearchableComboboxProps) {
  const selected = options.find((option) => option.value === value) ?? null
  const [inputElement, setInputElement] = useState<HTMLInputElement | null>(null)
  // Inside a dialog the popup has to be portalled into that dialog, or the
  // dialog's own focus trap swallows every click on an option. That includes
  // an AlertDialog (`role="alertdialog"`), e.g. the Change subject picker.
  // Anywhere else (a table cell, a plain form) there is no such container, and
  // the popup portals to its default target — passing `undefined` rather than
  // skipping the portal entirely, which would render no option list at all.
  const portalContainer =
    (inputElement?.closest(
      '[role="dialog"], [role="alertdialog"]',
    ) as HTMLElement | null) ?? undefined

  return (
    <ComboboxPrimitive.Root
      items={options}
      value={selected}
      onValueChange={(option) => onValueChange(option?.value ?? "")}
      itemToStringValue={(option) => option.label}
    >
      <ComboboxPrimitive.Input
        render={<Input id={id} ref={setInputElement} />}
        aria-label={label}
        placeholder={placeholder}
        disabled={disabled}
      />
      <ComboboxPrimitive.Portal container={portalContainer}>
        <ComboboxPrimitive.Positioner side="bottom" align="start" sideOffset={6} className="z-50">
          <ComboboxPrimitive.Popup className="max-h-64 w-(--anchor-width) overflow-hidden rounded-lg bg-popover text-popover-foreground shadow-md ring-1 ring-foreground/10">
            <ComboboxPrimitive.Empty className="hidden justify-center px-3 py-2 text-sm text-muted-foreground data-empty:flex">
              {emptyMessage}
            </ComboboxPrimitive.Empty>
            <ComboboxPrimitive.List className="max-h-64 overflow-y-auto p-1">
              {(value: unknown) => {
                if (!isSearchableComboboxOption(value)) return null

                return <ComboboxPrimitive.Item key={value.value} value={value} className={cn("flex cursor-default items-center rounded-md px-2 py-1.5 text-sm outline-hidden select-none data-highlighted:bg-accent data-highlighted:text-accent-foreground")}>{value.label}</ComboboxPrimitive.Item>
              }}
            </ComboboxPrimitive.List>
          </ComboboxPrimitive.Popup>
        </ComboboxPrimitive.Positioner>
      </ComboboxPrimitive.Portal>
    </ComboboxPrimitive.Root>
  )
}

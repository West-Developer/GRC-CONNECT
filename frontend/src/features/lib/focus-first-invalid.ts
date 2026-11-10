/**
 * After a form is submitted with errors, scrolls to the first field that is marked invalid and
 * focuses it, so the person is taken to what needs fixing instead of having to hunt for the message
 * (a long form can have the error far above the button they just pressed).
 *
 * Fields mark themselves invalid with `data-invalid="true"` on their `Field` wrapper or
 * `aria-invalid="true"` on the control. Runs a frame later so the error state has rendered first.
 * react-hook-form's own focus only reaches inputs registered by ref, not dropdowns or checkbox lists.
 */
export function focusFirstInvalidField(container: HTMLElement | null): void {
  if (!container) return

  window.requestAnimationFrame(() => {
    const invalid = container.querySelector<HTMLElement>(
      '[data-invalid="true"], [aria-invalid="true"]',
    )
    if (!invalid) return

    invalid.scrollIntoView?.({ behavior: "smooth", block: "center" })

    const focusable =
      'input:not([type="hidden"]), select, textarea, button, [role="combobox"], [role="checkbox"]'
    const target = invalid.matches(focusable)
      ? invalid
      : invalid.querySelector<HTMLElement>(focusable)
    target?.focus({ preventScroll: true })
  })
}

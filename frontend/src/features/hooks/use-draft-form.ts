"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import {
  useForm,
  type DefaultValues,
  type FieldValues,
  type UseFormProps,
  type UseFormReturn,
} from "react-hook-form"

import { useAuth } from "@/features/auth/use-auth"
import { browserFormDraftStore } from "@/features/services/form-draft-store"

/** How long typing must pause before the draft is written. */
const SAVE_DELAY_MS = 400

export interface DraftFormControls {
  /** Forget the saved draft. The form itself is left as it is. */
  clear: () => void
  /** Put the form back to how it started (or was last rebased) and forget the draft. */
  discard: () => void
  /** Something worth keeping has been entered. */
  hasContent: boolean
  /**
   * Treat what the form holds now as its starting point. Call it after resetting the form on
   * purpose (a saved record, with a value such as the program carried over to the next one), so
   * that carried-over value is not mistaken for something typed.
   */
  rebase: () => void
  /** The form started from a draft saved earlier. */
  restored: boolean
}

interface Baseline<TValues> {
  key: string
  values: DefaultValues<TValues>
}

/** An object's empty strings and undefined fields count as "not there", in any key order. */
function normalize(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(normalize)
  }

  if (value !== null && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value)
        .filter(([, field]) => field !== undefined && field !== "")
        .sort(([first], [second]) => (first < second ? -1 : 1))
        .map(([name, field]) => [name, normalize(field)]),
    )
  }

  return value
}

function canonical(value: unknown): string {
  return JSON.stringify(normalize(value))
}

/**
 * `useForm` that does not lose what was typed. It starts from the draft saved earlier for this
 * user and form, saves the values a moment after typing pauses (and once more as the form goes
 * away, so the last keystrokes survive a tab switch), and forgets the draft when asked to — after
 * a successful save, or when the person discards it. Nothing is saved while the form still
 * equals how it started, and nothing at all without a signed-in user.
 *
 * Drafts are per user and per `formKey`, live in `sessionStorage`, expire, and are wiped when the
 * session ends (see `form-draft-store.ts`). Give `formKey` a version suffix and bump it when the
 * form's fields change shape.
 *
 * React Compiler trap: the compiler recognises `useForm` by name and skips components that call
 * `watch()` or spread `register()` props, because they cannot be memoized safely. Behind this hook
 * it can no longer see that, so a component that uses `watch`/`register` from the returned form
 * must opt out itself with a `"use no memo"` directive. Compiled, the memoized `register()` props
 * keep stale ref callbacks and a `reset()` (discarding a draft, clearing the form) leaves the old
 * text in the boxes. The jsdom tests do not run the compiler, so only a real browser shows it.
 */
export function useDraftForm<TValues extends FieldValues>({
  formKey,
  defaultValues,
  ...formProps
}: UseFormProps<TValues> & {
  defaultValues: DefaultValues<TValues>
  formKey: string
}): { draft: DraftFormControls; form: UseFormReturn<TValues> } {
  const { session } = useAuth()
  const userId = session?.userId ?? null

  // Read once, at mount, so the very first render already shows what was typed.
  const [restoredValues] = useState(() =>
    userId === null
      ? null
      : browserFormDraftStore.read<DefaultValues<TValues>>(userId, formKey),
  )
  const form = useForm<TValues>({
    ...formProps,
    defaultValues:
      restoredValues === null
        ? defaultValues
        : { ...defaultValues, ...restoredValues },
  })

  const baseline = useRef<Baseline<TValues>>({
    key: canonical(defaultValues),
    values: defaultValues,
  })
  const saveTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [hasContent, setHasContent] = useState(restoredValues !== null)

  /** Writes the draft, or removes it when the form equals its starting point. */
  const save = useCallback((): boolean => {
    if (userId === null) {
      return false
    }

    const values = form.getValues()
    if (canonical(values) === baseline.current.key) {
      browserFormDraftStore.clear(userId, formKey)
      return false
    }

    browserFormDraftStore.write(userId, formKey, values)
    return true
  }, [form, formKey, userId])

  const cancelPendingSave = useCallback(() => {
    if (saveTimer.current !== null) {
      clearTimeout(saveTimer.current)
      saveTimer.current = null
    }
  }, [])

  useEffect(() => {
    // `subscribe`, not `watch`: it reports every change (typing, `setValue`, `reset`) without
    // re-rendering the form, and does not stop the React Compiler from optimising the component.
    const unsubscribe = form.subscribe({
      formState: { values: true },
      callback: () => {
        cancelPendingSave()
        saveTimer.current = setTimeout(() => {
          saveTimer.current = null
          setHasContent(save())
        }, SAVE_DELAY_MS)
      },
    })

    return () => {
      unsubscribe()
      if (saveTimer.current !== null) {
        // The form is going away with a save still waiting: write it now.
        cancelPendingSave()
        save()
      }
    }
  }, [cancelPendingSave, form, save])

  const clear = useCallback(() => {
    cancelPendingSave()
    if (userId !== null) {
      browserFormDraftStore.clear(userId, formKey)
    }
    setHasContent(false)
  }, [cancelPendingSave, formKey, userId])

  const discard = useCallback(() => {
    form.reset(baseline.current.values)
    clear()
  }, [clear, form])

  const rebase = useCallback(() => {
    const values = JSON.parse(
      JSON.stringify(form.getValues()),
    ) as DefaultValues<TValues>
    baseline.current = { key: canonical(values), values }
  }, [form])

  return {
    draft: {
      clear,
      discard,
      hasContent,
      rebase,
      restored: restoredValues !== null,
    },
    form,
  }
}

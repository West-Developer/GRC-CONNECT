"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useForm } from "react-hook-form"
import { toast } from "sonner"

import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import { Field, FieldError, FieldLabel } from "@/features/components/ui/field"
import { PasswordInput } from "@/features/components/ui/password-input"
import { useChangeItControlPasswordMutation } from "@/features/hooks/use-it-control-accounts"
import { apiErrorMessages } from "@/features/lib/api-error-messages"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  changeItControlPasswordSchema,
  type ChangeItControlPasswordInput,
} from "@/features/schemas/it-control-schema"

export function ItControlPasswordDialog({
  account,
  onClose,
}: {
  account: { id: number; name: string; email: string }
  onClose: () => void
}) {
  const change = useChangeItControlPasswordMutation()
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ChangeItControlPasswordInput>({
    resolver: zodResolver(changeItControlPasswordSchema),
    defaultValues: { password: "", password_confirmation: "" },
  })

  const submit = async (input: ChangeItControlPasswordInput) => {
    try {
      await change.mutateAsync({ id: account.id, input })
      toast.success(
        `Password changed for ${account.name}. All existing sessions were signed out.`,
      )
      onClose()
    } catch (error) {
      if (!applyApiFieldErrors(error, setError)) {
        setError("root", {
          message: apiErrorMessages(
            error,
            "The password could not be changed.",
          )[0],
        })
      }
    } finally {
      change.reset()
    }
  }

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !change.isPending) onClose()
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Change password</DialogTitle>
          <DialogDescription>
            Set a new password for {account.name} ({account.email}). This signs
            the account out on all devices. The account must sign in again with
            its new password.
          </DialogDescription>
        </DialogHeader>
        <form
          id={`change-password-${account.id}`}
          noValidate
          onSubmit={(event) => void handleSubmit(submit)(event)}
        >
          <fieldset disabled={change.isPending} className="grid gap-4">
            <Field data-invalid={Boolean(errors.password)}>
              <FieldLabel htmlFor={`new-password-${account.id}`}>
                New password
              </FieldLabel>
              <PasswordInput
                id={`new-password-${account.id}`}
                autoComplete="new-password"
                aria-invalid={Boolean(errors.password)}
                aria-describedby={`password-help-${account.id}`}
                {...register("password")}
              />
              <p
                id={`password-help-${account.id}`}
                className="text-sm text-muted-foreground"
              >
                Use at least 8 characters with uppercase and lowercase letters,
                a number and a symbol.
              </p>
              <FieldError>{errors.password?.message}</FieldError>
            </Field>
            <Field data-invalid={Boolean(errors.password_confirmation)}>
              <FieldLabel htmlFor={`confirm-password-${account.id}`}>
                Confirm new password
              </FieldLabel>
              <PasswordInput
                id={`confirm-password-${account.id}`}
                autoComplete="new-password"
                aria-invalid={Boolean(errors.password_confirmation)}
                {...register("password_confirmation")}
              />
              <FieldError>{errors.password_confirmation?.message}</FieldError>
            </Field>
          </fieldset>
          {errors.root?.message && (
            <p role="alert" className="mt-3 text-sm text-destructive">
              {errors.root.message}
            </p>
          )}
        </form>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            disabled={change.isPending}
            onClick={onClose}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form={`change-password-${account.id}`}
            disabled={change.isPending}
          >
            {change.isPending ? "Changing password…" : "Change password"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

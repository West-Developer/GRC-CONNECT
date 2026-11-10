"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useState } from "react"
import { useForm } from "react-hook-form"
import { toast } from "sonner"

import { ItControlPasswordDialog } from "@/features/components/portal/it-control-password-dialog"

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/features/components/ui/alert-dialog"
import { Button } from "@/features/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/features/components/ui/dialog"
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/features/components/ui/field"
import { Input } from "@/features/components/ui/input"
import {
  useArchiveItControlAccountMutation,
  useDeleteItControlAccountMutation,
  useRestoreItControlAccountMutation,
  useUpdateItControlAccountMutation,
} from "@/features/hooks/use-it-control-accounts"
import { apiErrorMessages } from "@/features/lib/api-error-messages"
import { applyApiFieldErrors } from "@/features/lib/api-form-errors"
import {
  accountEditFormSchema,
  type AccountEditForm,
  type UpdateItControlAccountInput,
} from "@/features/schemas/it-control-schema"

/** The part of a Student or Faculty row IT Control needs to act on the account behind it. */
export interface ManagedAccount {
  /** The account (user) id, which is what every action addresses. */
  id: number
  name: string
  firstName: string | null
  lastName: string | null
  suffix: string | null
  email: string
  isArchived: boolean
}

type Dialog = "edit" | "archive" | "restore" | "delete" | "password"

function EditAccountDialog({
  account,
  onClose,
}: {
  account: ManagedAccount
  onClose: () => void
}) {
  const update = useUpdateItControlAccountMutation()
  const {
    formState: { errors },
    handleSubmit,
    register,
    setError,
  } = useForm<AccountEditForm>({
    resolver: zodResolver(accountEditFormSchema),
    defaultValues: {
      first_name: account.firstName ?? "",
      last_name: account.lastName ?? "",
      suffix: account.suffix ?? "",
      email: account.email,
    },
  })

  const submit = async (values: AccountEditForm) => {
    // Only what changed is sent; the server refuses an edit that changes nothing.
    const changes: UpdateItControlAccountInput = {}
    if (values.first_name !== (account.firstName ?? "")) {
      changes.first_name = values.first_name
    }
    if (values.last_name !== (account.lastName ?? "")) {
      changes.last_name = values.last_name
    }
    if (values.suffix !== (account.suffix ?? "")) {
      changes.suffix = values.suffix === "" ? null : values.suffix
    }
    if (values.email !== account.email) {
      changes.email = values.email
    }
    if (Object.keys(changes).length === 0) {
      setError("root", { message: "Change at least one field to save." })
      return
    }

    try {
      await update.mutateAsync({ id: account.id, input: changes })
      toast.success(`Updated the account of ${values.first_name}.`)
      onClose()
    } catch (error) {
      // A message the server tied to a field shows beside it; anything else is shown once, under the form.
      if (!applyApiFieldErrors(error, setError)) {
        setError("root", {
          message: apiErrorMessages(
            error,
            "The account could not be saved.",
          )[0],
        })
      }
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit account</DialogTitle>
          <DialogDescription>
            Change the name or login email of {account.name}. The middle name is
            kept by Admission and is not edited here.
          </DialogDescription>
        </DialogHeader>
        <form
          id={`edit-account-${account.id}`}
          onSubmit={(event) => void handleSubmit(submit)(event)}
        >
          <FieldGroup className="grid gap-4 sm:grid-cols-2">
            <Field data-invalid={Boolean(errors.first_name)}>
              <FieldLabel htmlFor={`account-first-name-${account.id}`}>
                First name
              </FieldLabel>
              <Input
                id={`account-first-name-${account.id}`}
                {...register("first_name")}
              />
              <FieldError>{errors.first_name?.message}</FieldError>
            </Field>
            <Field data-invalid={Boolean(errors.last_name)}>
              <FieldLabel htmlFor={`account-last-name-${account.id}`}>
                Last name
              </FieldLabel>
              <Input
                id={`account-last-name-${account.id}`}
                {...register("last_name")}
              />
              <FieldError>{errors.last_name?.message}</FieldError>
            </Field>
            <Field data-invalid={Boolean(errors.suffix)}>
              <FieldLabel htmlFor={`account-suffix-${account.id}`}>
                Suffix
              </FieldLabel>
              <Input
                id={`account-suffix-${account.id}`}
                placeholder="Jr., Sr., III…"
                maxLength={20}
                {...register("suffix")}
              />
              <FieldError>{errors.suffix?.message}</FieldError>
            </Field>
            <Field data-invalid={Boolean(errors.email)}>
              <FieldLabel htmlFor={`account-email-${account.id}`}>
                Email address
              </FieldLabel>
              <Input
                id={`account-email-${account.id}`}
                type="email"
                {...register("email")}
              />
              <FieldError>{errors.email?.message}</FieldError>
            </Field>
          </FieldGroup>
          {errors.root?.message && (
            <p role="alert" className="mt-3 text-sm text-destructive">
              {errors.root.message}
            </p>
          )}
        </form>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            type="submit"
            form={`edit-account-${account.id}`}
            disabled={update.isPending}
          >
            {update.isPending ? "Saving…" : "Save changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function ConfirmDialog({
  title,
  description,
  confirmLabel,
  pending,
  destructive = false,
  onConfirm,
  onClose,
}: {
  title: string
  description: string
  confirmLabel: string
  pending: boolean
  destructive?: boolean
  onConfirm: () => void
  onClose: () => void
}) {
  return (
    <AlertDialog open onOpenChange={(open) => !open && onClose()}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>Cancel</AlertDialogCancel>
          <AlertDialogAction
            disabled={pending}
            // The same tinted look as the destructive button variant (the action itself has no variant prop).
            className={
              destructive
                ? "bg-destructive/10 text-destructive hover:bg-destructive/20"
                : undefined
            }
            onClick={(event) => {
              // Stay open until the request answers: an error is shown, not lost behind a closed dialog.
              event.preventDefault()
              onConfirm()
            }}
          >
            {pending ? "Working…" : confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}

/**
 * IT Control's row actions for a Student or Faculty account (ADR 0054): edit the name and login email, archive an
 * account that has records (or restore it), and delete one that was never used. The server decides whether a delete
 * is allowed and says so, so an account with records is pointed to Archive.
 */
export function ItControlAccountActions({
  account,
}: {
  account: ManagedAccount
}) {
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const archive = useArchiveItControlAccountMutation()
  const restore = useRestoreItControlAccountMutation()
  const remove = useDeleteItControlAccountMutation()
  const close = () => setDialog(null)

  const run = async (action: () => Promise<unknown>, success: string) => {
    try {
      await action()
      toast.success(success)
      close()
    } catch (error) {
      toast.error(apiErrorMessages(error, "That did not work. Try again.")[0])
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {!account.isArchived && (
        <Button
          type="button"
          size="xs"
          variant="outline"
          aria-label={`Edit ${account.name}`}
          onClick={() => setDialog("edit")}
        >
          Edit
        </Button>
      )}
      {account.isArchived ? (
        <Button
          type="button"
          size="xs"
          variant="secondary"
          aria-label={`Restore ${account.name}`}
          onClick={() => setDialog("restore")}
        >
          Restore
        </Button>
      ) : (
        <Button
          type="button"
          size="xs"
          variant="secondary"
          aria-label={`Archive ${account.name}`}
          onClick={() => setDialog("archive")}
        >
          Archive
        </Button>
      )}
      {!account.isArchived && (
        <Button
          type="button"
          size="xs"
          variant="outline"
          aria-label={`Change password for ${account.name}`}
          onClick={() => setDialog("password")}
        >
          Change password
        </Button>
      )}
      <Button
        type="button"
        size="xs"
        variant="destructive"
        aria-label={`Delete ${account.name}`}
        onClick={() => setDialog("delete")}
      >
        Delete
      </Button>

      {dialog === "edit" && (
        <EditAccountDialog account={account} onClose={close} />
      )}
      {dialog === "password" && (
        <ItControlPasswordDialog account={account} onClose={close} />
      )}
      {dialog === "archive" && (
        <ConfirmDialog
          title={`Archive ${account.name}?`}
          description="The account is disabled and signed out everywhere, and it moves out of the default list. Nothing is deleted: records are kept and you can restore the account later."
          confirmLabel="Archive account"
          pending={archive.isPending}
          onClose={close}
          onConfirm={() =>
            void run(
              () => archive.mutateAsync(account.id),
              `Archived ${account.name}.`,
            )
          }
        />
      )}
      {dialog === "restore" && (
        <ConfirmDialog
          title={`Restore ${account.name}?`}
          description="The account returns to the list. It can sign in again only if it had finished setting up its login; otherwise it stays disabled until it is invited again."
          confirmLabel="Restore account"
          pending={restore.isPending}
          onClose={close}
          onConfirm={() =>
            void run(
              () => restore.mutateAsync(account.id),
              `Restored ${account.name}.`,
            )
          }
        />
      )}
      {dialog === "delete" && (
        <ConfirmDialog
          title={`Delete ${account.name} permanently?`}
          description="This cannot be undone. Only an account that was never used can be deleted; if it has records you will be told to archive it instead."
          confirmLabel="Delete account"
          destructive
          pending={remove.isPending}
          onClose={close}
          onConfirm={() =>
            void run(
              () => remove.mutateAsync(account.id),
              `Deleted ${account.name}.`,
            )
          }
        />
      )}
    </div>
  )
}

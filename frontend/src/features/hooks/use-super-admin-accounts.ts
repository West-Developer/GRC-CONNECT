import { useQuery } from "@tanstack/react-query"

import type { ManagedUserFilters } from "@/features/schemas/super-admin-schema"
import { listManagedUsers } from "@/features/services/super-admin-service"

export function managedUsersQueryKey(filters: ManagedUserFilters = {}) {
  return [
    "super-admin-users",
    filters.q ?? "",
    filters.role ?? "",
    filters.status ?? "",
    filters.college ?? "",
    filters.pending_setup !== undefined ? String(filters.pending_setup) : "",
    filters.page ?? 1,
    filters.per_page ?? 20,
  ] as const
}

export function useManagedUsersQuery(
  filters: ManagedUserFilters = {},
  enabled: boolean = true,
) {
  return useQuery({
    queryKey: managedUsersQueryKey(filters),
    queryFn: ({ signal }) => listManagedUsers(filters, signal),
    enabled,
  })
}

"use client"

import { useQuery } from "@tanstack/react-query"

import { useAuth } from "@/features/auth/use-auth"
import {
  getFacultyAvailabilities,
  getFacultyCurriculumSubjectPreferences,
  getFacultyPreferenceCatalog,
  getFacultySpecializations,
  getFacultySubjectPreferences,
  getFacultyTeachingHistory,
} from "@/features/services/faculty-service"

export const facultyAvailabilitiesQueryKey = (userId: string | null) =>
  ["faculty-availabilities", userId] as const
export const facultySubjectPreferencesQueryKey = (userId: string | null) =>
  ["faculty-subject-preferences", userId] as const
export const facultyPreferenceCatalogQueryKey = (userId: string | null) =>
  ["faculty-preference-catalog", userId] as const
export const facultyCurriculumSubjectPreferencesQueryKey = (
  userId: string | null,
) => ["faculty-curriculum-subject-preferences", userId] as const
export const facultyTeachingHistoryQueryKey = (userId: string | null) =>
  ["faculty-teaching-history", userId] as const
export const facultySpecializationsQueryKey = (userId: string | null) =>
  ["faculty-specializations", userId] as const

export function useFacultyAvailabilitiesQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultyAvailabilitiesQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getFacultyAvailabilities(signal),
    enabled: session !== null,
  })
}

export function useFacultySubjectPreferencesQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultySubjectPreferencesQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getFacultySubjectPreferences(signal),
    enabled: session !== null,
  })
}

export function useFacultyPreferenceCatalogQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultyPreferenceCatalogQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getFacultyPreferenceCatalog(signal),
    enabled: session !== null,
  })
}

export function useFacultyCurriculumSubjectPreferencesQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultyCurriculumSubjectPreferencesQueryKey(
      session?.userId ?? null,
    ),
    queryFn: ({ signal }) => getFacultyCurriculumSubjectPreferences(signal),
    enabled: session !== null,
  })
}

export function useFacultyTeachingHistoryQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultyTeachingHistoryQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getFacultyTeachingHistory(signal),
    enabled: session !== null,
  })
}

export function useFacultySpecializationsQuery() {
  const { session } = useAuth()

  return useQuery({
    queryKey: facultySpecializationsQueryKey(session?.userId ?? null),
    queryFn: ({ signal }) => getFacultySpecializations(signal),
    enabled: session !== null,
  })
}

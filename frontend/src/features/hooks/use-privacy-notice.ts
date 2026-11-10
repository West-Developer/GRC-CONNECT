"use client"

import { useMutation, useQuery } from "@tanstack/react-query"

import {
  acceptPrivacyNotice,
  fetchPrivacyNoticeInfo,
} from "@/features/services/auth-service"

export const privacyNoticeInfoQueryKey = ["privacy-notice-info"] as const

/** The notice's current version and contact. Public: it is read before anyone signs in. */
export function usePrivacyNoticeInfoQuery() {
  return useQuery({
    queryKey: privacyNoticeInfoQueryKey,
    queryFn: ({ signal }) => fetchPrivacyNoticeInfo(signal),
    staleTime: 5 * 60 * 1000,
  })
}

/** The signed-in person agrees to the version they were shown. */
export function useAcceptPrivacyNoticeMutation() {
  return useMutation({
    mutationFn: (noticeVersion: string) => acceptPrivacyNotice(noticeVersion),
  })
}

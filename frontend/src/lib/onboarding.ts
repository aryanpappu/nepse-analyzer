import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import { useAuth } from './auth'
import type { OnboardingState, OnboardingStep } from './types'

/**
 * What the signed-in user still has to do before the app can say anything.
 *
 * One query, shared by every consumer through React Query's cache, so the
 * Dashboard checklist and a page's empty state can never disagree about what
 * is missing. Skipped entirely when signed out: onboarding is about *your*
 * money, and the public market pages have nothing to prompt for.
 */
export function useOnboarding() {
  const { user } = useAuth()

  return useQuery({
    queryKey: ['onboarding'],
    queryFn: async () => (await api.get<{ data: OnboardingState }>('/onboarding')).data.data,
    enabled: user !== null,
    // Importing or classifying is a deliberate act elsewhere in the app, and
    // those mutations invalidate this key. A minute of staleness in between is
    // cheaper than re-asking on every page change.
    staleTime: 60 * 1000,
  })
}

/** The first outstanding step, or null once everything is done. */
export function nextStep(state: OnboardingState | undefined): OnboardingStep | null {
  if (state === undefined || state.next === null) return null

  return state.steps.find((step) => step.key === state.next) ?? null
}

/**
 * Whether one checklist step is finished.
 *
 * `undefined` while the answer is unknown, so a page can hold off on saying
 * "you have not imported anything" to someone who has. Pages use this to pick
 * between two honest empty states: a portfolio with nothing in it needs the
 * setup path, one that is populated but has no sales needs to be told exactly
 * that and nothing more.
 */
export function useStepDone(key: string): boolean | undefined {
  const { data } = useOnboarding()

  return data?.steps.find((step) => step.key === key)?.done
}

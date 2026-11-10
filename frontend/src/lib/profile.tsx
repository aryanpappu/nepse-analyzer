import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { api } from './api'
import type { InvestorProfileMeta, InvestorProfileValue } from './types'

const STORAGE_KEY = 'nepse.profile'

/**
 * The investor profile is a lens over the whole app, not a per-page filter:
 * switch to "swing" and the stock page, the portfolio actions and the market
 * screen all re-answer their question for that horizon. Keeping it in one
 * context (and in localStorage) means the choice survives a reload and never
 * disagrees between two pages.
 */
interface ProfileContextValue {
  profile: InvestorProfileValue
  setProfile: (profile: InvestorProfileValue) => void
  profiles: InvestorProfileMeta[]
  meta: InvestorProfileMeta | null
}

const ProfileContext = createContext<ProfileContextValue | null>(null)

function readStored(): InvestorProfileValue {
  const stored = localStorage.getItem(STORAGE_KEY)

  return stored === 'swing' || stored === 'momentum' || stored === 'long_term'
    ? stored
    : 'long_term'
}

export function ProfileProvider({ children }: { children: ReactNode }) {
  const [profile, setProfile] = useState<InvestorProfileValue>(readStored)

  // The catalog is served from the backend enum, so hold bands and stop widths
  // are never duplicated in the UI where they could drift out of sync.
  const profilesQuery = useQuery({
    queryKey: ['profiles'],
    queryFn: async () => (await api.get<{ data: InvestorProfileMeta[] }>('/profiles')).data.data,
    staleTime: 60 * 60 * 1000,
  })

  const select = useCallback((next: InvestorProfileValue) => {
    localStorage.setItem(STORAGE_KEY, next)
    setProfile(next)
  }, [])

  /*
   * The `?? []` has to live inside the memo. Outside it, a new array is
   * allocated on every render while the query is loading, so `profiles` is
   * never referentially equal, the memo re-runs, and every consumer of this
   * context re-renders on each pass.
   */
  const profilesData = profilesQuery.data

  const value = useMemo<ProfileContextValue>(() => {
    const profiles = profilesData ?? []

    return {
      profile,
      setProfile: select,
      profiles,
      meta: profiles.find((item) => item.value === profile) ?? null,
    }
  }, [profile, select, profilesData])

  return <ProfileContext.Provider value={value}>{children}</ProfileContext.Provider>
}

export function useProfile(): ProfileContextValue {
  const context = useContext(ProfileContext)

  if (context === null) {
    throw new Error('useProfile must be used inside a ProfileProvider.')
  }

  return context
}

/**
 * The global lens switcher. Rendered in the header so it is the same control
 * wherever the user happens to be.
 */
export function ProfileSwitcher({ compact = false }: { compact?: boolean }) {
  const { profile, setProfile, profiles } = useProfile()

  if (profiles.length === 0) return null

  return (
    <div
      className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5"
      role="group"
      aria-label="Investor profile"
    >
      {profiles.map((item) => (
        <button
          key={item.value}
          type="button"
          onClick={() => setProfile(item.value)}
          title={`${item.description} Typical hold: ${item.hold_label}.`}
          aria-pressed={profile === item.value}
          className={clsx(
            'rounded-md py-1 text-xs font-medium transition',
            compact ? 'px-2' : 'px-2.5 sm:px-3',
            profile === item.value
              ? 'bg-white text-slate-900 shadow-sm'
              : 'text-slate-500 hover:text-slate-800',
          )}
        >
          {item.short_label}
        </button>
      ))}
    </div>
  )
}

/** Grade colours, shared by every surface that shows a verdict. */
export function gradeClasses(grade: string | null | undefined): string {
  switch (grade) {
    case 'strong_buy':
      return 'bg-emerald-100 text-emerald-800 ring-emerald-200'
    case 'buy':
      return 'bg-green-50 text-green-700 ring-green-200'
    case 'watch':
      return 'bg-amber-50 text-amber-700 ring-amber-200'
    case 'neutral':
      return 'bg-slate-100 text-slate-600 ring-slate-200'
    case 'avoid':
      return 'bg-rose-50 text-rose-700 ring-rose-200'
    default:
      return 'bg-slate-100 text-slate-500 ring-slate-200'
  }
}

/** Action colours, ordered by how much the action costs you if ignored. */
export function actionClasses(action: string): string {
  switch (action) {
    case 'stop_loss':
      return 'bg-rose-100 text-rose-800 ring-rose-300'
    case 'exit':
      return 'bg-rose-50 text-rose-700 ring-rose-200'
    case 'trim':
      return 'bg-amber-50 text-amber-800 ring-amber-200'
    case 'accumulate':
      return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    case 'add_on_dip':
      return 'bg-sky-50 text-sky-700 ring-sky-200'
    default:
      return 'bg-slate-100 text-slate-600 ring-slate-200'
  }
}

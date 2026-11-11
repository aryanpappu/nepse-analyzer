import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useEffect, useRef } from 'react'
import { ErrorBoundary } from './ErrorBoundary'
import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { api } from '../lib/api'
import { useAuth } from '../lib/auth'
import { shortDate } from '../lib/format'
import { ProfileSwitcher } from '../lib/profile'
import { PortfolioSwitcher } from '../lib/portfolioSelection'
import type { HealthStatus, HealthVerdict } from '../lib/types'

/*
 * Grouped rather than a flat list of thirteen. The split is how the app is
 * actually used: look at the market, look at your own money, then the things
 * you set up once. A newcomer facing thirteen equal-weight links has no way to
 * tell that Dashboard and Portfolio are the entry points and Attribution is a
 * place you go after a question forms.
 */
const NAV_GROUPS: { label: string; items: { to: string; label: string; end?: boolean }[] }[] = [
  {
    label: 'Market',
    items: [
      { to: '/', label: 'Dashboard', end: true },
      { to: '/stocks', label: 'Stocks' },
      { to: '/signals', label: 'Signals' },
      { to: '/sectors', label: 'Sectors' },
      { to: '/compare', label: 'Compare' },
      { to: '/calendar', label: 'Calendar' },
    ],
  },
  {
    label: 'My money',
    items: [
      { to: '/portfolio', label: 'Portfolio' },
      { to: '/performance', label: 'Performance' },
      { to: '/attribution', label: 'Attribution' },
      { to: '/channels', label: 'IPO / Traded' },
      { to: '/capital-gains', label: 'Tax' },
    ],
  },
  {
    label: 'Setup',
    items: [
      { to: '/import', label: 'Holdings' },
      { to: '/cash-import', label: 'Statement' },
      { to: '/settings', label: 'Settings' },
    ],
  },
]

const ADMIN_GROUP: (typeof NAV_GROUPS)[number] = { label: 'Admin', items: [{ to: '/admin/users', label: 'Users' }] }

export function Layout() {
  const { user, loading: authLoading, logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const mainRef = useRef<HTMLElement>(null)

  useEffect(() => {
    mainRef.current?.focus({ preventScroll: true })
    window.scrollTo({ top: 0, behavior: 'instant' })
  }, [location.pathname])

  // Health drives the staleness banner, so a silently broken pipeline is
  // visible rather than being mistaken for a quiet market.
  const health = useQuery({
    queryKey: ['health'],
    queryFn: async () => (await api.get<{ data: HealthStatus }>('/health')).data.data,
    refetchInterval: 5 * 60 * 1000,
    staleTime: 60 * 1000,
  })

  const priceDate = health.data?.latest_price_date ?? null

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen">
      <a href="#main-content" className="sr-only fixed left-4 top-4 z-50 rounded-lg bg-slate-900 px-4 py-2 text-white focus:not-sr-only">
        Skip to content
      </a>
      <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
          <NavLink to="/" className="flex items-center gap-2">
            <span className="grid h-8 w-8 place-items-center rounded-lg bg-slate-900 text-sm font-bold text-white">
              N
            </span>
            <span className="text-lg font-semibold tracking-tight">NEPSE Analyzer</span>
          </NavLink>

          {/*
           * Its own full-width row: with group labels the bar no longer fits
           * beside the logo, and a clipped "Settings" reads as broken rather
           * than scrollable.
           *
           * The caption sits *above* its links rather than inline with them.
           * Inline, small grey uppercase text at the same baseline as the
           * links reads as a disabled menu item — "why is my money section
           * disabled?" was the first question it drew — and the row could wrap
           * between a caption and the group it names, orphaning "SETUP" on a
           * line of its own above Import and Settings.
           */}
          <nav aria-label="Main navigation" className="order-3 flex w-full flex-wrap items-start gap-x-5 gap-y-3">
            {(user?.is_admin === true ? [...NAV_GROUPS, ADMIN_GROUP] : NAV_GROUPS).map((group) => (
              <div key={group.label} className="flex flex-col gap-1">
                <span
                  aria-hidden
                  className="select-none pl-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400"
                >
                  {group.label}
                </span>
                {/* The caption names the group for sighted readers; a screen
                    reader gets it from the landmark instead, so it is not read
                    out twice. */}
                <div className="flex flex-wrap items-center gap-1" role="group" aria-label={group.label}>
                  {group.items.map((item) => (
                    <NavLink
                      key={item.to}
                      to={item.to}
                      end={item.end}
                      className={({ isActive }) =>
                        clsx(
                          'whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                          isActive
                            ? 'bg-slate-900 text-white'
                            : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900',
                        )
                      }
                    >
                      {item.label}
                    </NavLink>
                  ))}
                </div>
              </div>
            ))}
          </nav>

          <div className="ml-auto flex items-center gap-3 text-sm">
            {user !== null && <PortfolioSwitcher />}
            <ProfileSwitcher />

            {priceDate !== null && (
              <span className="hidden text-xs text-slate-500 sm:inline">
                Prices as of {shortDate(priceDate)}
              </span>
            )}
            {/*
             * `loading` is a third state, not a synonym for signed out. It is
             * only ever true when a stored token is being verified, so showing
             * "Sign in" during it told a signed-in user on every hard refresh
             * that their session had ended.
             */}
            {authLoading ? (
              <span className="h-8 w-20 animate-pulse rounded-lg bg-slate-100" aria-hidden />
            ) : user !== null ? (
              <>
                <span className="hidden text-slate-600 md:inline">{user.name}</span>
                <button type="button" onClick={handleLogout} className="btn-secondary">
                  Sign out
                </button>
              </>
            ) : (
              <NavLink to="/login" className="btn-primary">
                Sign in
              </NavLink>
            )}
          </div>
        </div>

        <DataStatusStrip verdict={health.data?.status} isAdmin={user?.is_admin === true} />

        {health.isError && (
          <div role="status" className="border-t border-amber-200 bg-amber-50 px-4 py-2 text-center text-xs text-amber-900">
            Data freshness could not be checked. Verify price dates before using market data.
          </div>
        )}

        {health.data?.ai?.configured === false && (
          <div className="border-t border-sky-200 bg-sky-50 px-4 py-2 text-center text-xs text-sky-900">
            AI analysis is currently unavailable. Market data and portfolio tracking remain available.
            {user?.is_admin === true && ' Configure an AI provider on the server to enable analysis.'}
          </div>
        )}
      </header>

      <main id="main-content" ref={mainRef} tabIndex={-1} className="mx-auto max-w-7xl px-4 py-6 focus:outline-none">
        <ErrorBoundary key={location.pathname}>
          <Outlet />
        </ErrorBoundary>
      </main>

      <footer className="mx-auto max-w-7xl px-4 py-8 text-xs text-slate-400">
        <p>
          Educational and research project — not investment advice. Data is collected from public
          sources and may be delayed or wrong; AI analysis can be mistaken. Verify before trading.
          Not affiliated with NEPSE, SEBON or CDSC.
        </p>
        <Link to="/about#disclaimer" className="mt-2 inline-block text-slate-600 underline">About, disclaimer &amp; data use</Link>
      </footer>
    </div>
  )
}

/**
 * Says out loud whether the data can be trusted today.
 *
 * Silence is the dangerous state: a stopped scheduler leaves every page looking
 * completely normal while prices freeze, which is exactly how this project lost
 * two days without noticing. Anything other than "fine" names the cause and the
 * command that fixes it, so a reader who does not know the pipeline can still
 * act.
 */
function DataStatusStrip({ verdict, isAdmin }: { verdict: HealthVerdict | undefined; isAdmin: boolean }) {
  if (verdict === undefined || verdict.level === 'ok') return null

  const tone =
    verdict.level === 'error'
      ? 'border-red-200 bg-red-50 text-red-900'
      : 'border-amber-200 bg-amber-50 text-amber-900'

  return (
    <div role="status" className={clsx('border-t px-4 py-2 text-center text-xs', tone)}>
      <strong>{verdict.headline}.</strong>{' '}
      {isAdmin ? verdict.detail : 'Market data may be delayed. Check the date shown with each price.'}
      {isAdmin && verdict.action !== null && (
        <>
          {' '}
          Run{' '}
          <code className="rounded bg-white/60 px-1 font-mono">{verdict.action}</code> in the
          backend directory.
        </>
      )}
    </div>
  )
}

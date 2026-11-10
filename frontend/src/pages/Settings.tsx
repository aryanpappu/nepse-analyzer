import { useState } from 'react'
import { EmailVerification } from '../components/EmailVerification'
import { AccountDataControls } from '../components/AccountDataControls'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '../lib/api'
import { useAuth } from '../lib/auth'
import { compactMoney, money } from '../lib/format'
import { Card, ErrorBox, Npr, Spinner } from '../components/ui'
import type { PortfolioSummary } from '../lib/types'

const INVESTOR_CLASSES = [
  { value: 'individual', label: 'Individual', hint: '7.5% under 365 days, 5% over' },
  { value: 'institution', label: 'Institution', hint: 'Flat rate, no holding-period split' },
]

export function Settings() {
  const { user, updateProfile } = useAuth()

  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [emailPassword, setEmailPassword] = useState('')
  const [fdRate, setFdRate] = useState(
    user?.benchmark_fd_rate === null || user?.benchmark_fd_rate === undefined
      ? ''
      : String(user.benchmark_fd_rate),
  )
  const [investorClass, setInvestorClass] = useState(user?.investor_class ?? '')

  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirm, setPasswordConfirm] = useState('')

  const queryClient = useQueryClient()
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  /*
   * Where the money actually sits. The user's question is "how much is in a
   * fixed deposit versus in the market", and the portfolio summary already
   * decomposes net worth into exactly those parts.
   */
  const allocation = useQuery({
    queryKey: ['portfolio', user?.default_portfolio_id],
    queryFn: async () =>
      (await api.get<{ data: PortfolioSummary }>(`/portfolios/${user?.default_portfolio_id}`)).data.data,
    enabled: user?.default_portfolio_id != null,
  })

  if (user === null) return <Spinner label="Loading your profile…" />

  async function save(changes: Parameters<typeof updateProfile>[0], label: string) {
    setSaving(true)
    setError(null)
    setSaved(null)

    try {
      await updateProfile(changes)
      setSaved(label)
      // Setting your own deposit rate completes a getting-started step.
      void queryClient.invalidateQueries({ queryKey: ['onboarding'] })
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setSaving(false)
    }
  }

  const components = allocation.data?.net_worth_components
  const placements = allocation.data?.placements
  const inMarket = components?.equity_market_value ?? null
  const inDeposits = components?.settled_placements ?? null
  const inCash = components?.investable_cash ?? null
  const total =
    inMarket !== null && inDeposits !== null && inCash !== null ? inMarket + inDeposits + inCash : null

  const share = (part: number | null): string =>
    part === null || total === null || total <= 0 ? 'n/a' : `${((part / total) * 100).toFixed(1)}%`

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Profile &amp; settings</h1>
        <p className="mt-1 text-sm text-slate-500">
          Your account details, and the benchmarks your profit is measured against.
        </p>
      </div>

      {error !== null && <ErrorBox message={error} />}
      {saved !== null && (
        <p className="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
          {saved}
        </p>
      )}

      <Card title="Account" subtitle="Your name and sign-in email.">
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <span className="text-xs uppercase tracking-wide text-slate-400">Name</span>
            <input className="input mt-1 w-full" value={name} onChange={(e) => setName(e.target.value)} />
          </label>
          <label className="block">
            <span className="text-xs uppercase tracking-wide text-slate-400">Email</span>
            <input
              className="input mt-1 w-full"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </label>
        </div>

        {email !== user.email && (
          <label className="mt-4 block max-w-sm">
            <span className="label">Current password to change your email</span>
            <input className="input w-full" type="password" autoComplete="current-password" value={emailPassword} onChange={(event) => setEmailPassword(event.target.value)} />
          </label>
        )}
        <button
          type="button"
          className="btn-primary mt-4"
          disabled={saving || (email !== user.email && emailPassword === '')}
          onClick={async () => {
            await save({ name, email, ...(email !== user.email ? { current_password: emailPassword } : {}) }, 'Account details saved.')
            setEmailPassword('')
          }}
        >
          {saving ? 'Saving…' : 'Save account details'}
        </button>
      </Card>

      <Card
        title="What your profit is compared against"
        subtitle="Two benchmarks: leaving the money in the bank, and simply buying the market."
      >
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block">
            <span className="text-xs uppercase tracking-wide text-slate-400">
              Your fixed-deposit rate
            </span>
            <div className="mt-1 flex items-center gap-2">
              <input
                className="input w-28 text-right"
                type="number"
                step="0.25"
                min="0"
                max="30"
                value={fdRate}
                placeholder={String(user.benchmark_fd_rate_default)}
                onChange={(e) => setFdRate(e.target.value)}
              />
              <span className="text-sm text-slate-500">%</span>
            </div>
            <p className="mt-1 text-xs text-slate-400">
              Deposit rates differ by bank and by negotiation. Leave this blank to use the default of{' '}
              {user.benchmark_fd_rate_default}% — currently in force:{' '}
              <strong>{user.benchmark_fd_rate_effective}%</strong>.
            </p>
          </label>

          <label className="block">
            <span className="text-xs uppercase tracking-wide text-slate-400">Tax treatment</span>
            <select
              className="input mt-1 w-full"
              value={investorClass}
              onChange={(e) => setInvestorClass(e.target.value)}
            >
              <option value="">Default ({user.investor_class_effective})</option>
              {INVESTOR_CLASSES.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label} — {c.hint}
                </option>
              ))}
            </select>
            <p className="mt-1 text-xs text-slate-400">
              Sets the capital-gains rate band. The 365-day boundary is worth a third of the bill.
            </p>
          </label>
        </div>

        <button
          type="button"
          className="btn-primary mt-4"
          disabled={saving}
          onClick={() =>
            save(
              {
                benchmark_fd_rate: fdRate.trim() === '' ? null : Number(fdRate),
                investor_class: investorClass === '' ? null : investorClass,
              },
              'Comparison settings saved. The performance page and both benchmarks now use them.',
            )
          }
        >
          {saving ? 'Saving…' : 'Save comparison settings'}
        </button>
      </Card>

      <Card
        title="Where your money is"
        subtitle="How much sits in a fixed deposit versus invested in the market."
      >
        {allocation.isLoading && <Spinner label="Loading your allocation…" />}
        {allocation.isError && <ErrorBox message={errorMessage(allocation.error)} />}

        {allocation.data !== undefined && (
          <>
            <div className="grid gap-4 sm:grid-cols-4">
              <div>
                <p className="text-xs uppercase tracking-wide text-slate-400">In the market</p>
                <p className="num mt-1 text-xl font-semibold text-slate-900">
                  {inMarket === null ? 'n/a' : compactMoney(inMarket)}
                </p>
                <p className="mt-1 text-xs text-slate-400">{share(inMarket)} of the total</p>
              </div>
              <div>
                <p className="text-xs uppercase tracking-wide text-slate-400">In fixed deposits</p>
                <p className="num mt-1 text-xl font-semibold text-slate-900">
                  {inDeposits === null ? 'n/a' : compactMoney(inDeposits)}
                </p>
                <p className="mt-1 text-xs text-slate-400">{share(inDeposits)} of the total</p>
              </div>
              <div>
                <p className="text-xs uppercase tracking-wide text-slate-400">Idle cash</p>
                <p className="num mt-1 text-xl font-semibold text-slate-900">
                  {inCash === null ? 'n/a' : compactMoney(inCash)}
                </p>
                <p className="mt-1 text-xs text-slate-400">{share(inCash)} of the total</p>
              </div>
              <div>
                <p className="text-xs uppercase tracking-wide text-slate-400">Total</p>
                <p className="num mt-1 text-xl font-semibold text-slate-900">
                  {total === null ? 'n/a' : compactMoney(total)}
                </p>
              </div>
            </div>

            {placements != null && placements.total_interest != null && (
              <p className="mt-4 text-sm text-slate-600">
                Your deposits have earned <Npr value={placements.total_interest} /> in interest
                {placements.principal_placed != null && (
                  <> on {money(placements.principal_placed)} placed</>
                )}
                . That is income, not capital growth, and is reported separately from the equity
                comparison.
              </p>
            )}

            {placements?.note != null && (
              <p className="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {placements.note}
              </p>
            )}
          </>
        )}
      </Card>

      <Card title="Password" subtitle="Changing it needs your current password.">
        <div className="grid gap-4 sm:grid-cols-3">
          <label className="block">
            <span className="text-xs uppercase tracking-wide text-slate-400">Current password</span>
            <input
              className="input mt-1 w-full"
              type="password"
              autoComplete="current-password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
            />
          </label>
          <label className="block">
            <span className="text-xs uppercase tracking-wide text-slate-400">New password</span>
            <input
              className="input mt-1 w-full"
              type="password"
              autoComplete="new-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </label>
          <label className="block">
            <span className="text-xs uppercase tracking-wide text-slate-400">Confirm new password</span>
            <input
              className="input mt-1 w-full"
              type="password"
              autoComplete="new-password"
              value={passwordConfirm}
              onChange={(e) => setPasswordConfirm(e.target.value)}
            />
          </label>
        </div>

        <button
          type="button"
          className="btn-secondary mt-4"
          disabled={saving || password === '' || currentPassword === ''}
          onClick={async () => {
            await save(
              {
                current_password: currentPassword,
                password,
                password_confirmation: passwordConfirm,
              },
              'Password changed.',
            )
            setCurrentPassword('')
            setPassword('')
            setPasswordConfirm('')
          }}
        >
          {saving ? 'Saving…' : 'Change password'}
        </button>
      </Card>
      <EmailVerification />
      <AccountDataControls />
    </div>
  )
}

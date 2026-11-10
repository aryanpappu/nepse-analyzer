import type { ReactNode } from 'react'
import clsx from 'clsx'
import { changeBg, changeColor, money, riskColor, signalColor, signedPercent } from '../lib/format'

export function Spinner({ label }: { label?: string }) {
  return (
    <div role="status" className="flex items-center justify-center gap-3 py-10 text-sm text-slate-500">
      <span aria-hidden="true" className="h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-slate-700" />
      {label ?? 'Loading…'}
    </div>
  )
}

export function ErrorBox({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
      <p className="font-medium">{message}</p>
      {onRetry !== undefined && (
        <button type="button" onClick={onRetry} className="mt-2 font-semibold underline">
          Try again
        </button>
      )}
    </div>
  )
}

export function EmptyState({
  title,
  hint,
  action,
}: {
  title: string
  hint?: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center gap-2 py-12 text-center">
      <p className="font-medium text-slate-700">{title}</p>
      {hint !== undefined && <p className="max-w-md text-sm text-slate-500">{hint}</p>}
      {action}
    </div>
  )
}

export function Card({
  title,
  subtitle,
  action,
  children,
  className,
}: {
  title?: string
  subtitle?: string
  action?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section className={clsx('card', className)}>
      {(title !== undefined || action !== undefined) && (
        <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
          <div>
            {title !== undefined && <h2 className="font-semibold text-slate-800">{title}</h2>}
            {subtitle !== undefined && <p className="text-xs text-slate-500">{subtitle}</p>}
          </div>
          {action}
        </header>
      )}
      <div className="p-4">{children}</div>
    </section>
  )
}

export function StatTile({
  label,
  value,
  delta,
  hint,
  tone,
}: {
  /** A node, not just a string, so a label can carry an <Explain> tooltip. */
  label: ReactNode
  value: string
  delta?: number | null
  hint?: string
  tone?: 'gain' | 'loss' | 'neutral'
}) {
  const valueTone =
    tone === 'gain' ? 'text-gain' : tone === 'loss' ? 'text-loss' : 'text-slate-900'

  return (
    <div className="card p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={clsx('num mt-1 text-2xl font-semibold', valueTone)}>{value}</p>
      {delta !== undefined && delta !== null && (
        <p className={clsx('num mt-1 text-sm font-medium', changeColor(delta))}>
          {signedPercent(delta)}
        </p>
      )}
      {hint !== undefined && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  )
}

export function SignalBadge({
  signal,
  confidence,
  stale,
}: {
  signal: string | null | undefined
  confidence?: number | null
  stale?: boolean
}) {
  if (signal === null || signal === undefined) {
    return <span className="badge bg-slate-100 text-slate-500">No signal</span>
  }

  return (
    <span
      className={clsx('badge', signalColor(signal), stale === true && 'opacity-60')}
      title={stale === true ? 'This signal is older than the freshness window' : undefined}
    >
      {signal.toUpperCase()}
      {confidence !== null && confidence !== undefined && ` ${confidence}%`}
      {stale === true && ' ·stale'}
    </span>
  )
}

export function RiskBadge({ risk }: { risk: string | null | undefined }) {
  if (risk === null || risk === undefined) return null

  return <span className={clsx('badge', riskColor(risk))}>{risk}</span>
}

export function ChangePill({ value }: { value: number | null | undefined }) {
  return (
    <span className={clsx('badge num', changeBg(value))}>{signedPercent(value)}</span>
  )
}

/** A labelled row for detail panels. */
export function Field({ label, children }: { label: ReactNode; children: ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-4 border-b border-slate-100 py-2 last:border-0">
      <dt className="text-sm text-slate-500">{label}</dt>
      <dd className="num text-sm font-medium text-slate-900">{children}</dd>
    </div>
  )
}

/** NPR-prefixed money, so the currency is never ambiguous. */
export function Npr({ value, decimals = 2 }: { value: number | null | undefined; decimals?: number }) {
  return (
    <span className="num">
      <span className="text-xs text-slate-400">NPR </span>
      {money(value, decimals)}
    </span>
  )
}

export function ScoreBar({ score, label }: { score: number | null | undefined; label: string }) {
  const safe = score ?? 0
  const tone = safe >= 75 ? 'bg-gain' : safe >= 50 ? 'bg-amber-500' : 'bg-loss'

  return (
    <div>
      <div className="mb-1 flex items-baseline justify-between">
        <span className="text-xs text-slate-500">{label}</span>
        <span className="num text-sm font-semibold text-slate-800">
          {score === null || score === undefined ? '—' : `${score}/100`}
        </span>
      </div>
      <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
        <div className={clsx('h-full rounded-full', tone)} style={{ width: `${safe}%` }} />
      </div>
    </div>
  )
}

export function Banner({
  tone,
  children,
}: {
  tone: 'info' | 'warning' | 'error' | 'success'
  children: ReactNode
}) {
  const tones = {
    info: 'border-sky-200 bg-sky-50 text-sky-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    error: 'border-red-200 bg-red-50 text-red-900',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
  }

  return (
    <div role={tone === 'error' ? 'alert' : 'status'} className={clsx('rounded-lg border p-3 text-sm', tones[tone])}>{children}</div>
  )
}

/** Marks a scrip whose analysis is NAV-based rather than technical. */
export function FundTag() {
  return (
    <span
      className="badge bg-violet-100 text-violet-800"
      title="Closed-end mutual fund — valued on NAV, not chart momentum"
    >
      FUND
    </span>
  )
}

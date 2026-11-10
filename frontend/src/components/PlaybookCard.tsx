import clsx from 'clsx'
import { gradeClasses } from '../lib/profile'
import { money, signedPercent } from '../lib/format'
import type { PlaybookVerdict } from '../lib/types'

/**
 * One profile's plan for one scrip.
 *
 * Deliberately leads with the levels rather than the score: a number out of 100
 * is an opinion, but an entry band, a stop and two targets are a decision you
 * can act on and be held to.
 */
export function PlaybookCard({
  verdict,
  active,
  onSelect,
}: {
  verdict: PlaybookVerdict
  active: boolean
  onSelect?: () => void
}) {
  const unavailable = verdict.source === 'unavailable' || verdict.grade === null

  return (
    <button
      type="button"
      onClick={onSelect}
      aria-pressed={active}
      className={clsx(
        'w-full rounded-xl border p-4 text-left transition',
        active
          ? 'border-slate-800 bg-white shadow-sm ring-1 ring-slate-800'
          : 'border-slate-200 bg-white hover:border-slate-300',
        unavailable && 'opacity-70',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="text-sm font-semibold text-slate-800">{verdict.profile_label}</p>
          {verdict.hold_label !== undefined && (
            <p className="text-xs text-slate-500">Hold {verdict.hold_label}</p>
          )}
        </div>

        {unavailable ? (
          <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500 ring-1 ring-slate-200">
            No read
          </span>
        ) : (
          <span
            className={clsx(
              'rounded-md px-2 py-0.5 text-xs font-semibold ring-1',
              gradeClasses(verdict.grade),
            )}
          >
            {verdict.grade_label}
          </span>
        )}
      </div>

      {unavailable ? (
        <p className="mt-3 text-xs leading-relaxed text-slate-500">{verdict.summary}</p>
      ) : (
        <>
          <div className="mt-3 flex items-baseline gap-2">
            <span className="num text-2xl font-semibold text-slate-900">
              {(verdict.score ?? 0).toFixed(0)}
            </span>
            <span className="text-xs text-slate-400">/ 100</span>

            {verdict.price_in_zone === true && (
              <span className="ml-auto rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700">
                In buy zone
              </span>
            )}
          </div>

          <dl className="mt-3 space-y-1 text-xs">
            <Level label="Buy zone" value={`${money(verdict.entry_low, 0)} – ${money(verdict.entry_high, 0)}`} />
            <Level label="Stop" value={money(verdict.stop_loss, 0)} tone="loss" />
            <Level
              label="Targets"
              value={`${money(verdict.target_1, 0)} → ${money(verdict.target_2, 0)}`}
              tone="gain"
            />
            <Level
              label="Risk / reward"
              value={verdict.risk_reward === null || verdict.risk_reward === undefined
                ? '—'
                : `${verdict.risk_reward.toFixed(2)} : 1`}
              tone={(verdict.risk_reward ?? 0) >= 1.5 ? 'gain' : undefined}
            />
            {verdict.expected_return_percent !== null && verdict.expected_return_percent !== undefined && (
              <Level label="Upside to T1" value={signedPercent(verdict.expected_return_percent)} />
            )}
          </dl>
        </>
      )}
    </button>
  )
}

function Level({
  label,
  value,
  tone,
}: {
  label: string
  value: string
  tone?: 'gain' | 'loss'
}) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-slate-500">{label}</dt>
      <dd
        className={clsx(
          'num font-medium',
          tone === 'gain' && 'text-emerald-700',
          tone === 'loss' && 'text-rose-700',
          tone === undefined && 'text-slate-800',
        )}
      >
        {value}
      </dd>
    </div>
  )
}

/**
 * The evidence behind a score, grouped so a user can see which family of
 * reasons is driving it rather than a flat list of assertions.
 */
export function VerdictReasons({ verdict }: { verdict: PlaybookVerdict }) {
  const reasons = verdict.reasons ?? []
  const scores = verdict.scores ?? {}

  if (reasons.length === 0 && Object.keys(scores).length === 0) return null

  return (
    <div className="space-y-4">
      {Object.keys(scores).length > 0 && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
          {Object.entries(scores).map(([bucket, value]) => (
            <div key={bucket}>
              <p className="text-[11px] uppercase tracking-wide text-slate-400">{bucket}</p>
              <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
                <div
                  className={clsx(
                    'h-full rounded-full',
                    value >= 70 ? 'bg-emerald-500' : value >= 45 ? 'bg-amber-400' : 'bg-rose-400',
                  )}
                  style={{ width: `${Math.max(2, Math.min(100, value))}%` }}
                />
              </div>
              <p className="num mt-0.5 text-xs text-slate-600">{value.toFixed(0)}</p>
            </div>
          ))}
        </div>
      )}

      {reasons.length > 0 && (
        <ul className="space-y-1.5">
          {reasons.map((reason, index) => (
            <li key={`${reason.bucket}-${index}`} className="flex gap-2 text-xs">
              <span
                className={clsx(
                  'mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full',
                  reason.effect === 'positive' && 'bg-emerald-500',
                  reason.effect === 'negative' && 'bg-rose-500',
                  reason.effect === 'neutral' && 'bg-slate-300',
                )}
              />
              <span className="text-slate-600">
                <span className="text-slate-400">{reason.bucket}</span> · {reason.text}
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

import clsx from 'clsx'
import { money, shortDate } from '../lib/format'
import { Banner, Card } from './ui'
import type { PortfolioSummary } from '../lib/types'

/**
 * What net worth is actually made of.
 *
 * Three disjoint parts, and the disjointness is the point: equity marked to
 * market, idle cash, and only those fixed deposits whose principal
 * demonstrably left a cash account. A deposit recorded without a cash
 * settlement is still sitting in the bank balance, so counting it here as well
 * would count the same rupees twice — it is therefore excluded, and said to be
 * excluded, rather than quietly added.
 */
export function NetWorthBreakdown({ summary }: { summary: PortfolioSummary }) {
  const parts = summary.net_worth_components
  const placements = summary.placements

  if (parts === undefined) {
    return null
  }

  const hasUnsettled = placements !== undefined && placements.unsettled_count > 0

  return (
    <Card
      title="What net worth is made of"
      subtitle={
        placements === undefined || placements.count === 0
          ? 'Equity plus idle cash. No fixed deposits recorded.'
          : `As of ${shortDate(placements.as_of)} · ${placements.count} placement${
              placements.count === 1 ? '' : 's'
            } on record`
      }
    >
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Part label="Equity at market" value={parts.equity_market_value} />
        <Part label="Investable cash" value={parts.investable_cash} />
        <Part
          label="Settled deposits"
          value={parts.settled_placements}
          hint={
            placements === undefined || placements.count === 0
              ? undefined
              : `${placements.settled_count} of ${placements.count} placements had their principal debited from cash`
          }
        />
        <Part label="Net worth" value={summary.net_worth} emphasis />
      </div>

      {placements !== undefined && placements.count > 0 && (
        <>
          <dl className="mt-4 grid gap-3 border-t border-slate-100 pt-3 text-sm sm:grid-cols-3">
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-400">Principal placed</dt>
              <dd className="num font-medium text-slate-800">
                NPR {money(placements.principal_placed)}
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-400">
                Deposit interest earned
              </dt>
              <dd className="num font-medium text-slate-800">
                NPR {money(placements.total_interest)}
              </dd>
              <dd className="text-xs text-slate-400">
                Income, deliberately not added to the placement value
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-400">
                Recorded but not settled
              </dt>
              <dd
                className={clsx(
                  'num font-medium',
                  hasUnsettled ? 'text-amber-700' : 'text-slate-800',
                )}
              >
                NPR {money(placements.unsettled_value)}
              </dd>
              <dd className="text-xs text-slate-400">
                {hasUnsettled
                  ? 'Already counted as idle cash above'
                  : 'Every placement was cash-settled'}
              </dd>
            </div>
          </dl>

          {placements.note !== null && (
            <div className="mt-3">
              <Banner tone={hasUnsettled ? 'warning' : 'info'}>{placements.note}</Banner>
            </div>
          )}

          {placements.excluded.length > 0 && (
            <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Left out of the valuation
              </p>
              <ul className="mt-1 space-y-1 text-xs text-slate-600">
                {placements.excluded.map((item) => (
                  <li key={item.label}>
                    <span className="font-medium text-slate-800">{item.label}</span> — {item.reason}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </>
      )}
    </Card>
  )
}

function Part({
  label,
  value,
  hint,
  emphasis,
}: {
  label: string
  value: number
  hint?: string
  emphasis?: boolean
}) {
  return (
    <div
      className={clsx(
        'rounded-lg p-3',
        emphasis === true ? 'bg-slate-900 text-white' : 'bg-slate-50',
      )}
    >
      <p
        className={clsx(
          'text-xs font-medium uppercase tracking-wide',
          emphasis === true ? 'text-slate-300' : 'text-slate-500',
        )}
      >
        {label}
      </p>
      <p className="num mt-1 text-xl font-semibold">
        <span className={clsx('text-xs', emphasis === true ? 'text-slate-400' : 'text-slate-400')}>
          NPR{' '}
        </span>
        {money(value)}
      </p>
      {hint !== undefined && (
        <p
          className={clsx(
            'mt-1 text-xs',
            emphasis === true ? 'text-slate-400' : 'text-slate-400',
          )}
        >
          {hint}
        </p>
      )}
    </div>
  )
}

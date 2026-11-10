import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { compactMoney, money, signedPercent } from '../lib/format'
import { Card, ErrorBox, Spinner } from './ui'
import { actionClasses, useProfile } from '../lib/profile'
import type { ActionsResponse, PositionAdvice } from '../lib/types'

/**
 * What to do about each position you hold, for the currently selected profile.
 *
 * The answer is deliberately lens-dependent: a portfolio of long-term holdings
 * viewed as a swing trader's book will show a lot of exits, because that is the
 * honest answer to "should a swing trader be in these". The banner says so,
 * rather than letting the list read as an alarm.
 */
export function PositionActions({ portfolioId }: { portfolioId: number }) {
  const { profile, meta } = useProfile()

  const actions = useQuery({
    queryKey: ['portfolio-actions', portfolioId, profile],
    queryFn: async () =>
      (
        await api.get<ActionsResponse>(`/portfolios/${portfolioId}/actions`, {
          params: { profile },
        })
      ).data,
  })

  const rows = actions.data?.data ?? []
  const info = actions.data?.meta

  return (
    <Card
      title="What to do now"
      subtitle={
        info === undefined
          ? undefined
          : `Judged as a ${info.profile_label.toLowerCase()} — typical hold ${info.hold_label}, position cap ${info.max_position_weight_percent}%.`
      }
      action={
        info !== undefined && info.needs_attention > 0 ? (
          <span className="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800 ring-1 ring-amber-200">
            {info.needs_attention} need{info.needs_attention === 1 ? 's' : ''} a decision
          </span>
        ) : undefined
      }
    >
      {actions.isLoading ? (
        <Spinner />
      ) : actions.isError ? (
        <ErrorBox message={errorMessage(actions.error)} onRetry={() => actions.refetch()} />
      ) : rows.length === 0 ? (
        <p className="py-6 text-center text-sm text-slate-500">
          No open positions to advise on.
        </p>
      ) : (
        <div className="space-y-3">
          <p className="text-xs leading-relaxed text-slate-500">
            Every position is re-judged against the {meta?.short_label.toLowerCase() ?? profile}{' '}
            playbook and your own average cost. Switching the profile in the header changes the
            answers — a long-term holding is not a swing setup, and this list will say so.
          </p>

          <div className="-mx-4 overflow-x-auto sm:mx-0">
            <table className="min-w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-3 py-2">Action</th>
                  <th className="px-3 py-2">Scrip</th>
                  <th className="px-3 py-2 text-right">Held</th>
                  <th className="px-3 py-2 text-right">Avg cost</th>
                  <th className="px-3 py-2 text-right">Price</th>
                  <th className="px-3 py-2 text-right">P&amp;L</th>
                  <th className="px-3 py-2 text-right">Weight</th>
                  <th className="px-3 py-2">Levels</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((advice) => (
                  <AdviceRow key={advice.symbol} advice={advice} />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </Card>
  )
}

function AdviceRow({ advice }: { advice: PositionAdvice }) {
  return (
    <>
      <tr className={clsx(advice.urgency >= 4 && 'bg-rose-50/40')}>
        <td className="px-3 py-2">
          <span
            className={clsx(
              'whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-semibold ring-1',
              actionClasses(advice.action),
            )}
          >
            {advice.action_label}
          </span>
        </td>
        <td className="px-3 py-2">
          <span className="font-medium text-slate-800">{advice.symbol}</span>
          {advice.days_held !== null && (
            <span className="block text-xs text-slate-400">{advice.days_held} days held</span>
          )}
        </td>
        <td className="num px-3 py-2 text-right text-slate-600">{advice.quantity}</td>
        <td className="num px-3 py-2 text-right text-slate-600">
          {money(advice.average_cost, 0)}
        </td>
        <td className="num px-3 py-2 text-right text-slate-600">
          {advice.price === null ? '—' : money(advice.price, 0)}
        </td>
        <td
          className={clsx(
            'num px-3 py-2 text-right font-medium',
            (advice.unrealized_gain ?? 0) > 0 && 'text-gain',
            (advice.unrealized_gain ?? 0) < 0 && 'text-loss',
          )}
        >
          {advice.unrealized_gain === null ? '—' : compactMoney(advice.unrealized_gain)}
          {advice.unrealized_gain_percent !== null && (
            <span className="ml-1 text-xs font-normal opacity-70">
              {signedPercent(advice.unrealized_gain_percent, 1)}
            </span>
          )}
        </td>
        <td className="num px-3 py-2 text-right text-slate-600">
          {advice.weight_percent === null ? '—' : `${advice.weight_percent}%`}
        </td>
        <td className="px-3 py-2 text-xs text-slate-500">
          {advice.stop_loss === null ? (
            '—'
          ) : (
            <>
              <span className="text-rose-600">S {money(advice.stop_loss, 0)}</span>
              {/* The stop is anchored to your cost, the target to today's price,
                  so on a deeply underwater position the target can sit below the
                  stop. Showing both there reads as nonsense — the target only
                  means anything while it is still above the stop. */}
              {advice.target_1 !== null && advice.target_1 > advice.stop_loss && (
                <span className="text-sky-700"> · T {money(advice.target_1, 0)}</span>
              )}
              {advice.risk_at_stop !== null && (
                <span className="block text-slate-400">
                  {compactMoney(advice.risk_at_stop)} at risk
                </span>
              )}
            </>
          )}
        </td>
      </tr>

      {/* The reasoning sits under the row rather than in a tooltip: this is the
          part that turns advice into something a person can disagree with. */}
      <tr>
        <td colSpan={8} className="px-3 pb-3 pt-0">
          <p className="text-xs font-medium text-slate-700">{advice.headline}</p>
          {advice.suggested_quantity !== null && advice.suggested_amount !== null && (
            <p className="num text-xs text-slate-500">
              {advice.is_sell ? 'Releases' : 'Needs'} {money(advice.suggested_amount, 0)} for{' '}
              {advice.suggested_quantity} units.
            </p>
          )}
          <ul className="mt-1 space-y-0.5">
            {advice.reasons.map((reason, index) => (
              <li key={index} className="text-xs text-slate-500">
                · {reason}
              </li>
            ))}
          </ul>
        </td>
      </tr>
    </>
  )
}

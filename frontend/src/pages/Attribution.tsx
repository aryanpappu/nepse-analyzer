import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { compactMoney, shortDate, signedPercent } from '../lib/format'
import { Card, EmptyState, ErrorBox, Spinner, StatTile } from '../components/ui'
import { SetupNeeded } from '../components/GettingStarted'
import { useStepDone } from '../lib/onboarding'
import { Explain } from '../components/Explain'
import type { AttributionHolding, AttributionReport, AttributionSector, Portfolio } from '../lib/types'

/**
 * Why the portfolio did what it did.
 *
 * The benchmark gives a verdict; this decomposes it. Two questions get separate
 * answers: which holdings moved the number, and — sector by sector — whether
 * the scrips chosen beat simply owning the sector.
 */
export function Attribution() {
  const [showAll, setShowAll] = useState(false)

  const portfolios = useQuery({
    queryKey: ['portfolios'],
    queryFn: async () => (await api.get<{ data: Portfolio[] }>('/portfolios')).data.data,
  })

  const activeId = portfolios.data?.[0]?.id ?? null
  // Distinguishes an empty portfolio from a populated one with nothing to
  // show here; shares the getting-started query rather than asking again.
  const hasHoldings = useStepDone('holdings')

  const report = useQuery({
    queryKey: ['attribution', activeId],
    queryFn: async () =>
      (await api.get<{ data: AttributionReport }>(`/portfolios/${activeId}/attribution`)).data.data,
    enabled: activeId !== null,
  })

  if (portfolios.isLoading) return <Spinner label="Loading portfolios…" />
  if (portfolios.isError) {
    return <ErrorBox message={errorMessage(portfolios.error)} onRetry={() => portfolios.refetch()} />
  }
  if (activeId === null) {
    return (
      <SetupNeeded
        title="Nothing to attribute yet"
        because="Attribution pulls a return apart to show which holdings moved it and whether each sector beat its own sub-index. It needs holdings to pull apart."
      />
    )
  }
  if (report.isLoading) return <Spinner label="Decomposing the return…" />
  if (report.isError) {
    return <ErrorBox message={errorMessage(report.error)} onRetry={() => report.refetch()} />
  }
  if (report.data === undefined) return null

  const data = report.data

  if (data.holdings.length === 0) {
    // Two different absences. An empty portfolio needs the setup path; one
    // that holds positions the window cannot price needs to be told that
    // instead, or it sends the user off to re-import data already there.
    return hasHoldings === false ? (
      <SetupNeeded
        title="Nothing to attribute yet"
        because="Attribution pulls a return apart to show which holdings moved it and whether each sector beat its own sub-index. It needs holdings to pull apart."
      />
    ) : (
      <EmptyState
        title="Nothing to attribute in this window"
        hint="Attribution needs holdings with a priced window on both ends. Widen the period, or let another day of prices land."
      />
    )
  }

  // Sorted by absolute contribution: the question is what moved the number,
  // and a large negative matters exactly as much as a large positive.
  const ranked = [...data.holdings].sort(
    (a, b) => Math.abs(b.contribution_percent) - Math.abs(a.contribution_percent),
  )
  const holdings = showAll ? ranked : ranked.slice(0, 12)

  const withComparison = data.sectors.filter((s) => s.selection_gap_percent !== null)
  const worst = [...withComparison].sort(
    (a, b) => (a.selection_gap_percent ?? 0) - (b.selection_gap_percent ?? 0),
  )[0]

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Why the portfolio moved</h1>
        <p className="mt-1 text-sm text-slate-500">
          {shortDate(data.window.from)} to {shortDate(data.window.to)} — {data.window.days} days.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <StatTile
          label="Return over the window"
          value={data.total.return_percent === null ? 'n/a' : `${data.total.return_percent}%`}
        />
        <StatTile
          label="Profit"
          value={data.total.profit === null ? 'n/a' : compactMoney(data.total.profit)}
        />
        <StatTile
          label="Capital at risk"
          value={data.total.capital_base === null ? 'n/a' : compactMoney(data.total.capital_base)}
        />
      </div>

      {!data.coverage.complete && data.coverage.note !== null && (
        <div className="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <strong>Partial.</strong> {data.coverage.note}
        </div>
      )}

      {!data.reconciles && (
        <div className="rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          The contributions do not sum to the total return — off by{' '}
          {data.reconciliation_error_percent}pp. A holding is unaccounted for; treat this
          decomposition as unreliable.
        </div>
      )}

      {worst !== undefined && worst.selection_gap_percent !== null && worst.selection_gap_percent < 0 && (
        <Card title="The biggest single drag">
          <p className="text-sm text-slate-600">
            In <strong>{worst.sector}</strong> your scrips returned{' '}
            <strong>{signedPercent(worst.portfolio_return_percent ?? 0)}</strong> while the{' '}
            {worst.index_name} returned{' '}
            <strong>{signedPercent(worst.index_return_percent ?? 0)}</strong> — a gap of{' '}
            <strong className="text-loss">{worst.selection_gap_percent.toFixed(2)}pp</strong>. Owning
            the sector outright would have done better than the scrips picked within it.
          </p>
        </Card>
      )}

      <Card
        title="Sector by sector, against its own sub-index"
        subtitle="Like-for-like: a bank judged against banks, not against the whole market."
      >
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-2 py-2">Sector</th>
                <th className="px-2 py-2 text-right">Capital</th>
                <th className="px-2 py-2 text-right">Your scrips</th>
                <th className="px-2 py-2 text-right">
                  <Explain term="sub-index">Sub-index</Explain>
                </th>
                <th className="px-2 py-2 text-right">Gap</th>
              </tr>
            </thead>
            <tbody>
              {data.sectors.map((sector) => (
                <SectorRow key={sector.sector} sector={sector} />
              ))}
            </tbody>
          </table>
        </div>

        <p className="mt-3 text-xs text-slate-400">
          A sector with no sub-index — debentures, government bonds, preference and promoter shares —
          reports its own return but no comparison, rather than being set against the wrong thing.
        </p>
      </Card>

      <Card
        title="What each holding contributed"
        subtitle="Weight times return. The rows are constrained to sum to the total, so a row that looks wrong is wrong."
        action={
          ranked.length > 12 ? (
            <button type="button" className="btn-secondary" onClick={() => setShowAll((v) => !v)}>
              {showAll ? 'Show top 12' : `Show all ${ranked.length}`}
            </button>
          ) : undefined
        }
      >
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-2 py-2">Scrip</th>
                <th className="px-2 py-2">Sector</th>
                <th className="px-2 py-2 text-right">Weight</th>
                <th className="px-2 py-2 text-right">Its return</th>
                <th className="px-2 py-2 text-right">Profit</th>
                <th className="px-2 py-2 text-right">
                  <Explain term="contribution">Contribution</Explain>
                </th>
              </tr>
            </thead>
            <tbody>
              {holdings.map((holding) => (
                <HoldingRow key={holding.symbol} holding={holding} />
              ))}
            </tbody>
          </table>
        </div>

        <p className="mt-3 text-xs text-slate-400">{data.basis.note}</p>
      </Card>

      {!data.allocation_effect.available && (
        <Card title="Allocation versus selection">
          <p className="text-sm text-slate-600">{data.allocation_effect.reason}</p>
          {data.allocation_effect.outstanding_shares_coverage_percent !== null && (
            <p className="mt-2 text-xs text-slate-400">
              Share counts cover {data.allocation_effect.outstanding_shares_coverage_percent}% of
              equities ({data.allocation_effect.equities_with_outstanding_shares} of{' '}
              {data.allocation_effect.equities_total}), and{' '}
              {data.allocation_effect.dated_outstanding_shares_rows} of them now carry a reporting
              date.
            </p>
          )}
        </Card>
      )}

      {data.excluded.length > 0 && (
        <p className="text-xs text-slate-400">
          Excluded and named:{' '}
          {data.excluded.map((e) => `${e.symbol} (${e.reason})`).join('; ')}
        </p>
      )}
    </div>
  )
}

function SectorRow({ sector }: { sector: AttributionSector }) {
  const gap = sector.selection_gap_percent
  const partial = (sector.index_coverage_percent ?? 100) < 100

  return (
    <tr className="border-b border-slate-100">
      <td className="px-2 py-2">
        <span className="font-medium text-slate-900">{sector.sector}</span>
        <div className="text-xs text-slate-400">{sector.holdings.join(', ') || '—'}</div>
      </td>
      <td className="num px-2 py-2 text-right">{compactMoney(sector.capital_base)}</td>
      <td
        className={clsx(
          'num px-2 py-2 text-right',
          (sector.portfolio_return_percent ?? 0) >= 0 ? 'text-gain' : 'text-loss',
        )}
      >
        {sector.portfolio_return_percent === null
          ? <span className="text-slate-400">n/a</span>
          : `${sector.portfolio_return_percent}%`}
      </td>
      <td className="num px-2 py-2 text-right text-slate-600">
        {sector.index_return_percent === null ? (
          <span className="text-slate-400" title={sector.index_note ?? undefined}>
            n/a
          </span>
        ) : (
          `${sector.index_return_percent}%`
        )}
      </td>
      <td className="num px-2 py-2 text-right font-semibold">
        {gap === null ? (
          <span className="text-slate-400" title={sector.index_note ?? undefined}>
            n/a
          </span>
        ) : (
          <span className={gap >= 0 ? 'text-gain' : 'text-loss'}>
            {gap.toFixed(2)}pp
            {partial && (
              <span className="ml-1 text-xs font-normal text-amber-700" title={sector.index_note ?? undefined}>
                partial
              </span>
            )}
          </span>
        )}
      </td>
    </tr>
  )
}

function HoldingRow({ holding }: { holding: AttributionHolding }) {
  return (
    <tr className="border-b border-slate-100">
      <td className="px-2 py-2 font-medium text-slate-900">{holding.symbol}</td>
      <td className="px-2 py-2 text-xs text-slate-500">{holding.sector ?? 'unclassified'}</td>
      <td className="num px-2 py-2 text-right text-slate-600">
        {holding.weight_percent === null ? (
          <span
            className="text-slate-400"
            title="Opened and closed inside the window, so there is no standing capital to be a percentage of."
          >
            n/a
          </span>
        ) : (
          `${holding.weight_percent}%`
        )}
      </td>
      <td
        className={clsx(
          'num px-2 py-2 text-right',
          (holding.return_percent ?? 0) >= 0 ? 'text-gain' : 'text-loss',
        )}
      >
        {holding.return_percent === null ? (
          <span className="text-slate-400">n/a</span>
        ) : (
          `${holding.return_percent}%`
        )}
      </td>
      <td
        className={clsx('num px-2 py-2 text-right', holding.profit >= 0 ? 'text-gain' : 'text-loss')}
      >
        {compactMoney(holding.profit)}
      </td>
      <td
        className={clsx(
          'num px-2 py-2 text-right font-semibold',
          holding.contribution_percent >= 0 ? 'text-gain' : 'text-loss',
        )}
      >
        {holding.contribution_percent.toFixed(3)}pp
      </td>
    </tr>
  )
}

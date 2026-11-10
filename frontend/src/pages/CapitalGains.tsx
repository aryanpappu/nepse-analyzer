import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { compactMoney, money, shortDate } from '../lib/format'
import { Card, EmptyState, ErrorBox, Spinner, StatTile } from '../components/ui'
import { SetupNeeded } from '../components/GettingStarted'
import { useStepDone } from '../lib/onboarding'
import { Explain } from '../components/Explain'
import type { CapitalGainsDisposal, CapitalGainsReport, Portfolio } from '../lib/types'

const BASES = [
  { value: 'fiscal', label: 'Nepali fiscal year', hint: 'Shrawan 1 – Ashad end, how gains are reported' },
  { value: 'calendar', label: 'Calendar year', hint: 'January to December' },
]

/**
 * Estimated capital gains tax on realised disposals.
 *
 * Nepal splits the rate on the holding period — 7.5% under 365 days, 5% over —
 * so the day count is the whole story, and it is shown per disposal rather than
 * summarised away.
 */
export function CapitalGains() {
  const [basis, setBasis] = useState('fiscal')
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
    queryKey: ['capital-gains', activeId, basis],
    queryFn: async () =>
      (
        await api.get<{ data: CapitalGainsReport }>(`/portfolios/${activeId}/capital-gains`, {
          params: { basis },
        })
      ).data.data,
    enabled: activeId !== null,
  })

  if (portfolios.isLoading) return <Spinner label="Loading portfolios…" />
  if (portfolios.isError) {
    return <ErrorBox message={errorMessage(portfolios.error)} onRetry={() => portfolios.refetch()} />
  }
  if (activeId === null) {
    return (
      <SetupNeeded
        title="No capital gains to assess"
        because="Tax is estimated per disposal, so this page needs a portfolio with sales in it. The 365-day boundary is worth a third of the bill, which is why each holding period is counted rather than averaged."
      />
    )
  }
  if (report.isLoading) return <Spinner label="Assessing disposals…" />
  if (report.isError) {
    return <ErrorBox message={errorMessage(report.error)} onRetry={() => report.refetch()} />
  }
  if (report.data === undefined) return null

  const data = report.data
  const totals = data.totals

  if (totals.disposals === 0) {
    // "Nothing has been sold" is the right answer for a populated portfolio
    // and a dead end for an empty one, where nothing has been *bought* either.
    return hasHoldings === false ? (
      <SetupNeeded
        title="No capital gains to assess"
        because="Tax is estimated per disposal, so this page needs a portfolio with sales in it. The 365-day boundary is worth a third of the bill, which is why each holding period is counted rather than averaged."
      />
    ) : (
      <EmptyState
        title="No realised disposals"
        hint="Capital gains are assessed on shares you have sold, and nothing has been sold in this portfolio yet. Nothing to do — this page fills in when you sell."
      />
    )
  }

  // Tax resting on parcels whose purchase price was never recorded. Called out
  // because the figure is overstated by exactly this much until they are priced.
  /*
   * Zero cost splits two ways, and conflating them produced an instruction
   * that could never be satisfied: a confirmed bonus share really did cost
   * nothing, so its whole proceeds being taxed is the right answer, not a gap
   * to go and fill in.
   */
  const bonusSymbols = new Set(data.bonus_zero_cost_symbols ?? [])
  const allZeroCost = data.disposals.filter((d) => d.cost_basis === 0)
  const zeroCost = allZeroCost.filter((d) => !bonusSymbols.has(d.symbol))
  const bonusZeroCost = allZeroCost.filter((d) => bonusSymbols.has(d.symbol))
  const zeroCostTax = zeroCost.reduce((sum, d) => sum + (d.estimated_tax ?? 0), 0)
  const bonusTax = bonusZeroCost.reduce((sum, d) => sum + (d.estimated_tax ?? 0), 0)

  const disposals = showAll ? data.disposals : data.disposals.slice(0, 15)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">
            <Explain term="capital-gains">Capital gains</Explain>
          </h1>
          <p className="mt-1 text-sm text-slate-500">
            {data.rates.label} — {data.rates.short_term_rate_percent}% under{' '}
            {data.rates.long_term_threshold_days} days, {data.rates.long_term_rate_percent}% over.
          </p>
        </div>

        <div className="flex gap-2">
          {BASES.map((option) => (
            <button
              key={option.value}
              type="button"
              title={option.hint}
              className={clsx(basis === option.value ? 'btn-primary' : 'btn-secondary')}
              onClick={() => setBasis(option.value)}
            >
              {option.label}
            </button>
          ))}
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-4">
        <StatTile label="Disposals" value={String(totals.disposals)} />
        <StatTile
          label="Gain, net of losses"
          value={totals.gross_gain === null ? 'n/a' : compactMoney(totals.gross_gain)}
        />
        <StatTile
          label="Estimated tax"
          value={totals.estimated_tax === null ? 'n/a' : compactMoney(totals.estimated_tax)}
        />
        <StatTile
          label="Net of tax"
          value={totals.net_gain === null ? 'n/a' : compactMoney(totals.net_gain)}
        />
      </div>

      {zeroCost.length > 0 && (
        <div className="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <strong>
            {zeroCost.length} disposal{zeroCost.length === 1 ? ' has' : 's have'} no recorded
            purchase price
          </strong>{' '}
          ({[...new Set(zeroCost.map((d) => d.symbol))].join(', ')}), so their entire proceeds count
          as gain and <strong>{money(zeroCostTax)}</strong> of the tax above rests on them. These are
          parcels that reached your account outside the broker — an IPO allotment or a rights issue.
          Set a cost on the <em>IPO / Traded</em> page and this figure will fall.
        </div>
      )}

      {bonusZeroCost.length > 0 && (
        /* Neutral, not a warning: nothing here needs fixing. */
        <div className="rounded border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
          <strong className="text-slate-800">
            {bonusZeroCost.length} disposal{bonusZeroCost.length === 1 ? '' : 's'} of bonus shares
          </strong>{' '}
          ({[...new Set(bonusZeroCost.map((d) => d.symbol))].join(', ')}){' '}
          {bonusZeroCost.length === 1 ? 'carries' : 'carry'} no cost, which is correct — bonus shares are issued free, so the whole proceeds are gain and{' '}
          <strong>{money(bonusTax)}</strong> of tax follows from that. Nothing to fix.
        </div>
      )}

      {totals.taxable_gain !== null && totals.gross_gain !== null && totals.taxable_gain > totals.gross_gain && (
        <Card title="Why the taxable gain exceeds the gain">
          <p className="text-sm text-slate-600">
            Tax is withheld per disposal at settlement, so a losing trade does not reduce the tax
            charged on a winning one. Winners total{' '}
            <strong>{money(totals.taxable_gain)}</strong>, and{' '}
            <strong>{money(Math.abs(totals.realised_loss ?? 0))}</strong> of losses cannot be offset
            against them — which is why the tax is {totals.effective_tax_rate_percent}% of the gain
            rather than of the net.
          </p>
        </Card>
      )}

      <Card title="By rate band" subtitle="The 365-day boundary is worth a third of the bill.">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-2 py-2">Band</th>
                <th className="px-2 py-2 text-right">Rate</th>
                <th className="px-2 py-2 text-right">Disposals</th>
                <th className="px-2 py-2 text-right">Taxable gain</th>
                <th className="px-2 py-2 text-right">Tax</th>
              </tr>
            </thead>
            <tbody>
              {totals.bands.map((band) => (
                <tr key={band.band} className="border-b border-slate-100">
                  <td className="px-2 py-2 capitalize text-slate-900">
                    {band.band.replace(/_/g, ' ')}
                  </td>
                  <td className="num px-2 py-2 text-right">{band.rate_percent}%</td>
                  <td className="num px-2 py-2 text-right">{band.disposals}</td>
                  <td className="num px-2 py-2 text-right">{compactMoney(band.taxable_gain)}</td>
                  <td className="num px-2 py-2 text-right font-semibold">
                    {compactMoney(band.estimated_tax)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {totals.bands.length === 1 && totals.bands[0].band === 'short_term' && (
          <p className="mt-3 text-xs text-slate-500">
            Every disposal is short term, so nothing has yet qualified for the{' '}
            {data.rates.long_term_rate_percent}% rate. Holding past{' '}
            {data.rates.long_term_threshold_days} days would cut the rate by a third.
          </p>
        )}
      </Card>

      <Card
        title={`By ${basis === 'fiscal' ? 'fiscal' : 'calendar'} year`}
        subtitle={data.fiscal_year_note ?? undefined}
      >
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-2 py-2">Year</th>
                <th className="px-2 py-2 text-right">Disposals</th>
                <th className="px-2 py-2 text-right">Gain</th>
                <th className="px-2 py-2 text-right">Tax</th>
                <th className="px-2 py-2 text-right">Net</th>
              </tr>
            </thead>
            <tbody>
              {data.periods.map((period) => (
                <tr key={period.key} className="border-b border-slate-100">
                  <td className="px-2 py-2 text-slate-900">
                    {period.label}
                    {period.approximate_boundary && (
                      <span
                        className="ml-1 text-xs text-amber-700"
                        title="This year's Shrawan 1 boundary is derived, not from the conversion table."
                      >
                        approx
                      </span>
                    )}
                  </td>
                  <td className="num px-2 py-2 text-right">{period.disposals}</td>
                  <td
                    className={clsx(
                      'num px-2 py-2 text-right',
                      (period.gross_gain ?? 0) >= 0 ? 'text-gain' : 'text-loss',
                    )}
                  >
                    {period.gross_gain === null ? 'n/a' : compactMoney(period.gross_gain)}
                  </td>
                  <td className="num px-2 py-2 text-right">
                    {period.estimated_tax === null ? 'n/a' : compactMoney(period.estimated_tax)}
                  </td>
                  <td className="num px-2 py-2 text-right font-semibold">
                    {period.net_gain === null ? 'n/a' : compactMoney(period.net_gain)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <Card
        title="Every disposal"
        subtitle="The day count decides the rate, so it is shown rather than summarised away."
        action={
          data.disposals.length > 15 ? (
            <button type="button" className="btn-secondary" onClick={() => setShowAll((v) => !v)}>
              {showAll ? 'Show first 15' : `Show all ${data.disposals.length}`}
            </button>
          ) : undefined
        }
      >
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                <th className="px-2 py-2">Scrip</th>
                <th className="px-2 py-2">Bought</th>
                <th className="px-2 py-2">Sold</th>
                <th className="px-2 py-2 text-right">Days</th>
                <th className="px-2 py-2 text-right">Cost</th>
                <th className="px-2 py-2 text-right">Proceeds</th>
                <th className="px-2 py-2 text-right">Gain</th>
                <th className="px-2 py-2 text-right">Tax</th>
              </tr>
            </thead>
            <tbody>
              {disposals.map((disposal, index) => (
                <DisposalRow key={`${disposal.symbol}-${disposal.closed_on}-${index}`} disposal={disposal} />
              ))}
            </tbody>
          </table>
        </div>

        {data.disposals_truncated && (
          <p className="mt-3 text-xs text-slate-400">
            The server truncated this list; the totals above cover every disposal.
          </p>
        )}
      </Card>

      {!totals.coverage_complete && totals.coverage_note !== null && (
        <div className="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          <strong>Partial.</strong> {totals.coverage_note}
          {totals.excluded_symbols.length > 0 && (
            <> Excluded and named: {totals.excluded_symbols.join(', ')}.</>
          )}
        </div>
      )}

      {data.estimate_note !== null && (
        <p className="text-xs text-slate-400">{data.estimate_note}</p>
      )}
    </div>
  )
}

function DisposalRow({ disposal }: { disposal: CapitalGainsDisposal }) {
  const noCost = disposal.cost_basis === 0
  const longTerm = disposal.rate_band === 'long_term'

  return (
    <tr className={clsx('border-b border-slate-100', !disposal.is_assessable && 'opacity-60')}>
      <td className="px-2 py-2 font-medium text-slate-900">{disposal.symbol}</td>
      <td className="px-2 py-2 text-slate-500">
        {disposal.opened_on === null ? (
          <span className="text-amber-700">unknown</span>
        ) : (
          shortDate(disposal.opened_on)
        )}
      </td>
      <td className="px-2 py-2 text-slate-500">{shortDate(disposal.closed_on)}</td>
      <td className="num px-2 py-2 text-right">
        {disposal.days_held === null ? (
          'n/a'
        ) : (
          <span
            className={clsx(longTerm && 'text-gain')}
            title={disposal.rate_reason ?? undefined}
          >
            {disposal.days_held}
          </span>
        )}
      </td>
      <td className="num px-2 py-2 text-right">
        {noCost ? (
          <span className="text-amber-700" title="No purchase price recorded, so the whole proceeds count as gain.">
            not recorded
          </span>
        ) : (
          compactMoney(disposal.cost_basis ?? 0)
        )}
      </td>
      <td className="num px-2 py-2 text-right">{compactMoney(disposal.proceeds)}</td>
      <td
        className={clsx(
          'num px-2 py-2 text-right',
          (disposal.gross_gain ?? 0) >= 0 ? 'text-gain' : 'text-loss',
        )}
      >
        {disposal.gross_gain === null ? 'n/a' : compactMoney(disposal.gross_gain)}
      </td>
      <td className="num px-2 py-2 text-right">
        {disposal.estimated_tax === null ? (
          <span className="text-slate-400" title={disposal.exclusion_reason ?? undefined}>
            n/a
          </span>
        ) : (
          compactMoney(disposal.estimated_tax)
        )}
      </td>
    </tr>
  )
}

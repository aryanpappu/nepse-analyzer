import { useState } from 'react'
import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { compactMoney, money, shortDate, signedPercent } from '../lib/format'
import { Card, ErrorBox, Npr, Spinner, StatTile } from '../components/ui'
import { SetupNeeded } from '../components/GettingStarted'
import { FixedDepositCard } from '../components/FixedDepositCard'
import { Explain } from '../components/Explain'
import type {
  BenchmarkReport,
  PerformanceBasis,
  PerformanceReport,
  Portfolio,
} from '../lib/types'

const BASES: { value: PerformanceBasis; label: string; hint: string }[] = [
  {
    value: 'fiscal',
    label: 'Nepali FY',
    hint: 'Shrawan 1 to Ashad end — how capital gains and broker statements are reported.',
  },
  { value: 'calendar', label: 'Calendar', hint: 'January to December.' },
]

/**
 * Am I making money, and when did I make it.
 *
 * Realised figures come from the transaction ledger and are historical fact.
 * The unrealised figure is a mark at today's close and therefore belongs to the
 * live period only — closed years report what they realised, which is why the
 * unrealised column is blank for them rather than zero.
 */
export function Performance() {
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [basis, setBasis] = useState<PerformanceBasis>('fiscal')
  // The benchmark is only meaningful at a rate you could actually get, and that
  // differs by bank — so it is an input, not a constant.
  const [fdRate, setFdRate] = useState('')

  const portfolios = useQuery({
    queryKey: ['portfolios'],
    queryFn: async () => (await api.get<{ data: Portfolio[] }>('/portfolios')).data.data,
  })

  const activeId = selectedId ?? portfolios.data?.[0]?.id ?? null

  const report = useQuery({
    queryKey: ['performance', activeId, basis, fdRate],
    queryFn: async () =>
      (
        await api.get<{ data: PerformanceReport }>(`/portfolios/${activeId}/performance`, {
          params: { basis, fd_rate: fdRate === '' ? undefined : fdRate },
        })
      ).data.data,
    enabled: activeId !== null,
  })

  /*
   * Separate query: the risk metrics replay the portfolio at every sampled
   * interval, so it is slower than the year buckets and should not hold up the
   * rest of the page.
   */
  const benchmark = useQuery({
    queryKey: ['benchmark', activeId],
    queryFn: async () =>
      (
        await api.get<{ data: BenchmarkReport }>(`/portfolios/${activeId}/benchmark`, {
          params: { sections: 'market,risk' },
        })
      ).data.data,
    enabled: activeId !== null,
  })

  if (portfolios.isLoading) return <Spinner label="Loading portfolios…" />

  if (portfolios.isError) {
    return <ErrorBox message={errorMessage(portfolios.error)} onRetry={() => portfolios.refetch()} />
  }

  if ((portfolios.data ?? []).length === 0) {
    return (
      <SetupNeeded
        title="No performance to report"
        because="Every figure here is replayed from your transaction ledger — what each year earned, and whether it beat a deposit or the index. An empty ledger replays to nothing."
      />
    )
  }

  const data = report.data
  const totals = data?.totals

  /*
   * A portfolio that exists but holds nothing. Registration creates one
   * automatically, so this — not "no portfolio" — is what a brand-new user
   * actually meets here, and before this it was four zeroed tiles and an
   * annualised return of "—" with nothing to say what to do about it.
   */
  if (data !== undefined && totals?.first_transaction_on === null) {
    return (
      <SetupNeeded
        title="No performance to report yet"
        because="Every figure here is replayed from your transaction ledger — what each year earned, and whether it beat a deposit or the index. An empty ledger replays to nothing."
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Performance</h1>
          <p className="text-sm text-slate-500">
            Year by year, from your transaction ledger.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {(portfolios.data ?? []).length > 1 && (
            <select
              className="input"
              value={activeId ?? ''}
              onChange={(event) => setSelectedId(Number(event.target.value))}
            >
              {(portfolios.data ?? []).map((portfolio) => (
                <option key={portfolio.id} value={portfolio.id}>
                  {portfolio.name}
                </option>
              ))}
            </select>
          )}

          <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
            {BASES.map((option) => (
              <button
                key={option.value}
                type="button"
                title={option.hint}
                onClick={() => setBasis(option.value)}
                className={clsx(
                  'rounded-md px-3 py-1 text-xs font-medium transition',
                  basis === option.value
                    ? 'bg-white text-slate-900 shadow-sm'
                    : 'text-slate-500 hover:text-slate-800',
                )}
              >
                {option.label}
              </button>
            ))}
          </div>
        </div>
      </div>

      {report.isLoading ? (
        <Spinner label="Working out your numbers…" />
      ) : report.isError ? (
        <ErrorBox message={errorMessage(report.error)} onRetry={() => report.refetch()} />
      ) : data === undefined || totals === undefined ? null : (
        <>
          {/* Lifetime */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile
              label={<Explain term="net-profit">Net profit</Explain>}
              value={compactMoney(totals.net_profit)}
              tone={totals.net_profit >= 0 ? 'gain' : 'loss'}
              hint="Realised + dividends + unrealised"
            />
            <StatTile
              label={<Explain term="realised">Realised</Explain>}
              value={compactMoney(totals.realized_gain + totals.dividends)}
              tone={totals.realized_gain + totals.dividends >= 0 ? 'gain' : 'loss'}
              hint={`Includes ${money(totals.dividends, 0)} of dividends`}
            />
            <StatTile
              label={<Explain term="unrealised">Unrealised</Explain>}
              value={compactMoney(totals.unrealized_gain)}
              tone={totals.unrealized_gain >= 0 ? 'gain' : 'loss'}
              hint="Marked at the latest close"
            />
            <StatTile
              label={<Explain term="annualised-return">Annualised return</Explain>}
              value={
                totals.annualized_return_percent === null
                  ? '—'
                  : signedPercent(totals.annualized_return_percent)
              }
              tone={
                totals.annualized_return_percent === null
                  ? 'neutral'
                  : totals.annualized_return_percent >= 0
                    ? 'gain'
                    : 'loss'
              }
              hint="Money-weighted (XIRR), so later deposits count for less"
            />
          </div>

          <MarketBenchmarkCard report={benchmark.data} isLoading={benchmark.isLoading} />

          {/* The question this page exists to answer — now stated on an
              equity-only basis, with real deposit income beside it rather than
              folded into it. */}
          <FixedDepositCard
            totals={totals}
            basis={data.fixed_deposit_basis}
            fdRate={fdRate}
            onFdRate={setFdRate}
          />

          {/* Trading record */}
          <Card
            title="Trading record"
            subtitle={
              totals.trades_closed === 0
                ? 'No round trips closed yet — these fill in once you sell something.'
                : `${totals.trades_closed} round trips closed since ${
                    totals.first_transaction_on === null
                      ? 'the start'
                      : shortDate(totals.first_transaction_on)
                  }`
            }
          >
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
              <Metric label={<Explain term="win-rate">Win rate</Explain>} value={totals.win_rate === null ? '—' : `${totals.win_rate}%`} />
              <Metric label="Wins" value={String(totals.wins)} tone="gain" />
              <Metric label="Losses" value={String(totals.losses)} tone="loss" />
              <Metric
                label="Average win"
                value={totals.average_win === null ? '—' : money(totals.average_win, 0)}
                tone="gain"
              />
              <Metric
                label="Average loss"
                value={totals.average_loss === null ? '—' : money(totals.average_loss, 0)}
                tone="loss"
              />
              <Metric
                label="Profit factor"
                value={totals.profit_factor === null ? '—' : totals.profit_factor.toFixed(2)}
                hint="Won ÷ lost. Above 1 means the winners carry the account."
              />
            </div>

            {totals.average_days_held !== null && (
              <p className="mt-4 text-xs text-slate-500">
                Average holding period {totals.average_days_held} days. Total fees paid{' '}
                <Npr value={totals.total_fees} decimals={0} />.
              </p>
            )}
          </Card>

          {/* The year-by-year table this page exists for */}
          <Card
            title={basis === 'fiscal' ? 'By fiscal year' : 'By calendar year'}
            subtitle={data.fiscal_year_note ?? undefined}
          >
            {data.periods.length === 0 ? (
              <p className="py-6 text-center text-sm text-slate-500">
                No transactions recorded yet.
              </p>
            ) : (
              <div className="-mx-4 overflow-x-auto sm:mx-0">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                      <th className="px-3 py-2">Period</th>
                      <th className="px-3 py-2 text-right">Opening</th>
                      <th className="px-3 py-2 text-right">Put in</th>
                      <th className="px-3 py-2 text-right">Closing</th>
                      <th className="px-3 py-2 text-right">Profit</th>
                      <th className="px-3 py-2 text-right">Return</th>
                      <th className="px-3 py-2 text-right">vs FD</th>
                      <th className="px-3 py-2 text-right">Realised</th>
                      <th className="px-3 py-2 text-right">Trades</th>
                      <th className="px-3 py-2 text-right">Win rate</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {data.periods.map((period) => (
                      <tr key={period.key} className={clsx(period.is_current && 'bg-slate-50')}>
                        <td className="px-3 py-2">
                          <span className="font-medium text-slate-800">{period.label}</span>
                          {period.is_current && (
                            <span className="ml-2 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">
                              current
                            </span>
                          )}
                          <span className="block text-xs text-slate-400">
                            {shortDate(period.starts_on)} – {shortDate(period.ends_on)}
                            {period.approximate_boundary && ' ~'}
                          </span>
                        </td>
                        <td className="num px-3 py-2 text-right text-slate-600">
                          {/* Blank, not zero: a boundary the price history cannot
                              reach is unknown, and zero would read as a wipeout. */}
                          {period.opening_value === null
                            ? <span title="No price history that far back" className="text-slate-300">n/a</span>
                            : compactMoney(period.opening_value)}
                        </td>
                        <td className="num px-3 py-2 text-right text-slate-600">
                          {period.net_contribution === 0 ? '—' : compactMoney(period.net_contribution)}
                        </td>
                        <td className="num px-3 py-2 text-right text-slate-600">
                          {period.closing_value === null
                            ? <span className="text-slate-300">n/a</span>
                            : compactMoney(period.closing_value)}
                        </td>
                        <td
                          className={clsx(
                            'num px-3 py-2 text-right font-semibold',
                            (period.profit ?? 0) > 0 && 'text-gain',
                            (period.profit ?? 0) < 0 && 'text-loss',
                          )}
                        >
                          {period.profit === null ? (
                            <span className="text-slate-300">n/a</span>
                          ) : (
                            compactMoney(period.profit)
                          )}
                        </td>
                        <td className="num px-3 py-2 text-right">
                          {period.simple_return_percent === null ? (
                            <span className="text-slate-300">n/a</span>
                          ) : (
                            <>
                              <span
                                className={clsx(
                                  'font-medium',
                                  period.simple_return_percent > 0 ? 'text-gain' : 'text-loss',
                                )}
                              >
                                {signedPercent(period.simple_return_percent, 1)}
                              </span>
                              {/* The annualised figure is an extrapolation while
                                  the period is still running, so it is a footnote
                                  rather than the headline. */}
                              {period.return_percent !== null && !period.is_partial && (
                                <span className="block text-xs text-slate-400">
                                  {signedPercent(period.return_percent, 1)} p.a.
                                </span>
                              )}
                              {period.is_partial && (
                                <span className="block text-xs text-slate-400">
                                  {period.days_in_period}d so far
                                </span>
                              )}
                            </>
                          )}
                        </td>
                        <td
                          className={clsx(
                            'num px-3 py-2 text-right font-medium',
                            (period.value_vs_fixed_deposit ?? 0) > 0 && 'text-gain',
                            (period.value_vs_fixed_deposit ?? 0) < 0 && 'text-loss',
                          )}
                        >
                          {period.value_vs_fixed_deposit === null ? (
                            <span className="text-slate-300">n/a</span>
                          ) : (
                            <span title={`A ${period.fixed_deposit_rate}% deposit would have ended at ${money(period.fixed_deposit_value, 0)}`}>
                              {compactMoney(period.value_vs_fixed_deposit)}
                            </span>
                          )}
                        </td>
                        <td
                          className={clsx(
                            'num px-3 py-2 text-right',
                            period.net_realized > 0 && 'text-gain',
                            period.net_realized < 0 && 'text-loss',
                            period.net_realized === 0 && 'text-slate-400',
                          )}
                        >
                          {period.net_realized === 0 ? '—' : compactMoney(period.net_realized)}
                        </td>
                        <td className="num px-3 py-2 text-right text-slate-600">
                          {period.trades_closed === 0 ? '—' : period.trades_closed}
                        </td>
                        <td className="num px-3 py-2 text-right text-slate-600">
                          {period.win_rate === null ? '—' : `${period.win_rate}%`}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          {/* Per-scrip lifetime */}
          <Card title="By scrip" subtitle="Lifetime, realised and unrealised together.">
            <div className="-mx-4 overflow-x-auto sm:mx-0">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                    <th className="px-3 py-2">Scrip</th>
                    <th className="px-3 py-2 text-right">Held</th>
                    <th className="px-3 py-2 text-right">Avg cost</th>
                    <th className="px-3 py-2 text-right">Value</th>
                    <th className="px-3 py-2 text-right">Unrealised</th>
                    <th className="px-3 py-2 text-right">Realised</th>
                    <th className="px-3 py-2 text-right">Dividends</th>
                    <th className="px-3 py-2 text-right">Total</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {data.by_stock.map((row) => (
                    <tr key={row.symbol}>
                      <td className="px-3 py-2 font-medium text-slate-800">{row.symbol}</td>
                      <td className="num px-3 py-2 text-right text-slate-600">
                        {row.quantity === 0 ? '—' : row.quantity}
                      </td>
                      <td className="num px-3 py-2 text-right text-slate-600">
                        {row.average_cost === null ? '—' : money(row.average_cost, 0)}
                      </td>
                      <td className="num px-3 py-2 text-right text-slate-600">
                        {row.market_value === null ? '—' : compactMoney(row.market_value)}
                      </td>
                      <td
                        className={clsx(
                          'num px-3 py-2 text-right',
                          (row.unrealized_gain ?? 0) > 0 && 'text-gain',
                          (row.unrealized_gain ?? 0) < 0 && 'text-loss',
                        )}
                      >
                        {row.unrealized_gain === null ? '—' : compactMoney(row.unrealized_gain)}
                      </td>
                      <td
                        className={clsx(
                          'num px-3 py-2 text-right',
                          row.realized_gain > 0 && 'text-gain',
                          row.realized_gain < 0 && 'text-loss',
                          row.realized_gain === 0 && 'text-slate-400',
                        )}
                      >
                        {row.realized_gain === 0 ? '—' : compactMoney(row.realized_gain)}
                      </td>
                      <td className="num px-3 py-2 text-right text-slate-600">
                        {row.dividends === 0 ? '—' : compactMoney(row.dividends)}
                      </td>
                      <td
                        className={clsx(
                          'num px-3 py-2 text-right font-semibold',
                          row.total_gain > 0 && 'text-gain',
                          row.total_gain < 0 && 'text-loss',
                          row.total_gain === 0 && 'text-slate-400',
                        )}
                      >
                        {row.total_gain === 0 ? '—' : compactMoney(row.total_gain)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          {/* Closed trades */}
          {data.closed_trades.length > 0 && (
            <Card
              title="Closed trades"
              subtitle={`Most recent first${data.closed_trades.length === 250 ? ' — capped at 250' : ''}.`}
            >
              <div className="-mx-4 overflow-x-auto sm:mx-0">
                <table className="min-w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                      <th className="px-3 py-2">Scrip</th>
                      <th className="px-3 py-2">Opened</th>
                      <th className="px-3 py-2">Closed</th>
                      <th className="px-3 py-2 text-right">Days</th>
                      <th className="px-3 py-2 text-right">Units</th>
                      <th className="px-3 py-2 text-right">Cost</th>
                      <th className="px-3 py-2 text-right">Proceeds</th>
                      <th className="px-3 py-2 text-right">Gain</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {data.closed_trades.map((trade, index) => (
                      <tr key={`${trade.symbol}-${trade.closed_on}-${index}`}>
                        <td className="px-3 py-2 font-medium text-slate-800">{trade.symbol}</td>
                        <td className="px-3 py-2 text-slate-500">{shortDate(trade.opened_on)}</td>
                        <td className="px-3 py-2 text-slate-500">{shortDate(trade.closed_on)}</td>
                        <td className="num px-3 py-2 text-right text-slate-600">{trade.days_held}</td>
                        <td className="num px-3 py-2 text-right text-slate-600">{trade.quantity}</td>
                        <td className="num px-3 py-2 text-right text-slate-600">
                          {compactMoney(trade.cost_basis)}
                        </td>
                        <td className="num px-3 py-2 text-right text-slate-600">
                          {compactMoney(trade.proceeds)}
                        </td>
                        <td
                          className={clsx(
                            'num px-3 py-2 text-right font-medium',
                            trade.is_win ? 'text-gain' : 'text-loss',
                          )}
                        >
                          {compactMoney(trade.gain)}
                          {trade.gain_percent !== null && (
                            <span className="ml-1 text-xs font-normal opacity-70">
                              {signedPercent(trade.gain_percent, 1)}
                            </span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>
          )}
        </>
      )}
    </div>
  )
}

function Metric({
  label,
  value,
  tone,
  hint,
}: {
  /** A node, not just a string, so a label can carry an <Explain> tooltip. */
  label: ReactNode
  value: string
  tone?: 'gain' | 'loss'
  hint?: string
}) {
  return (
    <div title={hint}>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p
        className={clsx(
          'num mt-0.5 text-lg font-semibold',
          tone === 'gain' && 'text-gain',
          tone === 'loss' && 'text-loss',
          tone === undefined && 'text-slate-800',
        )}
      >
        {value}
      </p>
    </div>
  )
}

/*
 * "Did stock-picking add anything" — the market sibling of the deposit card
 * above.
 *
 * The coverage warning is the important part of this component, not a footnote.
 * An index that does not reach the start of the window can invert the verdict:
 * in a fictional example, 60% coverage suggests NPR 2,000.00 ahead, while
 * complete history places the portfolio NPR 1,500.00 behind.
 * So an incomplete comparison is labelled as provisional rather than shown as
 * though it were the answer.
 */
function MarketBenchmarkCard({
  report,
  isLoading,
}: {
  report: BenchmarkReport | undefined
  isLoading: boolean
}) {
  if (isLoading) {
    return (
      <Card title="Versus the market">
        <Spinner label="Comparing against the index…" />
      </Card>
    )
  }

  const market = report?.market
  const risk = report?.risk

  if (market === undefined) return null

  // No index data at all: say so plainly instead of rendering zeroes.
  if (!market.index_available || market.value_vs_index === null || market.index_value === null) {
    return (
      <Card title="Versus the market" subtitle="Did picking these scrips beat simply buying the index?">
        <p className="text-sm text-slate-600">
          {market.index_coverage_note ??
            'No index history has been collected yet, so this comparison cannot be computed.'}
        </p>
        <p className="mt-2 text-xs text-slate-400">
          This comparison will become available once enough index history has been collected.
        </p>
      </Card>
    )
  }

  const ahead = market.beat_index === true
  const provisional = !market.index_simulation_complete

  return (
    <Card
      title="Versus the market"
      subtitle={`If the same money had bought the ${market.index_name} on the same dates instead.`}
    >
      {provisional && (
        <p className="mb-4 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
          <strong>Provisional.</strong> {market.index_coverage_note} A gap early in the window can
          reverse this verdict, so treat it as incomplete rather than as the answer.
        </p>
      )}

      <div className="grid gap-4 sm:grid-cols-3">
        <div>
          <p className="text-xs uppercase tracking-wide text-slate-400">Your portfolio</p>
          <p className="num mt-1 text-2xl font-semibold text-slate-900">
            {compactMoney(market.portfolio_value ?? 0)}
          </p>
        </div>
        <div>
          <p className="text-xs uppercase tracking-wide text-slate-400">In the {market.index_name}</p>
          <p className="num mt-1 text-2xl font-semibold text-slate-600">
            {compactMoney(market.index_value)}
          </p>
        </div>
        <div>
          <p className="text-xs uppercase tracking-wide text-slate-400">Difference</p>
          <p className={clsx('num mt-1 text-2xl font-semibold', ahead ? 'text-gain' : 'text-loss')}>
            {compactMoney(market.value_vs_index)}
          </p>
        </div>
      </div>

      <p className="mt-4 text-sm text-slate-600">
        {ahead ? (
          <>
            You are <strong>{compactMoney(Math.abs(market.value_vs_index))} ahead</strong> of the
            index — the stock selection has added value over this period.
          </>
        ) : (
          <>
            You are <strong>{compactMoney(Math.abs(market.value_vs_index))} behind</strong> the
            index. Buying the market outright would have done better over this period.
          </>
        )}
      </p>

      {risk !== undefined && risk.beta !== null && (
        <div className="mt-4 grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-3">
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-400">Beta</p>
            <p className="num mt-1 font-semibold text-slate-900">{risk.beta}</p>
            <p className="mt-1 text-xs text-slate-400">
              {risk.beta > 1
                ? `Moves ${Math.round((risk.beta - 1) * 100)}% more than the market`
                : `Moves ${Math.round((1 - risk.beta) * 100)}% less than the market`}
            </p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-400">Alpha (annualised)</p>
            <p
              className={clsx(
                'num mt-1 font-semibold',
                (risk.alpha_percent_annualized ?? 0) >= 0 ? 'text-gain' : 'text-loss',
              )}
            >
              {risk.alpha_percent_annualized === null
                ? 'n/a'
                : signedPercent(risk.alpha_percent_annualized)}
            </p>
            <p className="mt-1 text-xs text-slate-400">Return beyond what this much risk explains</p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wide text-slate-400">Correlation</p>
            <p className="num mt-1 font-semibold text-slate-900">{risk.correlation ?? 'n/a'}</p>
            <p className="mt-1 text-xs text-slate-400">
              {risk.sample_size} {risk.interval} samples
            </p>
          </div>
        </div>
      )}

      {market.portfolio_unpriced_symbols.length > 0 && (
        <p className="mt-3 text-xs text-slate-400">
          Excluded for having no price: {market.portfolio_unpriced_symbols.join(', ')}. Counting them
          at cost would report a silent 0% on those holdings.
        </p>
      )}

      <p className="mt-2 text-xs text-slate-400">
        Both sides are charged the same way: money put in buys index units at that date's level,
        money taken out stops earning when you took it.
      </p>
    </Card>
  )
}

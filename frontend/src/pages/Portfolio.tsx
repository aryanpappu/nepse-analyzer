import { AiDataNotice } from '../components/AiDataNotice'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { invalidatePortfolioMoney } from '../lib/portfolioQueries'
import {
  changeColor,
  compactMoney,
  money,
  percent,
  quantity,
  shortDate,
  signedPercent,
} from '../lib/format'
import {
  Banner,
  Card,
  EmptyState,
  ErrorBox,
  Field,
  FundTag,
  Npr,
  RiskBadge,
  ScoreBar,
  SignalBadge,
  Spinner,
  StatTile,
} from '../components/ui'
import { PositionActions } from '../components/PositionActions'
import { Explain } from '../components/Explain'
import { CashLedgerCard } from '../components/CashLedgerCard'
import { CreateFirstPortfolio } from '../components/CreateFirstPortfolio'
import { SetupNeeded } from '../components/GettingStarted'
import { useProfile } from '../lib/profile'
import { useStepDone } from '../lib/onboarding'
import { CashPlacementsCard } from '../components/CashPlacementsCard'
import { NetWorthBreakdown } from '../components/NetWorthBreakdown'
import type {
  Holding,
  InvestorProfileValue,
  Portfolio,
  PortfolioAnalysis,
  PortfolioSummary,
  RecommendationRun,
} from '../lib/types'

// Distinct enough to tell apart, and stable per slice index.
const PIE_COLORS = [
  '#0f172a', '#2563eb', '#16a34a', '#d97706', '#7c3aed',
  '#db2777', '#0891b2', '#65a30d', '#dc2626', '#64748b',
]

interface PortfolioDetail {
  portfolio: Portfolio
  holdings: Holding[]
  latest_analysis: PortfolioAnalysis | null
}

export function PortfolioPage() {
  const queryClient = useQueryClient()
  const { profile, meta: profileMeta } = useProfile()
  const profileLabel = profileMeta?.label ?? profile.replace('_', ' ')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [showTradeForm, setShowTradeForm] = useState(false)
  const [showCashForm, setShowCashForm] = useState(false)

  const portfolios = useQuery({
    queryKey: ['portfolios'],
    queryFn: async () => (await api.get<{ data: Portfolio[] }>('/portfolios')).data.data,
  })

  const activeId = selectedId ?? portfolios.data?.[0]?.id ?? null
  // Whether anything has ever been recorded here, from the shared
  // getting-started query rather than a second round trip.
  const hasHoldings = useStepDone('holdings')

  const detail = useQuery({
    queryKey: ['portfolio', activeId],
    queryFn: async () =>
      (await api.get<{ data: PortfolioDetail }>(`/portfolios/${activeId}`)).data.data,
    enabled: activeId !== null,
  })

  const recommendations = useQuery({
    queryKey: ['recommendations', activeId, profile],
    queryFn: async () =>
      (await api.get<{ data: RecommendationRun | null }>(`/portfolios/${activeId}/recommendations`, {
        params: { profile },
      }))
        .data.data,
    enabled: activeId !== null,
  })

  function refresh() {
    if (activeId === null) return

    // A trade or a cash movement changes the summary, the year-by-year
    // performance report and the market benchmark alike — dropping only the
    // summary leaves the other two showing pre-correction numbers.
    invalidatePortfolioMoney(queryClient, activeId)
  }

  const analyze = useMutation({
    mutationFn: async () =>
      (await api.post<{ data: PortfolioAnalysis }>(`/portfolios/${activeId}/analyze`)).data.data,
    onSuccess: () => {
      setNotice('Portfolio analysis updated.')
      refresh()
    },
    onError: (error) => setNotice(errorMessage(error)),
  })

  const recommend = useMutation({
    mutationFn: async ({ portfolioId, requestedProfile }: { portfolioId: number; requestedProfile: InvestorProfileValue }) =>
      (await api.post<{ data: RecommendationRun }>(`/portfolios/${portfolioId}/recommend`, {
        profile: requestedProfile,
      })).data.data,
    onSuccess: (run, variables) => {
      setNotice(`Recommendations generated for the ${run.investor_profile.replace('_', ' ')} profile.`)
      void queryClient.invalidateQueries({ queryKey: ['recommendations', variables.portfolioId, variables.requestedProfile] })
    },
    onError: (error) => setNotice(errorMessage(error)),
  })

  if (portfolios.isLoading) return <Spinner label="Loading your portfolios…" />

  if (portfolios.isError) {
    return <ErrorBox message={errorMessage(portfolios.error)} onRetry={() => portfolios.refetch()} />
  }

  if ((portfolios.data?.length ?? 0) === 0) {
    return (
      <EmptyState
        title="Create your first portfolio"
        hint="A portfolio holds your positions, trades and cash. Make one here, then import your MeroShare exports to fill it."
        action={
          <div className="mt-4 w-full">
            <CreateFirstPortfolio onCreated={(portfolio) => setSelectedId(portfolio.id)} />
          </div>
        }
      />
    )
  }

  const data = detail.data
  const summary = data?.portfolio.summary
  const holdings = data?.holdings ?? []
  const analysis = data?.latest_analysis ?? null

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Portfolio</h1>
          <p className="text-sm text-slate-500">
            {data?.portfolio.name ?? '—'} · {holdings.length} open positions
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {(portfolios.data?.length ?? 0) > 1 && (
            <select
              className="input w-auto"
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
          <Link to="/import" className="btn-secondary">
            Import CSV
          </Link>
          <button type="button" className="btn-secondary" onClick={() => setShowCashForm((v) => !v)}>
            Manage cash
          </button>
          <button type="button" className="btn-secondary" onClick={() => setShowTradeForm((v) => !v)}>
            Add trade
          </button>
        </div>
      </div>

      {notice !== null && <Banner tone="info">{notice}</Banner>}

      {detail.isLoading ? (
        <Spinner />
      ) : detail.isError ? (
        <ErrorBox message={errorMessage(detail.error)} onRetry={() => detail.refetch()} />
      ) : summary === undefined || data === undefined ? null : (
        <>
          {/*
           * Five tiles reading NPR 0 and a net-worth breakdown of nothing are
           * not a report — they are the shape a report will take, shown to
           * someone who has not yet given it anything to report on. Registration
           * creates the portfolio, so this is the first screen a new account
           * actually lands on with its own money nav.
           */}
          {hasHoldings === false ? (
            <SetupNeeded
              title="This portfolio is empty"
              because="Everything on this page is derived from your transaction ledger, so until something is in it every figure here is zero by arithmetic rather than by result."
            />
          ) : (
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <StatTile label="Invested" value={`NPR ${compactMoney(summary.total_invested)}`} />
            <StatTile label="Market value" value={`NPR ${compactMoney(summary.total_value)}`} />
            <StatTile
              label={<Explain term="unrealised">Unrealised P/L</Explain>}
              value={`NPR ${compactMoney(summary.unrealized_gain)}`}
              delta={summary.unrealized_gain_percent}
              tone={summary.unrealized_gain >= 0 ? 'gain' : 'loss'}
            />
            <StatTile
              label={<Explain term="realised">Realised P/L</Explain>}
              value={`NPR ${compactMoney(summary.realized_gain)}`}
              tone={summary.realized_gain >= 0 ? 'gain' : 'loss'}
            />
            <StatTile
              label={<Explain term="net-worth">Net worth</Explain>}
              value={`NPR ${compactMoney(summary.net_worth)}`}
              hint={`incl. NPR ${compactMoney(summary.investable_cash)} cash`}
            />
          </div>
          )}

          {summary.unpriced_symbols.length > 0 && (
            <Banner tone="warning">
              No current price for {summary.unpriced_symbols.join(', ')} — these are excluded from
              the valuation and return figures rather than counted at cost.
            </Banner>
          )}

          {/* What net worth is actually made of, and which deposits are — and
              are not — inside it. Skipped while the ledger is empty: a
              breakdown of zero into three zeroes explains nothing. */}
          {hasHoldings !== false && <NetWorthBreakdown summary={summary} />}

          {showCashForm && activeId !== null && (
            <CashForm
              portfolioId={activeId}
              cashInHand={summary.cash_in_hand}
              bankBalance={summary.bank_balance}
              onDone={(message) => {
                setNotice(message)
                setShowCashForm(false)
                refresh()
              }}
            />
          )}

          {showTradeForm && activeId !== null && (
            <TradeForm
              portfolioId={activeId}
              onDone={(message) => {
                setNotice(message)
                setShowTradeForm(false)
                refresh()
              }}
            />
          )}

          <div className="grid gap-6 lg:grid-cols-3">
            <HoldingsTable holdings={holdings} className="lg:col-span-2" />
            <AllocationCard summary={summary} />
          </div>

          {/* Every cash movement, amendable in place or voidable with a
              reason — the fix for a balance that could previously only be
              corrected by posting a second, offsetting entry. */}
          {activeId !== null && <CashLedgerCard portfolioId={activeId} />}

          {/* Fixed deposits, with the settle-cash rule stated rather than
              implied. */}
          {activeId !== null && (
            <CashPlacementsCard portfolioId={activeId} valuation={summary.placements} />
          )}

          {/* Add / hold / trim / exit for every open position, under the
              profile selected in the header. */}
          {activeId !== null && <PositionActions portfolioId={activeId} />}

          <AiDataNotice />
          <AnalysisCard
            analysis={analysis}
            busy={analyze.isPending}
            onRun={() => analyze.mutate()}
          />

          <RecommendationsCard
            run={recommendations.data ?? null}
            profileLabel={profileLabel}
            loading={recommendations.isLoading}
            error={recommendations.isError ? errorMessage(recommendations.error) : null}
            onRetry={() => { void recommendations.refetch() }}
            investableCash={summary.investable_cash}
            busy={recommend.isPending}
            onRun={() => {
              if (activeId !== null) recommend.mutate({ portfolioId: activeId, requestedProfile: profile })
            }}
          />
        </>
      )}
    </div>
  )
}

function HoldingsTable({ holdings, className }: { holdings: Holding[]; className?: string }) {
  const hasFunds = holdings.some((holding) => holding.is_fund)

  return (
    <Card title="Holdings" className={clsx('overflow-hidden', className)}>
      {holdings.length === 0 ? (
        <EmptyState title="No open positions" hint="Import your MeroShare CSVs or add a trade." />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[46rem]">
            <thead className="border-b border-slate-200 bg-slate-50">
              <tr>
                <th className="th">Scrip</th>
                <th className="th text-right">Qty</th>
                <th className="th text-right">Avg cost</th>
                <th className="th text-right"><Explain term="ltp">LTP</Explain></th>
                <th className="th text-right">Value</th>
                <th className="th text-right">P/L</th>
                <th className="th text-right">Wt.</th>
                <th className="th">Signal</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {holdings.map((holding) => (
                <tr key={holding.id} className="hover:bg-slate-50">
                  <td className="td">
                    <Link to={`/stocks/${holding.symbol}`} className="flex items-center gap-1.5">
                      <span>
                        <span className="font-semibold text-slate-800">{holding.symbol}</span>
                        <span className="block max-w-[13rem] truncate text-xs text-slate-500">
                          {holding.sector ?? holding.name}
                        </span>
                      </span>
                      {holding.is_fund && <FundTag />}
                    </Link>
                  </td>
                  <td className="td num text-right">{quantity(holding.quantity)}</td>
                  <td className="td num text-right">{money(holding.average_cost)}</td>
                  <td className="td num text-right">
                    {money(holding.current_price)}
                    {holding.is_fund && holding.nav !== null && (
                      <span
                        className="block text-[10px] text-violet-600"
                        title={`NAV as of ${shortDate(holding.nav_date)}`}
                      >
                        NAV {money(holding.nav, 2)}
                      </span>
                    )}
                  </td>
                  <td className="td num text-right font-medium">{money(holding.current_value)}</td>
                  <td className="td text-right">
                    <span className={clsx('num block text-sm', changeColor(holding.unrealized_gain))}>
                      {holding.unrealized_gain === null
                        ? '—'
                        : `${holding.unrealized_gain >= 0 ? '+' : ''}${money(holding.unrealized_gain)}`}
                    </span>
                    <span className={clsx('num text-xs', changeColor(holding.unrealized_gain_percent))}>
                      {signedPercent(holding.unrealized_gain_percent)}
                    </span>
                  </td>
                  <td className="td num text-right text-slate-500">
                    {percent(holding.weight_percent, 1)}
                  </td>
                  <td className="td">
                    <SignalBadge signal={holding.signal} confidence={holding.signal_confidence} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {hasFunds && (
        <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-500">
          Fund rows show NAV beneath the market price. A closed-end fund trading below NAV is normal
          for NEPSE — judge the discount against that fund's own history, not against zero.
        </p>
      )}
    </Card>
  )
}

function AllocationCard({ summary }: { summary: PortfolioSummary }) {
  const data = useMemo(
    () =>
      summary.sector_allocation.map((sector) => ({
        name: sector.sector,
        value: sector.value,
        percent: sector.percent,
      })),
    [summary.sector_allocation],
  )

  return (
    <Card title="Sector allocation">
      {data.length === 0 ? (
        <p className="py-6 text-center text-sm text-slate-500">Nothing allocated yet.</p>
      ) : (
        <>
          <ResponsiveContainer width="100%" height={200}>
            <PieChart>
              <Pie
                data={data}
                dataKey="value"
                nameKey="name"
                innerRadius={50}
                outerRadius={80}
                paddingAngle={2}
              >
                {data.map((entry, index) => (
                  <Cell key={entry.name} fill={PIE_COLORS[index % PIE_COLORS.length]} />
                ))}
              </Pie>
              <Tooltip
                formatter={(value: number, name: string) => [`NPR ${money(value)}`, name]}
                contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e2e8f0' }}
              />
            </PieChart>
          </ResponsiveContainer>

          <ul className="mt-3 space-y-1.5">
            {data.map((entry, index) => (
              <li key={entry.name} className="flex items-center gap-2 text-sm">
                <span
                  className="h-2.5 w-2.5 shrink-0 rounded-sm"
                  style={{ backgroundColor: PIE_COLORS[index % PIE_COLORS.length] }}
                />
                <span className="flex-1 truncate text-slate-700">{entry.name}</span>
                <span className="num font-medium text-slate-900">{percent(entry.percent, 1)}</span>
              </li>
            ))}
          </ul>
        </>
      )}

      <dl className="mt-4 border-t border-slate-100 pt-3">
        <Field label="Cash in hand">
          <Npr value={summary.cash_in_hand} />
        </Field>
        <Field label="Bank balance">
          <Npr value={summary.bank_balance} />
        </Field>
        <Field label="Investable">
          <Npr value={summary.investable_cash} />
        </Field>
      </dl>
    </Card>
  )
}

function AnalysisCard({
  analysis,
  busy,
  onRun,
}: {
  analysis: PortfolioAnalysis | null
  busy: boolean
  onRun: () => void
}) {
  return (
    <Card
      title="AI portfolio analysis"
      subtitle={analysis === null ? undefined : `${shortDate(analysis.analysis_date)} · ${analysis.model}`}
      action={
        <button type="button" className="btn-primary" onClick={onRun} disabled={busy}>
          {busy ? 'Analysing…' : analysis === null ? 'Run analysis' : 'Re-run'}
        </button>
      }
    >
      {analysis === null ? (
        <p className="py-6 text-center text-sm text-slate-500">
          Run an analysis to get a health score, concentration risks and a rebalancing plan.
        </p>
      ) : (
        <div className="grid gap-6 lg:grid-cols-3">
          <div className="space-y-4">
            <div className="flex items-baseline gap-3">
              <div>
                <p className="text-xs uppercase text-slate-500">Health score</p>
                <p
                  className={clsx(
                    'text-4xl font-bold',
                    analysis.health_score >= 75
                      ? 'text-gain'
                      : analysis.health_score >= 50
                        ? 'text-amber-600'
                        : 'text-loss',
                  )}
                >
                  {analysis.health_score}
                  <span className="text-lg text-slate-400">/100</span>
                </p>
              </div>
              <div className="ml-auto text-right">
                <p className="text-xs uppercase text-slate-500">Diversification</p>
                <p className="text-2xl font-bold text-slate-800">
                  {analysis.diversification_grade ?? '—'}
                </p>
                <RiskBadge risk={analysis.risk_level} />
              </div>
            </div>
            <ScoreBar score={analysis.health_score} label="Overall health" />
          </div>

          <div className="lg:col-span-2 space-y-4">
            {analysis.summary !== null && (
              <p className="text-sm leading-relaxed text-slate-700">{analysis.summary}</p>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
              {analysis.strengths !== null && analysis.strengths.length > 0 && (
                <div>
                  <p className="mb-1 text-xs font-semibold uppercase text-emerald-700">Strengths</p>
                  <ul className="list-inside list-disc space-y-1 text-sm text-slate-700">
                    {analysis.strengths.map((item) => (
                      <li key={item}>{item}</li>
                    ))}
                  </ul>
                </div>
              )}
              {analysis.weaknesses !== null && analysis.weaknesses.length > 0 && (
                <div>
                  <p className="mb-1 text-xs font-semibold uppercase text-red-700">Weaknesses</p>
                  <ul className="list-inside list-disc space-y-1 text-sm text-slate-700">
                    {analysis.weaknesses.map((item) => (
                      <li key={item}>{item}</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>

            {analysis.concentration_risks !== null && analysis.concentration_risks.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase text-slate-500">
                  Concentration risks
                </p>
                <div className="space-y-2">
                  {analysis.concentration_risks.map((risk) => (
                    <div key={risk.label} className="rounded-lg bg-slate-50 p-3 text-sm">
                      <div className="flex items-center gap-2">
                        <span className="font-semibold text-slate-800">{risk.label}</span>
                        <RiskBadge risk={risk.severity} />
                        {risk.weight_percent !== undefined && (
                          <span className="num ml-auto text-xs text-slate-500">
                            {percent(risk.weight_percent, 1)}
                          </span>
                        )}
                      </div>
                      <p className="mt-1 text-slate-600">{risk.explanation}</p>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {analysis.rebalancing_actions !== null && analysis.rebalancing_actions.length > 0 && (
              <div>
                <p className="mb-2 text-xs font-semibold uppercase text-slate-500">
                  Rebalancing plan
                </p>
                <div className="space-y-2">
                  {analysis.rebalancing_actions.map((action, index) => (
                    <div
                      key={`${action.symbol}-${index}`}
                      className="flex gap-3 rounded-lg border border-slate-200 p-3 text-sm"
                    >
                      <span
                        className={clsx(
                          'badge h-fit',
                          action.action === 'buy' || action.action === 'add'
                            ? 'bg-gain-light text-gain-dark'
                            : action.action === 'hold'
                              ? 'bg-slate-100 text-slate-600'
                              : 'bg-loss-light text-loss-dark',
                        )}
                      >
                        {action.action.toUpperCase()}
                      </span>
                      <div className="flex-1">
                        <p className="font-semibold text-slate-800">
                          <Link to={`/stocks/${action.symbol}`} className="hover:underline">
                            {action.symbol}
                          </Link>
                          {action.quantity !== undefined && (
                            <span className="num ml-2 font-normal text-slate-500">
                              {quantity(action.quantity)} shares
                            </span>
                          )}
                          {action.estimated_amount !== undefined && (
                            <span className="num ml-2 font-normal text-slate-500">
                              ≈ NPR {compactMoney(action.estimated_amount)}
                            </span>
                          )}
                        </p>
                        <p className="text-slate-600">{action.reason}</p>
                      </div>
                      <span className="badge h-fit bg-slate-100 text-slate-500">
                        {action.priority}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </Card>
  )
}

function RecommendationsCard({
  run,
  profileLabel,
  loading,
  error,
  onRetry,
  investableCash,
  busy,
  onRun,
}: {
  run: RecommendationRun | null
  profileLabel: string
  loading: boolean
  error: string | null
  onRetry: () => void
  investableCash: number
  busy: boolean
  onRun: () => void
}) {
  return (
    <Card
      title="What to buy with idle cash"
      subtitle={
        run === null
          ? `${profileLabel} · NPR ${money(investableCash)} available`
          : `${profileLabel} · ${shortDate(run.analysis_date)} · ${run.model}`
      }
      action={
        <button type="button" className="btn-primary" onClick={onRun} disabled={busy || loading}>
          {busy ? 'Thinking…' : run === null ? 'Get recommendations' : 'Refresh'}
        </button>
      }
    >
      {loading ? <Spinner label={`Loading ${profileLabel} recommendations…`} /> : error !== null ? (
        <ErrorBox message={error} onRetry={onRetry} />
      ) : run === null ? (
        <p className="py-6 text-center text-sm text-slate-500">
          {investableCash < 1000
            ? 'Add cash or a bank balance first — there is nothing to allocate.'
            : `Ask for ideas under the ${profileLabel} lens, sized to your available cash and current holdings.`}
        </p>
      ) : (
        <div className="space-y-4">
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg bg-slate-50 p-3">
              <p className="text-xs text-slate-500">Investable</p>
              <p className="num font-semibold">{money(run.investable_cash)}</p>
            </div>
            <div className="rounded-lg bg-gain-light p-3">
              <p className="text-xs text-gain-dark">Allocated</p>
              <p className="num font-semibold text-gain-dark">{money(run.allocated_amount)}</p>
            </div>
            <div className="rounded-lg bg-amber-50 p-3">
              <p className="text-xs text-amber-800">Held back</p>
              <p className="num font-semibold text-amber-800">{money(run.reserve_amount)}</p>
            </div>
          </div>

          {run.strategy_summary !== null && (
            <p className="text-sm leading-relaxed text-slate-700">{run.strategy_summary}</p>
          )}

          {run.recommendations.length === 0 ? (
            <Banner tone="info">
              Nothing was worth buying at current valuations — the full amount stays in cash.
            </Banner>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[46rem]">
                <thead className="border-b border-slate-200 bg-slate-50">
                  <tr>
                    <th className="th">#</th>
                    <th className="th">Scrip</th>
                    <th className="th text-right">Qty</th>
                    <th className="th text-right">Price</th>
                    <th className="th text-right">Cost</th>
                    <th className="th text-right">Stop / Target</th>
                    <th className="th text-right">Conviction</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {run.recommendations.map((item) => (
                    <tr key={item.symbol} className="align-top hover:bg-slate-50">
                      <td className="td text-slate-400">{item.rank}</td>
                      <td className="td">
                        <Link to={`/stocks/${item.symbol}`} className="font-semibold hover:underline">
                          {item.symbol}
                        </Link>
                        <span className="block max-w-[16rem] text-xs text-slate-500">
                          {item.sector ?? item.name}
                        </span>
                        {item.reason !== null && (
                          <span className="mt-1 block max-w-[22rem] whitespace-normal text-xs text-slate-600">
                            {item.reason}
                          </span>
                        )}
                      </td>
                      <td className="td num text-right">{quantity(item.suggested_quantity)}</td>
                      <td className="td num text-right">{money(item.suggested_price)}</td>
                      <td className="td num text-right font-medium">
                        {money(item.estimated_cost)}
                        <span className="block text-xs text-slate-400">
                          {percent(item.allocation_percent, 1)}
                        </span>
                      </td>
                      <td className="td num text-right text-xs">
                        <span className="block text-loss">{money(item.stop_loss)}</span>
                        <span className="block text-gain">{money(item.target_price)}</span>
                      </td>
                      <td className="td text-right">
                        <span className="num font-semibold">{item.conviction ?? '—'}%</span>
                        <span className="block">
                          <RiskBadge risk={item.risk_level} />
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {run.avoid != null && run.avoid.length > 0 && (
            <div>
              <p className="mb-1 text-xs font-semibold uppercase text-slate-500">
                Deliberately passed over
              </p>
              <ul className="space-y-1 text-sm text-slate-600">
                {run.avoid.map((item) => (
                  <li key={item.symbol}>
                    <span className="font-medium text-slate-800">{item.symbol}</span> — {item.reason}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>
      )}
    </Card>
  )
}

function TradeForm({
  portfolioId,
  onDone,
}: {
  portfolioId: number
  onDone: (message: string) => void
}) {
  const [symbol, setSymbol] = useState('')
  const [type, setType] = useState('buy')
  const [qty, setQty] = useState('')
  const [price, setPrice] = useState('')
  const [commission, setCommission] = useState('')
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10))
  const [settleCash, setSettleCash] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ message: string }>(`/portfolios/${portfolioId}/transactions`, {
          symbol: symbol.toUpperCase(),
          type,
          quantity: Number(qty),
          price: Number(price === '' ? 0 : price),
          commission: Number(commission === '' ? 0 : commission),
          transacted_on: date,
          settle_cash: settleCash,
          cash_account: 'bank',
        })
      ).data,
    onSuccess: (data) => onDone(data.message),
    onError: (caught) => setError(errorMessage(caught)),
  })

  return (
    <Card title="Record a trade">
      <form
        onSubmit={(event) => {
          event.preventDefault()
          setError(null)
          mutation.mutate()
        }}
        className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6"
      >
        <div>
          <label className="label" htmlFor="tf-symbol">Symbol</label>
          <input
            id="tf-symbol"
            className="input uppercase"
            value={symbol}
            onChange={(event) => setSymbol(event.target.value)}
            required
          />
        </div>
        <div>
          <label className="label" htmlFor="tf-type">Type</label>
          <select id="tf-type" className="input" value={type} onChange={(e) => setType(e.target.value)}>
            <option value="buy">Buy</option>
            <option value="sell">Sell</option>
            <option value="bonus">Bonus</option>
            <option value="dividend">Dividend</option>
            <option value="rights">Rights</option>
          </select>
        </div>
        <div>
          <label className="label" htmlFor="tf-qty">Quantity</label>
          <input
            id="tf-qty"
            type="number"
            step="0.01"
            min="0.01"
            className="input"
            value={qty}
            onChange={(event) => setQty(event.target.value)}
            required
          />
        </div>
        <div>
          <label className="label" htmlFor="tf-price">Price</label>
          <input
            id="tf-price"
            type="number"
            step="0.0001"
            min="0"
            className="input"
            value={price}
            onChange={(event) => setPrice(event.target.value)}
          />
        </div>
        <div>
          <label className="label" htmlFor="tf-comm">Commission</label>
          <input
            id="tf-comm"
            type="number"
            step="0.01"
            min="0"
            className="input"
            value={commission}
            onChange={(event) => setCommission(event.target.value)}
          />
        </div>
        <div>
          <label className="label" htmlFor="tf-date">Date</label>
          <input
            id="tf-date"
            type="date"
            className="input"
            value={date}
            max={new Date().toISOString().slice(0, 10)}
            onChange={(event) => setDate(event.target.value)}
            required
          />
        </div>

        <label className="flex items-center gap-2 text-sm text-slate-600 sm:col-span-3 lg:col-span-4">
          <input
            type="checkbox"
            checked={settleCash}
            onChange={(event) => setSettleCash(event.target.checked)}
          />
          Also move the cash from my bank balance
        </label>

        <div className="sm:col-span-3 lg:col-span-2 flex items-end">
          <button type="submit" className="btn-primary w-full" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Record trade'}
          </button>
        </div>

        {error !== null && (
          <div className="sm:col-span-3 lg:col-span-6">
            <ErrorBox message={error} />
          </div>
        )}
      </form>
    </Card>
  )
}

function CashForm({
  portfolioId,
  cashInHand,
  bankBalance,
  onDone,
}: {
  portfolioId: number
  cashInHand: number
  bankBalance: number
  onDone: (message: string) => void
}) {
  const [action, setAction] = useState('deposit')
  const [account, setAccount] = useState('bank')
  const [amount, setAmount] = useState('')
  const [fromAccount, setFromAccount] = useState('bank')
  const [toAccount, setToAccount] = useState('cash')
  const [error, setError] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ message: string }>(`/portfolios/${portfolioId}/cash`, {
          action,
          account: action === 'transfer' ? undefined : account,
          amount: Number(amount),
          from_account: action === 'transfer' ? fromAccount : undefined,
          to_account: action === 'transfer' ? toAccount : undefined,
        })
      ).data,
    onSuccess: (data) => onDone(data.message),
    onError: (caught) => setError(errorMessage(caught)),
  })

  return (
    <Card
      title="Cash and bank"
      subtitle={`Cash in hand NPR ${money(cashInHand)} · Bank NPR ${money(bankBalance)}`}
    >
      <form
        onSubmit={(event) => {
          event.preventDefault()
          setError(null)
          mutation.mutate()
        }}
        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
      >
        <div>
          <label className="label" htmlFor="cf-action">Action</label>
          <select
            id="cf-action"
            className="input"
            value={action}
            onChange={(event) => setAction(event.target.value)}
          >
            <option value="deposit">Deposit</option>
            <option value="withdraw">Withdraw</option>
            <option value="transfer">Transfer</option>
            <option value="set">Set balance to</option>
          </select>
        </div>

        {action === 'transfer' ? (
          <>
            <div>
              <label className="label" htmlFor="cf-from">From</label>
              <select id="cf-from" className="input" value={fromAccount} onChange={(e) => setFromAccount(e.target.value)}>
                <option value="bank">Bank</option>
                <option value="cash">Cash in hand</option>
              </select>
            </div>
            <div>
              <label className="label" htmlFor="cf-to">To</label>
              <select id="cf-to" className="input" value={toAccount} onChange={(e) => setToAccount(e.target.value)}>
                <option value="cash">Cash in hand</option>
                <option value="bank">Bank</option>
              </select>
            </div>
          </>
        ) : (
          <div>
            <label className="label" htmlFor="cf-account">Account</label>
            <select
              id="cf-account"
              className="input"
              value={account}
              onChange={(event) => setAccount(event.target.value)}
            >
              <option value="bank">Bank</option>
              <option value="cash">Cash in hand</option>
            </select>
          </div>
        )}

        <div>
          <label className="label" htmlFor="cf-amount">Amount (NPR)</label>
          <input
            id="cf-amount"
            type="number"
            step="0.01"
            min="0.01"
            className="input"
            value={amount}
            onChange={(event) => setAmount(event.target.value)}
            required
          />
        </div>

        <div className="flex items-end">
          <button type="submit" className="btn-primary w-full" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : 'Apply'}
          </button>
        </div>

        {error !== null && (
          <div className="sm:col-span-2 lg:col-span-5">
            <ErrorBox message={error} />
          </div>
        )}
      </form>
    </Card>
  )
}

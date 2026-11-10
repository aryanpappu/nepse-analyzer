import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { useAuth } from '../lib/auth'
import {
  compactMoney,
  money,
  percent,
  scoreColor,
  shortDate,
  signedPercent,
  titleCase,
  changeColor,
} from '../lib/format'
import {
  Banner,
  Card,
  ChangePill,
  ErrorBox,
  Field,
  FundTag,
  Npr,
  RiskBadge,
  ScoreBar,
  SignalBadge,
  Spinner,
} from '../components/ui'
import {
  MacdChart,
  PriceChart,
  RsiChart,
  VolumeChart,
  zoneLevelsOutOfView,
  type ChartZone,
} from '../components/PriceChart'
import { PlaybookCard, VerdictReasons } from '../components/PlaybookCard'
import { Explain } from '../components/Explain'
import { useProfile } from '../lib/profile'
import type {
  PlaybookVerdict,
  PricePoint,
  StockDetail as StockDetailType,
  StockSignal,
} from '../lib/types'

const RANGES = [
  { days: 30, label: '1M' },
  { days: 90, label: '3M' },
  { days: 180, label: '6M' },
  { days: 365, label: '1Y' },
  { days: 1825, label: 'Max' },
]

export function StockDetail() {
  const { symbol = '' } = useParams()
  const { user } = useAuth()
  const queryClient = useQueryClient()

  const { profile: globalProfile } = useProfile()

  const [days, setDays] = useState(90)
  const [showMa, setShowMa] = useState(true)
  const [showBollinger, setShowBollinger] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  // null means "follow the global lens"; picking a card pins one profile so the
  // three plans can be compared without changing the whole app's setting.
  const [pinnedProfile, setPinnedProfile] = useState<string | null>(null)
  const [showZones, setShowZones] = useState(true)

  const detail = useQuery({
    queryKey: ['stock', symbol],
    queryFn: async () =>
      (await api.get<{ data: StockDetailType }>(`/stocks/${symbol}`)).data.data,
  })

  const history = useQuery({
    queryKey: ['stock-history', symbol, days],
    queryFn: async () => {
      const response = await api.get<{
        data: { symbol: string; days: number; count: number; series: PricePoint[] }
      }>(`/stocks/${symbol}/history`, { params: { days, with_indicators: 1 } })

      return response.data.data
    },
  })

  // Rule-based, so this costs nothing to fetch and needs no sign-in.
  const playbook = useQuery({
    queryKey: ['stock-playbook', symbol],
    queryFn: async () =>
      (
        await api.get<{ data: { symbol: string; last_price: number | null; profiles: PlaybookVerdict[] } }>(
          `/stocks/${symbol}/playbook`,
        )
      ).data.data,
  })

  const signals = useQuery({
    queryKey: ['stock-signals', symbol],
    queryFn: async () =>
      (await api.get<{ data: StockSignal[] }>(`/stocks/${symbol}/signals`, { params: { limit: 10 } }))
        .data.data,
  })

  // Declared before the mutation that closes over it: the early returns below
  // would otherwise leave it in the temporal dead zone on some render paths.
  const activeProfile = pinnedProfile ?? globalProfile

  const analyze = useMutation({
    // Analyse for the lens the user is looking through, not a generic one — the
    // prompt reasons to that horizon and the signal is stored against it.
    mutationFn: async () =>
      (await api.post(`/stocks/${symbol}/analyze`, { profile: activeProfile })).data,
    onSuccess: (data: { message?: string }) => {
      setNotice(data.message ?? 'Analysis queued.')
      // The job runs on the queue, so refresh shortly after rather than now.
      setTimeout(() => {
        void queryClient.invalidateQueries({ queryKey: ['stock', symbol] })
        void queryClient.invalidateQueries({ queryKey: ['stock-signals', symbol] })
      }, 4000)
    },
    onError: (error) => setNotice(errorMessage(error)),
  })

  if (detail.isLoading) return <Spinner label={`Loading ${symbol}…`} />

  if (detail.isError) {
    return <ErrorBox message={errorMessage(detail.error)} onRetry={() => detail.refetch()} />
  }

  const data = detail.data
  if (data === undefined) return null

  const { stock, indicators, fundamentals, fundamental_analysis: fundamental } = data
  const isFund = stock.instrument_type === 'mutual_fund'
  const latestSignal = signals.data?.[0] ?? null
  const series = history.data?.series ?? []

  const verdicts = playbook.data?.profiles ?? []
  const activeVerdict = verdicts.find((item) => item.profile === activeProfile) ?? null

  const zone: ChartZone | null =
    showZones && activeVerdict !== null && activeVerdict.source !== 'unavailable'
      ? {
          entryLow: activeVerdict.entry_low ?? null,
          entryHigh: activeVerdict.entry_high ?? null,
          stopLoss: activeVerdict.stop_loss ?? null,
          target1: activeVerdict.target_1 ?? null,
          target2: activeVerdict.target_2 ?? null,
          label: activeVerdict.profile_label,
        }
      : null

  const clippedLevels = zoneLevelsOutOfView(series, zone)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-2xl font-semibold tracking-tight">{stock.symbol}</h1>
            {isFund && <FundTag />}
          </div>
          <p className="text-slate-600">{stock.name}</p>
          <p className="mt-1 text-xs text-slate-500">
            {stock.sector ?? 'Unclassified'} · {titleCase(stock.instrument_type)} · Last traded{' '}
            {shortDate(stock.last_traded_on)}
          </p>
        </div>

        <div className="text-right">
          <p className="num text-3xl font-semibold">{money(stock.last_price)}</p>
          <div className="mt-1 flex items-center justify-end gap-2">
            <span className={clsx('num text-sm font-medium', changeColor(stock.change_amount))}>
              {stock.change_amount === null
                ? '—'
                : `${stock.change_amount >= 0 ? '+' : ''}${money(stock.change_amount)}`}
            </span>
            <ChangePill value={stock.change_percent} />
          </div>
          {user?.is_admin === true && (
            <button
              type="button"
              className="btn-secondary mt-3"
              onClick={() => analyze.mutate()}
              disabled={analyze.isPending}
            >
              {analyze.isPending ? 'Queueing…' : 'Re-run AI analysis'}
            </button>
          )}
        </div>
      </div>

      {notice !== null && <Banner tone="info">{notice}</Banner>}

      {isFund && (
        <Banner tone="info">
          This is a closed-end mutual fund. Its signal is derived from NAV and the premium/discount
          to NAV, not from RSI or MACD — momentum indicators are noise on an instrument that trades
          in a few paisa band.
        </Banner>
      )}

      {/* Profile playbook. Rule-based and free, so it renders for every visitor
          — signed in or not — and is the first thing on the page: what should
          someone like me do about this scrip, at today's price. */}
      <Card
        title="Your playbook"
        subtitle="The same scrip, judged three ways. Pick one to draw its zones on the chart."
      >
        {playbook.isLoading ? (
          <Spinner />
        ) : playbook.isError ? (
          <ErrorBox message={errorMessage(playbook.error)} onRetry={() => playbook.refetch()} />
        ) : (
          <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-3">
              {verdicts.map((verdict) => (
                <PlaybookCard
                  key={verdict.profile}
                  verdict={verdict}
                  active={verdict.profile === activeProfile}
                  onSelect={() =>
                    setPinnedProfile(verdict.profile === activeProfile ? null : verdict.profile)
                  }
                />
              ))}
            </div>

            {activeVerdict !== null && (
              <div className="rounded-xl bg-slate-50 p-4">
                <p className="text-sm text-slate-700">{activeVerdict.summary}</p>

                <div className="mt-4">
                  <VerdictReasons verdict={activeVerdict} />
                </div>

                {activeVerdict.source === 'stored' && activeVerdict.analysis_date !== undefined && (
                  <p className="mt-3 text-xs text-slate-400">
                    Scored on {shortDate(activeVerdict.analysis_date)} — not enough data to
                    re-score at today's close.
                  </p>
                )}
              </div>
            )}
          </div>
        )}
      </Card>

      {/* Charts */}
      <Card
        title="Price history"
        subtitle={
          history.data === undefined
            ? undefined
            : `${history.data.count} sessions`
        }
        action={
          <div className="flex flex-wrap items-center gap-1">
            {RANGES.map((range) => (
              <button
                key={range.days}
                type="button"
                onClick={() => setDays(range.days)}
                className={clsx(
                  'rounded-md px-2 py-1 text-xs font-medium',
                  days === range.days
                    ? 'bg-slate-900 text-white'
                    : 'text-slate-500 hover:bg-slate-100',
                )}
              >
                {range.label}
              </button>
            ))}
          </div>
        }
      >
        {history.isLoading ? (
          <Spinner />
        ) : history.isError ? (
          <ErrorBox message={errorMessage(history.error)} onRetry={() => history.refetch()} />
        ) : series.length === 0 ? (
          <p className="py-8 text-center text-sm text-slate-500">
            Price history is not available for this stock yet. Please check back after the next data update.
          </p>
        ) : (
          <div className="space-y-1">
            <div className="mb-2 flex flex-wrap gap-4 text-xs">
              <label className="flex items-center gap-1.5">
                <input
                  type="checkbox"
                  checked={showMa}
                  onChange={(event) => setShowMa(event.target.checked)}
                />
                Moving averages
              </label>
              <label className="flex items-center gap-1.5">
                <input
                  type="checkbox"
                  checked={showBollinger}
                  onChange={(event) => setShowBollinger(event.target.checked)}
                />
                Bollinger bands
              </label>
              <label className="flex items-center gap-1.5">
                <input
                  type="checkbox"
                  checked={showZones}
                  onChange={(event) => setShowZones(event.target.checked)}
                />
                Trade zones
                {activeVerdict !== null && (
                  <span className="text-slate-400">({activeVerdict.profile_label})</span>
                )}
              </label>
            </div>

            <PriceChart
              series={series}
              showMovingAverages={showMa}
              showBollinger={showBollinger}
              zone={zone}
            />

            {/* A clipped level draws off-chart and reads as "no target at all",
                so say where it went rather than leaving a gap. */}
            {clippedLevels.length > 0 && (
              <p className="text-xs text-slate-400">
                {clippedLevels
                  .map((level) => `${level.label} ${money(level.value, 0)}`)
                  .join(' and ')}{' '}
                {clippedLevels.length === 1 ? 'is' : 'are'} outside this range — switch to 1Y or
                Max to see {clippedLevels.length === 1 ? 'it' : 'them'}.
              </p>
            )}

            <p className="pt-2 text-xs font-medium text-slate-500">Volume</p>
            <VolumeChart series={series} />

            {!isFund && (
              <>
                <p className="pt-2 text-xs font-medium text-slate-500">RSI (14)</p>
                <RsiChart series={series} />

                <p className="pt-2 text-xs font-medium text-slate-500">MACD (12, 26, 9)</p>
                <MacdChart series={series} />
              </>
            )}
          </div>
        )}
      </Card>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* AI signal */}
        <Card title="AI signal" className="lg:col-span-2">
          {latestSignal === null ? (
            <p className="py-6 text-center text-sm text-slate-500">
              No signal yet.{' '}
              {user?.is_admin === true
                ? 'Use “Re-run AI analysis” above to request one.'
                : 'Check back after the next analysis update.'}
            </p>
          ) : (
            <div className="space-y-4">
              <div className="flex flex-wrap items-center gap-2">
                <SignalBadge
                  signal={latestSignal.signal}
                  confidence={latestSignal.confidence}
                  stale={latestSignal.is_stale}
                />
                <RiskBadge risk={latestSignal.risk_level} />
                <span className="badge bg-slate-100 text-slate-600">
                  {latestSignal.time_horizon_label}
                </span>
                <span className="ml-auto text-xs text-slate-400">
                  {shortDate(latestSignal.analysis_date)} · {latestSignal.model}
                </span>
              </div>

              {latestSignal.summary !== null && (
                <p className="text-sm leading-relaxed text-slate-700">{latestSignal.summary}</p>
              )}

              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <LevelTile label="Entry" value={latestSignal.entry_price} max={latestSignal.entry_price_max} />
                <LevelTile label={<Explain term="stop">Stop loss</Explain>} value={latestSignal.stop_loss} tone="loss" />
                <LevelTile label="Target 1" value={latestSignal.target_1} tone="gain" />
                <LevelTile label="Target 2" value={latestSignal.target_2} tone="gain" />
                <LevelTile label="Exit" value={latestSignal.exit_price} />
                <div className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs text-slate-500">Risk / reward</p>
                  <p className="num text-lg font-semibold">
                    {latestSignal.risk_reward_ratio === null
                      ? '—'
                      : `1 : ${latestSignal.risk_reward_ratio.toFixed(2)}`}
                  </p>
                </div>
              </div>

              {latestSignal.rationale !== null && (
                <div className="grid gap-3 sm:grid-cols-2">
                  {latestSignal.rationale.bull_case !== undefined && (
                    <div className="rounded-lg border border-emerald-100 bg-emerald-50 p-3">
                      <p className="mb-1 text-xs font-semibold uppercase text-emerald-800">
                        Bull case
                      </p>
                      <p className="text-sm text-emerald-900">{latestSignal.rationale.bull_case}</p>
                    </div>
                  )}
                  {latestSignal.rationale.bear_case !== undefined && (
                    <div className="rounded-lg border border-red-100 bg-red-50 p-3">
                      <p className="mb-1 text-xs font-semibold uppercase text-red-800">Bear case</p>
                      <p className="text-sm text-red-900">{latestSignal.rationale.bear_case}</p>
                    </div>
                  )}
                </div>
              )}

              {latestSignal.rationale?.key_drivers !== undefined &&
                latestSignal.rationale.key_drivers.length > 0 && (
                  <div>
                    <p className="mb-1 text-xs font-semibold uppercase text-slate-500">
                      Key drivers
                    </p>
                    <ul className="list-inside list-disc space-y-1 text-sm text-slate-700">
                      {latestSignal.rationale.key_drivers.map((driver) => (
                        <li key={driver}>{driver}</li>
                      ))}
                    </ul>
                  </div>
                )}

              {latestSignal.rationale?.invalidation !== undefined && (
                <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                  <span className="font-semibold">What would prove this wrong: </span>
                  {latestSignal.rationale.invalidation}
                </div>
              )}

              {latestSignal.rationale?.data_gaps !== undefined &&
                latestSignal.rationale.data_gaps.length > 0 && (
                  <p className="text-xs text-slate-500">
                    <span className="font-semibold">Data gaps: </span>
                    {latestSignal.rationale.data_gaps.join('; ')}
                  </p>
                )}
            </div>
          )}
        </Card>

        <div className="space-y-6">
          {/* Technicals */}
          {!isFund && indicators !== null && (
            <Card title="Technicals" subtitle={shortDate(indicators.trade_date)}>
              <dl>
                <Field label={<Explain term="rsi">RSI (14)</Explain>}>
                  {indicators.rsi_14 === null
                    ? '—'
                    : `${indicators.rsi_14.toFixed(2)} (${indicators.rsi_zone ?? '—'})`}
                </Field>
                <Field label={<Explain term="macd">MACD histogram</Explain>}>
                  {indicators.macd_histogram === null ? '—' : indicators.macd_histogram.toFixed(4)}
                </Field>
                <Field label={<Explain term="sma">SMA 20</Explain>}>{money(indicators.sma_20)}</Field>
                <Field label="SMA 50">{money(indicators.sma_50)}</Field>
                <Field label="SMA 200">{money(indicators.sma_200)}</Field>
                <Field label="ATR (14)">{money(indicators.atr_14)}</Field>
                <Field label="Trend">
                  {indicators.trend === null ? (
                    '—'
                  ) : (
                    <span
                      className={clsx(
                        'badge',
                        indicators.trend === 'bullish'
                          ? 'bg-gain-light text-gain-dark'
                          : indicators.trend === 'bearish'
                            ? 'bg-loss-light text-loss-dark'
                            : 'bg-slate-100 text-slate-600',
                      )}
                    >
                      {indicators.trend} {indicators.trend_strength?.toFixed(0)}
                    </span>
                  )}
                </Field>
              </dl>
            </Card>
          )}

          {/* Fundamentals */}
          <Card title="Fundamentals" subtitle={fundamentals?.fiscal_period}>
            {fundamentals === null ? (
              <p className="py-4 text-center text-sm text-slate-500">
                Fundamental data is not available for this stock yet.
              </p>
            ) : (
              <dl>
                <Field label="EPS">{money(fundamentals.eps)}</Field>
                <Field label="P/E">{money(fundamentals.pe_ratio)}</Field>
                <Field label="Book value">{money(fundamentals.book_value)}</Field>
                <Field label="P/B">{money(fundamentals.pb_ratio)}</Field>
                <Field label="Dividend">{percent(fundamentals.dividend_percent)}</Field>
                <Field label="Dividend yield">{percent(fundamentals.dividend_yield)}</Field>
                <Field label="ROE">{percent(fundamentals.roe)}</Field>
                {fundamentals.npl_percent !== null && (
                  <Field label="NPL">{percent(fundamentals.npl_percent)}</Field>
                )}
                {fundamentals.capital_adequacy_ratio !== null && (
                  <Field label="Capital adequacy">
                    {percent(fundamentals.capital_adequacy_ratio)}
                  </Field>
                )}
              </dl>
            )}
          </Card>

          <Card title="Company">
            <dl>
              <Field label="Market cap">
                {stock.market_capitalization === null
                  ? '—'
                  : `NPR ${compactMoney(stock.market_capitalization)}`}
              </Field>
              <Field label="Listed shares">
                {stock.listed_shares === null ? '—' : compactMoney(stock.listed_shares)}
              </Field>
              <Field label="Previous close">{money(stock.previous_close)}</Field>
            </dl>
          </Card>
        </div>
      </div>

      {/* AI fundamental analysis */}
      {fundamental !== null && (
        <Card title="Fundamental analysis" subtitle={`${shortDate(fundamental.analysis_date)}`}>
          <div className="grid gap-6 lg:grid-cols-3">
            <div className="space-y-4">
              <div>
                <p className="text-xs uppercase text-slate-500">Valuation</p>
                <p className={clsx('text-lg font-semibold', scoreColor(fundamental.quality_score))}>
                  {titleCase(fundamental.valuation)}
                </p>
              </div>
              <ScoreBar score={fundamental.quality_score} label="Business quality" />
              <ScoreBar score={fundamental.financial_health_score} label="Financial health" />
              <ScoreBar
                score={fundamental.dividend_sustainability_score}
                label="Dividend sustainability"
              />
              {fundamental.fair_value_low !== null && (
                <div className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs text-slate-500">Estimated fair value</p>
                  <p className="num font-semibold">
                    <Npr value={fundamental.fair_value_low} /> – {money(fundamental.fair_value_high)}
                  </p>
                  {stock.last_price !== null && fundamental.fair_value_low !== null && (
                    <p className="mt-1 text-xs text-slate-500">
                      Trading at{' '}
                      {signedPercent(
                        ((stock.last_price -
                          (fundamental.fair_value_low + (fundamental.fair_value_high ?? fundamental.fair_value_low)) /
                            2) /
                          ((fundamental.fair_value_low +
                            (fundamental.fair_value_high ?? fundamental.fair_value_low)) /
                            2)) *
                          100,
                      )}{' '}
                      vs midpoint
                    </p>
                  )}
                </div>
              )}
            </div>

            <div className="lg:col-span-2">
              {fundamental.summary !== null && (
                <p className="mb-4 text-sm leading-relaxed text-slate-700">{fundamental.summary}</p>
              )}
              <div className="grid gap-4 sm:grid-cols-2">
                {fundamental.strengths !== null && fundamental.strengths.length > 0 && (
                  <div>
                    <p className="mb-1 text-xs font-semibold uppercase text-emerald-700">
                      Strengths
                    </p>
                    <ul className="list-inside list-disc space-y-1 text-sm text-slate-700">
                      {fundamental.strengths.map((item) => (
                        <li key={item}>{item}</li>
                      ))}
                    </ul>
                  </div>
                )}
                {fundamental.concerns !== null && fundamental.concerns.length > 0 && (
                  <div>
                    <p className="mb-1 text-xs font-semibold uppercase text-red-700">Concerns</p>
                    <ul className="list-inside list-disc space-y-1 text-sm text-slate-700">
                      {fundamental.concerns.map((item) => (
                        <li key={item}>{item}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            </div>
          </div>
        </Card>
      )}

      {/* Signal history */}
      {(signals.data?.length ?? 0) > 1 && (
        <Card title="Signal history">
          <div className="overflow-x-auto">
            <table className="w-full min-w-[40rem]">
              <thead className="border-b border-slate-200 bg-slate-50">
                <tr>
                  <th className="th">Date</th>
                  <th className="th">Signal</th>
                  <th className="th text-right">Ref. price</th>
                  <th className="th text-right">Entry</th>
                  <th className="th text-right">Stop</th>
                  <th className="th text-right">Target</th>
                  <th className="th">Risk</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(signals.data ?? []).map((signal) => (
                  <tr key={signal.id}>
                    <td className="td text-slate-600">{shortDate(signal.analysis_date)}</td>
                    <td className="td">
                      <SignalBadge signal={signal.signal} confidence={signal.confidence} />
                    </td>
                    <td className="td num text-right">{money(signal.reference_price)}</td>
                    <td className="td num text-right">{money(signal.entry_price)}</td>
                    <td className="td num text-right">{money(signal.stop_loss)}</td>
                    <td className="td num text-right">{money(signal.target_1)}</td>
                    <td className="td">
                      <RiskBadge risk={signal.risk_level} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <Link to="/stocks" className="inline-block text-sm text-slate-500 underline">
        ← Back to all stocks
      </Link>
    </div>
  )
}

function LevelTile({
  label,
  value,
  max,
  tone,
}: {
  /** A node, not just a string, so a label can carry an <Explain> tooltip. */
  label: ReactNode
  value: number | null
  max?: number | null
  tone?: 'gain' | 'loss'
}) {
  return (
    <div className="rounded-lg bg-slate-50 p-3">
      <p className="text-xs text-slate-500">{label}</p>
      <p
        className={clsx(
          'num text-lg font-semibold',
          tone === 'gain' ? 'text-gain' : tone === 'loss' ? 'text-loss' : 'text-slate-900',
        )}
      >
        {money(value)}
        {max !== null && max !== undefined && value !== null && max !== value && (
          <span className="text-sm font-normal text-slate-500"> – {money(max)}</span>
        )}
      </p>
    </div>
  )
}

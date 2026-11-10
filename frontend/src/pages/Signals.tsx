import { Link, useSearchParams } from 'react-router-dom'
import { Explain } from '../components/Explain'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, errorMessage } from '../lib/api'
import { money, quantity, shortDate, signedPercent } from '../lib/format'
import {
  Card,
  EmptyState,
  ErrorBox,
  RiskBadge,
  SignalBadge,
  Spinner,
} from '../components/ui'
import type { Paginated, Sector, StockSignal } from '../lib/types'

export function Signals() {
  const [params, setParams] = useSearchParams()

  const signal = params.get('signal') ?? ''
  const minConfidence = params.get('min_confidence') ?? ''
  const sector = params.get('sector') ?? ''
  const risk = params.get('risk_level') ?? ''
  const includeStale = params.get('include_stale') === '1'
  const page = params.get('page') ?? '1'

  const sectors = useQuery({
    queryKey: ['sectors'],
    queryFn: async () => (await api.get<{ data: Sector[] }>('/sectors')).data.data,
    staleTime: 30 * 60 * 1000,
  })

  const query = useQuery({
    queryKey: ['signals', { signal, minConfidence, sector, risk, includeStale, page }],
    queryFn: async () => {
      const response = await api.get<Paginated<StockSignal>>('/signals', {
        params: {
          signal: signal === '' ? undefined : signal,
          min_confidence: minConfidence === '' ? undefined : minConfidence,
          sector: sector === '' ? undefined : sector,
          risk_level: risk === '' ? undefined : risk,
          include_stale: includeStale ? 1 : undefined,
          per_page: 25,
          page,
        },
      })

      return response.data
    },
    placeholderData: keepPreviousData,
  })

  function update(key: string, value: string) {
    setParams(
      (previous) => {
        const next = new URLSearchParams(previous)
        if (value === '') next.delete(key)
        else next.set(key, value)
        if (key !== 'page') next.delete('page')
        return next
      },
      { replace: true },
    )
  }

  const meta = query.data?.meta
  const ttlDays = meta?.signal_ttl_days ?? 3
  const staleAvailable = meta?.stale_available ?? 0
  const newestDate = meta?.newest_analysis_date ?? null

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">AI signals</h1>
        <p className="text-sm text-slate-500">
          AI-generated buy, sell and hold signals with suggested levels. They are not guarantees of future returns.
          {meta !== undefined && ` ${quantity(meta.total)} signals.`}
        </p>
        {newestDate !== null && (
          <p className="mt-1 text-xs text-slate-500">
            Latest analysis: {shortDate(newestDate)}. Check each signal's date before using it.
          </p>
        )}
      </div>

      <div className="card flex flex-wrap items-end gap-3 p-4">
        <div>
          <label className="label" htmlFor="signal">
            Signal
          </label>
          <select
            id="signal"
            className="input"
            value={signal}
            onChange={(event) => update('signal', event.target.value)}
          >
            <option value="">All</option>
            <option value="buy">Buy</option>
            <option value="hold">Hold</option>
            <option value="sell">Sell</option>
          </select>
        </div>

        <div>
          <label className="label" htmlFor="min_confidence">
            Min confidence
          </label>
          <select
            id="min_confidence"
            className="input"
            value={minConfidence}
            onChange={(event) => update('min_confidence', event.target.value)}
          >
            <option value="">Any</option>
            <option value="50">50%+</option>
            <option value="65">65%+</option>
            <option value="80">80%+</option>
          </select>
        </div>

        <div>
          <label className="label" htmlFor="risk_level">
            Risk
          </label>
          <select
            id="risk_level"
            className="input"
            value={risk}
            onChange={(event) => update('risk_level', event.target.value)}
          >
            <option value="">Any</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select>
        </div>

        <div>
          <label className="label" htmlFor="sector">
            Sector
          </label>
          <select
            id="sector"
            className="input"
            value={sector}
            onChange={(event) => update('sector', event.target.value)}
          >
            <option value="">All sectors</option>
            {(sectors.data ?? []).map((option) => (
              <option key={option.slug} value={option.slug}>
                {option.name}
              </option>
            ))}
          </select>
        </div>

        <label className="flex items-center gap-2 py-2 text-sm text-slate-600">
          <input
            type="checkbox"
            checked={includeStale}
            onChange={(event) => update('include_stale', event.target.checked ? '1' : '')}
          />
          Include stale
        </label>
      </div>

      {query.isError && (
        <ErrorBox message={errorMessage(query.error)} onRetry={() => query.refetch()} />
      )}

      {query.isLoading ? (
        <Spinner label="Loading signals…" />
      ) : (query.data?.data.length ?? 0) === 0 ? (
        staleAvailable > 0 ? (
          // Signals exist, they have just aged out. Saying "no signals yet"
          // here sends the user to re-run an analysis when the answer is one
          // checkbox away.
          <EmptyState
            title={`No signals from the last ${ttlDays} day${ttlDays === 1 ? '' : 's'}`}
            hint={`${quantity(staleAvailable)} older signals exist${
              newestDate === null ? '' : `, the newest from ${shortDate(newestDate)}`
            }. Tick "Include stale" to view them. Older signals may no longer reflect current market conditions.`}
          />
        ) : (
          <EmptyState
            title="No signals yet"
            hint="AI signals have not been published yet. You can still browse stocks, compare fundamentals, and track your portfolio."
          />
        )
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {(query.data?.data ?? []).map((item) => (
            <SignalCard key={item.id} signal={item} />
          ))}
        </div>
      )}

      {meta !== undefined && meta.last_page > 1 && (
        <div className="flex items-center justify-center gap-2 text-sm">
          <button
            type="button"
            className="btn-secondary"
            disabled={meta.current_page <= 1}
            onClick={() => update('page', String(meta.current_page - 1))}
          >
            Previous
          </button>
          <span className="num px-2 text-slate-600">
            {meta.current_page} / {meta.last_page}
          </span>
          <button
            type="button"
            className="btn-secondary"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => update('page', String(meta.current_page + 1))}
          >
            Next
          </button>
        </div>
      )}
    </div>
  )
}

function SignalCard({ signal }: { signal: StockSignal }) {
  return (
    <Card className={signal.is_stale ? 'opacity-75' : undefined}>
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <Link to={`/stocks/${signal.symbol}`} className="font-semibold text-slate-900 hover:underline">
            {signal.symbol}
          </Link>
          <p className="max-w-[12rem] truncate text-xs text-slate-500">{signal.name}</p>
          <p className="text-xs text-slate-400">{signal.sector ?? 'Unclassified'}</p>
        </div>
        <div className="flex flex-col items-end gap-1">
          <SignalBadge
            signal={signal.signal}
            confidence={signal.confidence}
            stale={signal.is_stale}
          />
          <RiskBadge risk={signal.risk_level} />
        </div>
      </div>

      {signal.summary !== null && (
        <p className="mb-3 line-clamp-3 text-sm text-slate-600">{signal.summary}</p>
      )}

      <dl className="grid grid-cols-3 gap-2 text-center">
        <div className="rounded-md bg-slate-50 p-2">
          <dt className="text-[10px] uppercase text-slate-500">Entry</dt>
          <dd className="num text-sm font-semibold">{money(signal.entry_price)}</dd>
        </div>
        <div className="rounded-md bg-loss-light p-2">
          <dt className="text-[10px] uppercase text-loss-dark"><Explain term="stop">Stop</Explain></dt>
          <dd className="num text-sm font-semibold text-loss-dark">{money(signal.stop_loss)}</dd>
        </div>
        <div className="rounded-md bg-gain-light p-2">
          <dt className="text-[10px] uppercase text-gain-dark"><Explain term="target">Target</Explain></dt>
          <dd className="num text-sm font-semibold text-gain-dark">{money(signal.target_1)}</dd>
        </div>
      </dl>

      <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
        <span>
          Ref {money(signal.reference_price)}
          {signal.expected_return_percent !== null &&
            ` · ${signedPercent(signal.expected_return_percent)} expected`}
        </span>
        <span>{shortDate(signal.analysis_date)}</span>
      </div>
    </Card>
  )
}

import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '../lib/auth'
import { api, errorMessage } from '../lib/api'
import { compactMoney, money, quantity, shortDate, signedPercent, changeColor } from '../lib/format'
import { Banner, Card, ChangePill, EmptyState, ErrorBox, Spinner, StatTile } from '../components/ui'
import { Explain } from '../components/Explain'
import { GettingStarted } from '../components/GettingStarted'
import type { MarketMover, MarketSummary } from '../lib/types'

/** Shared by the empty state and the loaded view, so both can fetch on demand. */
function useRefreshPrices(onNotice: (message: string) => void) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string }>('/market/refresh')).data,
    onSuccess: (data) => {
      onNotice(data.message)
      // The crawl runs on the queue and takes about a minute, so poll a couple
      // of times rather than refetching immediately into unchanged data.
      const invalidate = () => {
        void queryClient.invalidateQueries({ queryKey: ['market-summary'] })
        void queryClient.invalidateQueries({ queryKey: ['health'] })
        void queryClient.invalidateQueries({ queryKey: ['stocks'] })
      }

      setTimeout(invalidate, 20_000)
      setTimeout(invalidate, 60_000)
    },
    onError: (error) => onNotice(errorMessage(error)),
  })
}

export function Dashboard() {
  const [notice, setNotice] = useState<string | null>(null)
  const { user } = useAuth()
  const isAdmin = user?.is_admin === true

  const query = useQuery({
    queryKey: ['market-summary'],
    queryFn: async () => (await api.get<{ data: MarketSummary }>('/market/summary')).data.data,
  })

  const refresh = useRefreshPrices(setNotice)

  if (query.isLoading) return <Spinner label="Loading market summary…" />

  if (query.isError) {
    return <ErrorBox message={errorMessage(query.error)} onRetry={() => query.refetch()} />
  }

  const summary = query.data

  if (summary === undefined || summary.trade_date === null) {
    return (
      <div className="space-y-4">
        {notice !== null && <Banner tone="info">{notice}</Banner>}
        <EmptyState
          title="No market data yet"
          hint={isAdmin
            ? 'Fetch prices now. The initial history backfill must be completed on the server to populate charts and indicators.'
            : 'Market data is being prepared. Please check back later; your portfolio remains available.'}
          action={isAdmin ? (
            <button
              type="button"
              className="btn-primary mt-3"
              onClick={() => refresh.mutate()}
              disabled={refresh.isPending}
            >
              {refresh.isPending ? 'Fetching…' : 'Fetch prices now'}
            </button>
          ) : <Link to="/portfolio" className="btn-secondary mt-3">Open my portfolio</Link>}
        />
      </div>
    )
  }

  const breadth = summary.advances + summary.declines + summary.unchanged
  const advanceShare = breadth > 0 ? (summary.advances / breadth) * 100 : 0

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Market overview</h1>
          <p className="text-sm text-slate-500">
            Trading session {shortDate(summary.trade_date)} · {summary.traded_scrips} scrips ·
            prices update after market sessions
          </p>
        </div>
        <div className="flex items-center gap-2">
          {isAdmin && <button
            type="button"
            className="btn-secondary"
            onClick={() => refresh.mutate()}
            disabled={refresh.isPending}
            title="Crawl the latest prices right now instead of waiting for the next scheduled run"
          >
            {refresh.isPending ? 'Fetching…' : '↻ Fetch now'}
          </button>}
          <Link to="/signals" className="btn-secondary">
            View AI signals
          </Link>
        </div>
      </div>

      {notice !== null && <Banner tone="info">{notice}</Banner>}

      {/* Above the market numbers on purpose. Someone who has imported nothing
          cannot use any of what follows, and burying the path to a populated
          portfolio under three mover tables is how it goes unnoticed. It
          removes itself once every step is done. */}
      <GettingStarted />

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatTile
          label="Advances"
          value={quantity(summary.advances)}
          tone="gain"
          hint={`${advanceShare.toFixed(0)}% of traded scrips`}
        />
        <StatTile label="Declines" value={quantity(summary.declines)} tone="loss" />
        <StatTile label="Unchanged" value={quantity(summary.unchanged)} />
        <StatTile
          label={<Explain term="turnover">Turnover</Explain>}
          value={`NPR ${compactMoney(summary.total_turnover)}`}
          hint={`${compactMoney(summary.total_volume)} shares`}
        />
      </div>

      {/* Advance/decline bar — the fastest read on whether the market was broadly up. */}
      <div className="card p-4">
        <div className="mb-2 flex items-baseline justify-between text-sm">
          <span className="font-medium text-slate-700"><Explain term="breadth">Market breadth</Explain></span>
          <span className="num text-slate-500">
            {summary.advances} up · {summary.declines} down · {summary.unchanged} flat
          </span>
        </div>
        <div className="flex h-3 overflow-hidden rounded-full bg-slate-100">
          <div className="bg-gain" style={{ width: `${(summary.advances / breadth) * 100}%` }} />
          <div className="bg-flat" style={{ width: `${(summary.unchanged / breadth) * 100}%` }} />
          <div className="bg-loss" style={{ width: `${(summary.declines / breadth) * 100}%` }} />
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <MoverTable title="Top gainers" rows={summary.top_gainers} />
        <MoverTable title="Top losers" rows={summary.top_losers} />
        <MoverTable title="Most active by turnover" rows={summary.most_active} showTurnover />
      </div>
    </div>
  )
}

function MoverTable({
  title,
  rows,
  showTurnover,
}: {
  title: string
  rows: MarketMover[]
  showTurnover?: boolean
}) {
  return (
    <Card title={title} className="overflow-hidden">
      {rows.length === 0 ? (
        <p className="py-4 text-center text-sm text-slate-400">Nothing to show.</p>
      ) : (
        <table className="w-full">
          <tbody className="divide-y divide-slate-100">
            {rows.map((row) => (
              <tr key={row.symbol} className="hover:bg-slate-50">
                <td className="py-2">
                  <Link to={`/stocks/${row.symbol}`} className="block">
                    <span className="font-semibold text-slate-800">{row.symbol}</span>
                    <span className="block max-w-[14rem] truncate text-xs text-slate-500">
                      {row.name}
                    </span>
                  </Link>
                </td>
                <td className="num py-2 text-right text-sm">{money(row.close)}</td>
                <td className="py-2 pl-2 text-right">
                  {showTurnover === true ? (
                    <span className={`num text-sm ${changeColor(row.change_percent)}`}>
                      {compactMoney(row.turnover)}
                      <span className="block text-xs text-slate-400">
                        {signedPercent(row.change_percent)}
                      </span>
                    </span>
                  ) : (
                    <ChangePill value={row.change_percent} />
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  )
}

import { useEffect, useState } from 'react'
import { Explain } from '../components/Explain'
import { Link, useSearchParams } from 'react-router-dom'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, errorMessage } from '../lib/api'
import { compactMoney, money, quantity } from '../lib/format'
import {
  ChangePill,
  EmptyState,
  ErrorBox,
  FundTag,
  SignalBadge,
  Spinner,
} from '../components/ui'
import type { Paginated, Sector, Stock } from '../lib/types'

const SORTS = [
  { value: 'symbol', label: 'Symbol' },
  { value: 'change', label: 'Change %' },
  { value: 'price', label: 'Price' },
  { value: 'market_cap', label: 'Market cap' },
]

export function StockList() {
  const [params, setParams] = useSearchParams()

  const [search, setSearch] = useState(params.get('search') ?? '')

  // Debounce the search box so typing does not fire a request per keystroke.
  useEffect(() => {
    const timer = setTimeout(() => {
      setParams(
        (previous) => {
          const next = new URLSearchParams(previous)
          if (search.trim() === '') next.delete('search')
          else next.set('search', search.trim())
          next.delete('page')
          return next
        },
        { replace: true },
      )
    }, 350)

    return () => clearTimeout(timer)
  }, [search, setParams])

  const sector = params.get('sector') ?? ''
  const signal = params.get('signal') ?? ''
  const sort = params.get('sort') ?? 'symbol'
  const direction = params.get('direction') ?? 'asc'
  const page = params.get('page') ?? '1'

  const sectors = useQuery({
    queryKey: ['sectors'],
    queryFn: async () => (await api.get<{ data: Sector[] }>('/sectors')).data.data,
    staleTime: 30 * 60 * 1000,
  })

  const query = useQuery({
    queryKey: ['stocks', { search: params.get('search'), sector, signal, sort, direction, page }],
    queryFn: async () => {
      const response = await api.get<Paginated<Stock>>('/stocks', {
        params: {
          search: params.get('search') ?? undefined,
          sector: sector === '' ? undefined : sector,
          signal: signal === '' ? undefined : signal,
          sort,
          direction,
          page,
          per_page: 50,
        },
      })

      return response.data
    },
    // Keeps the previous page visible while the next loads, so the table does
    // not collapse to a spinner on every paginate.
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

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Stocks</h1>
        <p className="text-sm text-slate-500">
          {meta === undefined ? 'Loading…' : `${quantity(meta.total)} listed scrips`}
        </p>
      </div>

      <div className="card flex flex-wrap items-end gap-3 p-4">
        <div className="min-w-[14rem] flex-1">
          <label className="label" htmlFor="search">
            Search
          </label>
          <input
            id="search"
            className="input"
            placeholder="Symbol or company name"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
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
                {option.name} ({option.stocks_count})
              </option>
            ))}
          </select>
        </div>

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
            <option value="">Any</option>
            <option value="buy">Buy</option>
            <option value="hold">Hold</option>
            <option value="sell">Sell</option>
          </select>
        </div>

        <div>
          <label className="label" htmlFor="sort">
            Sort by
          </label>
          <select
            id="sort"
            className="input"
            value={sort}
            onChange={(event) => update('sort', event.target.value)}
          >
            {SORTS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>

        <button
          type="button"
          className="btn-secondary"
          onClick={() => update('direction', direction === 'asc' ? 'desc' : 'asc')}
          title="Toggle sort direction"
        >
          {direction === 'asc' ? '↑ Ascending' : '↓ Descending'}
        </button>
      </div>

      {query.isError && <ErrorBox message={errorMessage(query.error)} onRetry={() => query.refetch()} />}

      {query.isLoading ? (
        <Spinner label="Loading stocks…" />
      ) : (query.data?.data.length ?? 0) === 0 ? (
        <EmptyState title="No stocks matched" hint="Try clearing the filters." />
      ) : (
        <div className="card overflow-x-auto">
          <table className="w-full min-w-[52rem]">
            <thead className="border-b border-slate-200 bg-slate-50">
              <tr>
                <th className="th">Scrip</th>
                <th className="th">Sector</th>
                <th className="th text-right"><Explain term="ltp">LTP</Explain></th>
                <th className="th text-right">Change</th>
                <th className="th text-right">Market cap</th>
                <th className="th text-right">RSI</th>
                <th className="th">Signal</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {(query.data?.data ?? []).map((stock) => (
                <tr key={stock.symbol} className="hover:bg-slate-50">
                  <td className="td">
                    <Link to={`/stocks/${stock.symbol}`} className="flex items-center gap-2">
                      <span>
                        <span className="font-semibold text-slate-800">{stock.symbol}</span>
                        <span className="block max-w-[18rem] truncate text-xs text-slate-500">
                          {stock.name}
                        </span>
                      </span>
                      {stock.instrument_type === 'mutual_fund' && <FundTag />}
                    </Link>
                  </td>
                  <td className="td text-xs text-slate-500">{stock.sector ?? '—'}</td>
                  <td className="td num text-right font-medium">{money(stock.last_price)}</td>
                  <td className="td text-right">
                    <ChangePill value={stock.change_percent} />
                  </td>
                  <td className="td num text-right text-slate-600">
                    {stock.market_capitalization === null
                      ? '—'
                      : compactMoney(stock.market_capitalization)}
                  </td>
                  <td className="td num text-right text-slate-600">
                    {stock.indicators?.rsi_14 === null || stock.indicators?.rsi_14 === undefined
                      ? '—'
                      : stock.indicators.rsi_14.toFixed(1)}
                  </td>
                  <td className="td">
                    <SignalBadge
                      signal={stock.signal?.signal}
                      confidence={stock.signal?.confidence}
                      stale={stock.signal?.is_stale}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {meta !== undefined && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <span className="text-slate-500">
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {quantity(meta.total)}
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              className="btn-secondary"
              disabled={meta.current_page <= 1}
              onClick={() => update('page', String(meta.current_page - 1))}
            >
              Previous
            </button>
            <span className="num px-2 py-2 text-slate-600">
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
        </div>
      )}
    </div>
  )
}

import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import clsx from 'clsx'
import { api, errorMessage } from '../lib/api'
import { shortDate } from '../lib/format'
import { useAuth } from '../lib/auth'
import { Card, EmptyState, ErrorBox, Spinner, StatTile } from '../components/ui'
import { Explain } from '../components/Explain'
import type { CorporateAction, CorporateCalendar } from '../lib/types'

const WINDOWS = [7, 30, 90]

/**
 * Book closes, AGMs, dividends, rights and public issues.
 *
 * The most actionable data in this market. A book close is not a judgement to
 * weigh — it is a date that decides whether you receive a dividend or a bonus
 * at all, so the screen leads with how many days are left and flags the rows
 * that touch your own holdings.
 */
export function Calendar() {
  const { user } = useAuth()
  const [days, setDays] = useState(30)
  const [mineOnly, setMineOnly] = useState(false)

  const calendar = useQuery({
    queryKey: ['corporate-actions', days, user?.default_portfolio_id],
    queryFn: async () =>
      (
        await api.get<{ data: CorporateCalendar }>('/corporate-actions', {
          params: { days, portfolio: user?.default_portfolio_id ?? undefined },
        })
      ).data.data,
  })

  if (calendar.isLoading) return <Spinner label="Loading the calendar…" />
  if (calendar.isError) {
    return <ErrorBox message={errorMessage(calendar.error)} onRetry={() => calendar.refetch()} />
  }
  if (calendar.data === undefined) return null

  const data = calendar.data
  const filter = (rows: CorporateAction[]): CorporateAction[] =>
    mineOnly ? rows.filter((r) => r.affects_me) : rows

  const bookCloses = filter(data.book_closes)
  const meetings = filter(data.meetings)
  const issues = filter(data.open_issues)
  const mineCount = data.book_closes.filter((r) => r.affects_me).length

  if (data.total_stored === 0) {
    return (
      <EmptyState
        title="The calendar is empty"
        hint="Corporate events have not been loaded yet. Check back after the next market update."
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Corporate calendar</h1>
          <p className="mt-1 text-sm text-slate-500">
            Own a scrip on its book-close date and the dividend or bonus is yours. Miss it by a day
            and it is not.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {WINDOWS.map((option) => (
            <button
              key={option}
              type="button"
              className={clsx(days === option ? 'btn-primary' : 'btn-secondary')}
              onClick={() => setDays(option)}
            >
              {option} days
            </button>
          ))}
          {data.my_symbols.length > 0 && (
            <label className="ml-2 flex items-center gap-2 text-xs text-slate-500">
              <input
                type="checkbox"
                checked={mineOnly}
                onChange={(event) => setMineOnly(event.target.checked)}
              />
              Only my holdings
            </label>
          )}
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-4">
        <StatTile label={`Book closes in ${days}d`} value={String(data.book_closes.length)} />
        <StatTile label="On my holdings" value={String(mineCount)} />
        <StatTile label="Meetings ahead" value={String(data.meetings.length)} />
        <StatTile label="Actions on file" value={String(data.total_stored)} />
      </div>

      {mineCount === 0 && data.my_symbols.length > 0 && (
        <p className="rounded border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Nothing in the next {days} days affects your open holdings (
          {data.my_symbols.join(', ')}). That is the answer, not a gap in the data.
        </p>
      )}

      <Card
        title="Book closes"
        subtitle="The date that decides entitlement. A negative countdown means it has already passed."
      >
        {bookCloses.length === 0 ? (
          <p className="py-3 text-sm text-slate-500">Nothing in this window.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-2 py-2">Scrip</th>
                  <th className="px-2 py-2">Type</th>
                  <th className="px-2 py-2">
                    <Explain term="book-close">Book close</Explain>
                  </th>
                  <th className="px-2 py-2 text-right">In</th>
                  <th className="px-2 py-2">What</th>
                </tr>
              </thead>
              <tbody>
                {bookCloses.map((action) => (
                  <ActionRow key={action.id} action={action} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {meetings.length > 0 && (
        <Card title="AGMs and SGMs" subtitle="With the venue and agenda where the notice carries them.">
          <div className="space-y-3">
            {meetings.map((meeting) => (
              <div
                key={meeting.id}
                className={clsx(
                  'rounded border p-3',
                  meeting.affects_me ? 'border-sky-200 bg-sky-50' : 'border-slate-200',
                )}
              >
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                  <p className="font-medium text-slate-900">
                    {meeting.symbol}
                    {meeting.affects_me && (
                      <span className="ml-2 text-xs font-normal text-sky-700">you hold this</span>
                    )}
                    <span className="ml-2 text-sm font-normal text-slate-500">{meeting.title}</span>
                  </p>
                  <p className="text-sm text-slate-600">
                    {meeting.event_on !== null ? shortDate(meeting.event_on) : 'date not published'}
                    {meeting.book_close_on !== null && (
                      <span className="text-slate-400">
                        {' '}· book close {shortDate(meeting.book_close_on)}
                      </span>
                    )}
                  </p>
                </div>
                {meeting.venue !== null && (
                  <p className="mt-1 text-xs text-slate-500">{meeting.venue}</p>
                )}
                {meeting.agenda !== null && (
                  <p className="mt-1 text-xs text-slate-400">{meeting.agenda}</p>
                )}
              </div>
            ))}
          </div>
        </Card>
      )}

      {issues.length > 0 && (
        <Card title="Open issues" subtitle="IPOs, FPOs and rights currently accepting applications.">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-2 py-2">Scrip</th>
                  <th className="px-2 py-2">Type</th>
                  <th className="px-2 py-2">Closes</th>
                  <th className="px-2 py-2 text-right">Units</th>
                  <th className="px-2 py-2 text-right">Price</th>
                  <th className="px-2 py-2">Manager</th>
                </tr>
              </thead>
              <tbody>
                {issues.map((issue) => (
                  <tr key={issue.id} className="border-b border-slate-100">
                    <td className="px-2 py-2 font-medium text-slate-900">{issue.symbol}</td>
                    <td className="px-2 py-2 capitalize text-slate-600">
                      {issue.type.replace(/_/g, ' ')}
                    </td>
                    <td className="px-2 py-2 text-slate-500">
                      {issue.closes_on !== null ? shortDate(issue.closes_on) : 'not published'}
                    </td>
                    <td className="num px-2 py-2 text-right">{issue.units ?? 'n/a'}</td>
                    <td className="num px-2 py-2 text-right">{issue.issue_price ?? 'n/a'}</td>
                    <td className="px-2 py-2 text-xs text-slate-500">
                      {issue.issue_manager ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <p className="text-xs text-slate-400">
        {data.total_stored} actions on file
        {data.latest_published_on !== null && (
          <> · newest notice published {shortDate(data.latest_published_on)}</>
        )}
        . A rights entitlement date is not published anywhere crawlable, so rights rows carry their
        opening and closing dates but no record date.
      </p>
    </div>
  )
}

function ActionRow({ action }: { action: CorporateAction }) {
  const days = action.days_until_book_close
  const passed = days !== null && days < 0
  const imminent = days !== null && days >= 0 && days <= 7

  return (
    <tr
      className={clsx(
        'border-b border-slate-100',
        action.affects_me && 'bg-sky-50',
        passed && 'opacity-60',
      )}
    >
      <td className="px-2 py-2 font-medium text-slate-900">
        {action.symbol}
        {action.affects_me && (
          <span className="ml-2 text-xs font-normal text-sky-700">you hold this</span>
        )}
      </td>
      <td className="px-2 py-2 capitalize text-slate-600">{action.type.replace(/_/g, ' ')}</td>
      <td className="px-2 py-2 text-slate-500">
        {action.book_close_on !== null ? shortDate(action.book_close_on) : 'not published'}
      </td>
      <td
        className={clsx(
          'num px-2 py-2 text-right font-semibold',
          passed && 'text-slate-400',
          imminent && 'text-loss',
        )}
      >
        {days === null ? 'n/a' : passed ? `${Math.abs(days)}d ago` : `${days}d`}
      </td>
      <td className="px-2 py-2 text-slate-600">
        {action.title ?? '—'}
        {(action.cash_dividend_percent !== null || action.bonus_percent !== null) && (
          <span className="ml-2 text-xs text-slate-400">
            {action.cash_dividend_percent !== null && `${action.cash_dividend_percent}% cash`}
            {action.cash_dividend_percent !== null && action.bonus_percent !== null && ' + '}
            {action.bonus_percent !== null && `${action.bonus_percent}% bonus`}
          </span>
        )}
      </td>
    </tr>
  )
}

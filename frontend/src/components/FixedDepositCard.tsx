import clsx from 'clsx'
import { compactMoney, money } from '../lib/format'
import { Banner, Card } from './ui'
import type { PerformanceTotals } from '../lib/types'

/**
 * "Would a bank deposit have done better?" — and, separately, what the real
 * deposits actually earned.
 *
 * The comparison is deliberately equity-only. Money already sitting in a real
 * fixed deposit earns the deposit rate by definition, so putting it on both
 * sides of its own benchmark guarantees a tie on that slice and dilutes the
 * equity verdict in proportion to how much is on deposit. The API says which
 * basis it used and why; this card repeats it rather than letting the headline
 * figure imply it covers everything the user owns.
 */
export function FixedDepositCard({
  totals,
  basis,
  fdRate,
  onFdRate,
}: {
  totals: PerformanceTotals
  /** `equity_only` from the report; anything else is shown as-is. */
  basis: string | undefined
  fdRate: string
  onFdRate: (value: string) => void
}) {
  const comparable =
    totals.fixed_deposit_value !== null && totals.value_vs_fixed_deposit !== null

  const placementCount = totals.placement_count ?? 0

  /*
   * The two bases can disagree: in a fictional example, equity-only says
   * the deposit won by 750.00 while contributed capital says the portfolio
   * won by 1,250.00. That is not a contradiction and must not be
   * allowed to look like one — they answer different questions, and the card
   * labels each rather than picking a winner.
   */
  const capital = totals.capital_basis_available === true

  return (
    <Card
      title="Versus a fixed deposit"
      subtitle={`If the same money had gone into a ${totals.fixed_deposit_rate}% deposit on the same dates, compounding until today.`}
      action={
        <label className="flex items-center gap-2 text-xs text-slate-500">
          FD rate
          <input
            type="number"
            step="0.25"
            min="0"
            max="30"
            value={fdRate}
            placeholder={String(totals.fixed_deposit_rate)}
            onChange={(event) => onFdRate(event.target.value)}
            className="input w-20 py-1 text-right"
          />
          %
        </label>
      }
    >
      {capital && (
        <div className="mb-5 rounded-lg border border-sky-200 bg-sky-50 p-4">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-sky-700">
            On the money you actually put in
          </p>
          <div className="mt-2 grid gap-4 sm:grid-cols-3">
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-400">Worth today</p>
              <p className="num mt-1 text-xl font-semibold text-slate-900">
                {compactMoney((totals.net_worth_vs_capital_fixed_deposit ?? 0) + (totals.capital_fixed_deposit_value ?? 0))}
              </p>
            </div>
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-400">
                Same money in a {totals.fixed_deposit_rate}% deposit
              </p>
              <p className="num mt-1 text-xl font-semibold text-slate-600">
                {compactMoney(totals.capital_fixed_deposit_value)}
              </p>
            </div>
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-400">Difference</p>
              <p
                className={clsx(
                  'num mt-1 text-xl font-semibold',
                  totals.beat_fixed_deposit_on_capital === true ? 'text-gain' : 'text-loss',
                )}
              >
                {compactMoney(totals.net_worth_vs_capital_fixed_deposit)}
              </p>
            </div>
          </div>
          <p className="mt-3 text-xs leading-relaxed text-slate-600">
            {totals.capital_basis_note}
          </p>

          {/* Coverage, the same way the index benchmark labels a partial
              window. A denominator missing a sixth of the money makes the
              verdict provisional, and saying so is the whole point. */}
          {totals.capital_basis_complete === false &&
            totals.capital_unexplained_note !== null && (
              <p className="mt-3 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
                <strong>Provisional.</strong> {totals.capital_unexplained_note}
              </p>
            )}
        </div>
      )}

      {basis === 'equity_only' && (
        <p className="mb-3">
          <span className="badge bg-slate-900 text-white">Equity only</span>
          <span className="ml-2 text-xs text-slate-500">
            {capital
              ? 'A different question from the panel above: did these trades beat a deposit, ignoring idle cash. The two can disagree.'
              : 'Compares the equity book against a hypothetical deposit fed the same trade flows.'}
          </span>
        </p>
      )}

      {!comparable ? (
        <p className="text-sm text-slate-600">
          Not enough transaction history to run the comparison — it needs at least one dated cash
          flow to compound. Nothing is shown rather than a zero, which would read as a tie.
        </p>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-3">
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-400">Your equity book</p>
              <p className="num mt-1 text-2xl font-semibold text-slate-900">
                {compactMoney(totals.market_value)}
              </p>
            </div>
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-400">
                In a {totals.fixed_deposit_rate}% deposit
              </p>
              <p className="num mt-1 text-2xl font-semibold text-slate-600">
                {compactMoney(totals.fixed_deposit_value)}
              </p>
            </div>
            <div>
              <p className="text-xs uppercase tracking-wide text-slate-400">Difference</p>
              <p
                className={clsx(
                  'num mt-1 text-2xl font-semibold',
                  totals.beat_fixed_deposit === null
                    ? 'text-slate-400'
                    : totals.beat_fixed_deposit
                      ? 'text-gain'
                      : 'text-loss',
                )}
              >
                {compactMoney(totals.value_vs_fixed_deposit)}
              </p>
            </div>
          </div>

          <p className="mt-4 text-sm text-slate-600">
            {totals.beat_fixed_deposit === null ? (
              <>The verdict cannot be stated on this data.</>
            ) : totals.beat_fixed_deposit ? (
              <>
                Your shares are{' '}
                <strong>
                  {compactMoney(Math.abs(totals.value_vs_fixed_deposit ?? 0))} ahead
                </strong>{' '}
                of the deposit — the equity risk has been paid for.
              </>
            ) : (
              <>
                Your shares are{' '}
                <strong>
                  {compactMoney(Math.abs(totals.value_vs_fixed_deposit ?? 0))} behind
                </strong>{' '}
                a {totals.fixed_deposit_rate}% deposit. The equity risk has not been paid for over
                this period.
              </>
            )}
          </p>
        </>
      )}

      {/* Which side of the line real deposits fall on — stated, never implied. */}
      {totals.fixed_deposit_comparison_note !== undefined && (
        <p className="mt-3 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600">
          <strong>What this compares.</strong> {totals.fixed_deposit_comparison_note}
        </p>
      )}

      <div className="mt-4 border-t border-slate-100 pt-4">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Your real fixed deposits
        </p>

        {placementCount === 0 ? (
          <p className="mt-2 text-sm text-slate-600">
            None recorded, so the deposit above is entirely hypothetical. Add the ones you hold on
            the Portfolio page and their actual interest will be reported here as income.
          </p>
        ) : (
          <>
            <div className="mt-2 grid gap-4 sm:grid-cols-3">
              <Figure
                label="Interest actually earned"
                value={totals.placement_interest_earned}
                hint="Income, on the same Actual/365 basis as the benchmark above"
                tone="gain"
              />
              <Figure
                label="Principal placed"
                value={totals.placement_principal_placed}
                hint={`Across ${placementCount} deposit${placementCount === 1 ? '' : 's'}`}
              />
              <Figure
                label="Counted in net worth"
                value={totals.placement_value_in_net_worth}
                hint="Only deposits whose principal actually left a cash account"
              />
            </div>

            {typeof totals.placement_note === 'string' && (
              <div className="mt-3">
                <Banner
                  tone={
                    totals.placement_value_in_net_worth !== null &&
                    totals.placement_value_in_net_worth !== undefined &&
                    totals.placement_principal_placed !== null &&
                    totals.placement_principal_placed !== undefined &&
                    totals.placement_value_in_net_worth < totals.placement_principal_placed
                      ? 'warning'
                      : 'info'
                  }
                >
                  {totals.placement_note}
                </Banner>
              </div>
            )}
          </>
        )}
      </div>

      <p className="mt-3 text-xs text-slate-400">
        Both sides are charged the same way: money put in earns until today, money taken out (a
        sale, a dividend) stops earning when you took it.
      </p>
    </Card>
  )
}

/** Null means not available — never a zero, which would read as a real figure. */
function Figure({
  label,
  value,
  hint,
  tone,
}: {
  label: string
  value: number | null | undefined
  hint: string
  tone?: 'gain'
}) {
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-slate-400">{label}</p>
      <p
        className={clsx(
          'num mt-1 text-xl font-semibold',
          value === null || value === undefined
            ? 'text-slate-300'
            : tone === 'gain'
              ? 'text-gain'
              : 'text-slate-900',
        )}
      >
        {value === null || value === undefined ? 'n/a' : `NPR ${money(value)}`}
      </p>
      <p className="mt-1 text-xs text-slate-400">{hint}</p>
    </div>
  )
}

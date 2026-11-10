import { Link } from 'react-router-dom'
import clsx from 'clsx'
import { nextStep, useOnboarding } from '../lib/onboarding'
import type { OnboardingStep } from '../lib/types'

/**
 * The path from a freshly set-up install to a portfolio that answers questions.
 *
 * `nepse:setup` leaves the market data in place and nothing else, so the first
 * thing a new user sees is a working app full of empty tiles. Nothing on the
 * screen distinguishes "you have not imported anything yet" from "this feature
 * is broken", and the five things that need doing are spread across five pages
 * with no indication that an order exists — cash before performance, channels
 * before the trading result means anything.
 *
 * So this says what is left, in order, with the reason each one matters. It
 * disappears on its own once every step is done, rather than needing to be
 * dismissed: a checklist that has to be closed by hand ends up closed while
 * still incomplete.
 */
export function GettingStarted() {
  const { data, isLoading } = useOnboarding()

  // Never a skeleton: this card is an aside, and a loading placeholder above
  // the market summary would be the most prominent thing on the page.
  if (isLoading || data === undefined || data.complete) return null

  const next = nextStep(data)

  return (
    <section className="card border-sky-200 bg-sky-50/50">
      <header className="flex flex-wrap items-baseline justify-between gap-2 border-b border-sky-100 px-4 py-3">
        <div>
          <h2 className="font-semibold text-slate-900">Getting set up</h2>
          <p className="text-xs text-slate-600">
            These steps personalize the app around{' '}
            <em>your</em> money.
          </p>
        </div>
        <span className="num shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 ring-1 ring-sky-200">
          {data.completed} of {data.total} done
        </span>
      </header>

      <div className="p-4">
        {next !== null && (
          <div className="mb-4 rounded-lg border border-sky-200 bg-white p-3">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-sky-700">
              Do this next
            </p>
            <p className="mt-1 font-medium text-slate-900">{next.title}</p>
            <p className="mt-1 text-sm leading-relaxed text-slate-600">{next.why}</p>
            <div className="mt-2 flex flex-wrap items-center gap-3">
              <Instruction text={next.how} />
              {next.link !== null && (
                <Link to={next.link} className="btn-primary shrink-0">
                  Open
                </Link>
              )}
            </div>
          </div>
        )}

        <ol className="space-y-1">
          {data.steps.map((step) => (
            <StepRow key={step.key} step={step} isNext={step.key === data.next} />
          ))}
        </ol>
      </div>
    </section>
  )
}

function StepRow({ step, isNext }: { step: OnboardingStep; isNext: boolean }) {
  return (
    <li className="flex items-baseline gap-2 text-sm">
      <span
        aria-hidden
        className={clsx(
          'shrink-0 text-xs',
          step.done ? 'text-emerald-600' : isNext ? 'text-sky-600' : 'text-slate-300',
        )}
      >
        {step.done ? '✓' : '○'}
      </span>
      <span className={clsx('shrink-0', step.done ? 'text-slate-500' : 'text-slate-800')}>
        {step.link === null || step.done ? (
          step.title
        ) : (
          <Link to={step.link} className="underline decoration-slate-300 hover:decoration-slate-600">
            {step.title}
          </Link>
        )}
      </span>
      {step.detail !== null && (
        <span className="truncate text-xs text-slate-400">{step.detail}</span>
      )}
      <span className="sr-only">{step.done ? 'done' : 'not done'}</span>
    </li>
  )
}

/**
 * Why a page has nothing on it, and the one thing to do about it.
 *
 * Every "No portfolio yet" on its own is honest but useless — it names the
 * absence and leaves the reader to work out which of six screens fixes it.
 * The next action here comes from the same checklist the Dashboard reads, so
 * the two cannot drift apart, and the instruction stays right after the data
 * actually arrives by some other route.
 */
export function SetupNeeded({ title, because }: { title: string; because: string }) {
  const { data } = useOnboarding()
  const next = nextStep(data)

  return (
    <div className="mx-auto flex max-w-lg flex-col items-center gap-2 py-12 text-center">
      <p className="font-medium text-slate-700">{title}</p>
      <p className="text-sm text-slate-500">{because}</p>

      {next !== null && (
        <div className="mt-4 w-full rounded-lg border border-slate-200 bg-white p-4 text-left">
          <p className="text-[10px] font-semibold uppercase tracking-wider text-sky-700">
            Start here
          </p>
          <p className="mt-1 font-medium text-slate-900">{next.title}</p>
          <p className="mt-1 text-sm leading-relaxed text-slate-600">{next.why}</p>
          <div className="mt-3 flex flex-wrap items-center gap-3">
            <Instruction text={next.how} />
            {next.link !== null && (
              <Link to={next.link} className="btn-primary shrink-0">
                Open
              </Link>
            )}
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * An instruction sentence with any shell command set in monospace.
 *
 * Several steps can only be done from the terminal, and a command rendered as
 * prose is one people mistype — it is the spacing and the exact flag that
 * matter, and proportional type hides both.
 */
function Instruction({ text }: { text: string }) {
  const parts = text.split(/(php artisan [^.]*)/)

  return (
    <p className="min-w-0 flex-1 text-sm text-slate-600">
      {parts.map((part, index) =>
        part.startsWith('php artisan ') ? (
          <code
            key={index}
            className="break-all rounded bg-slate-100 px-1.5 py-0.5 text-[13px] text-slate-800"
          >
            {part.trim()}
          </code>
        ) : (
          <span key={index}>{part}</span>
        ),
      )}
    </p>
  )
}

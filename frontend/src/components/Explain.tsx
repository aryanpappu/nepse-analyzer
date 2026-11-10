import { useEffect, useId, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import clsx from 'clsx'
import { GLOSSARY } from '../lib/glossary'

/**
 * Marks a jargon term and explains it on demand.
 *
 * Deliberately opt-in rather than always-on prose. Someone who trades NEPSE
 * daily does not need "book close" spelled out in the page body, and padding
 * every screen with definitions would make the app worse for them. A dotted
 * underline that yields a sentence when asked serves the newcomer without
 * taxing the expert.
 *
 * Hover alone would be useless on a phone and invisible to a keyboard, so the
 * trigger is a real button: hover, focus and tap all open it, Escape closes it,
 * and the tooltip is wired to the trigger with `aria-describedby` so a screen
 * reader announces the definition rather than a bare underline.
 */
export function Explain({
  term,
  children,
  className,
}: {
  term: keyof typeof GLOSSARY | string
  children?: ReactNode
  className?: string
}) {
  const entry = GLOSSARY[term]
  const [open, setOpen] = useState(false)
  const id = useId()
  const wrapper = useRef<HTMLSpanElement>(null)

  useEffect(() => {
    if (!open) return

    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') setOpen(false)
    }

    // A tap elsewhere should dismiss it; without this the tooltip would stay
    // open on touch devices, where there is no pointer to leave.
    function onPointerDown(event: PointerEvent) {
      if (!wrapper.current?.contains(event.target as Node)) setOpen(false)
    }

    document.addEventListener('keydown', onKey)
    document.addEventListener('pointerdown', onPointerDown)

    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('pointerdown', onPointerDown)
    }
  }, [open])

  // An unknown term must still render its label. A missing definition is a
  // content gap, not a reason to drop a word out of a sentence.
  if (entry === undefined) {
    if (import.meta.env.DEV) {
      console.warn(`[Explain] No glossary entry for "${term}".`)
    }

    return <>{children ?? term}</>
  }

  return (
    <span ref={wrapper} className="relative inline-block">
      <button
        type="button"
        aria-describedby={open ? id : undefined}
        aria-expanded={open}
        className={clsx(
          // Inherit the surrounding type rather than imposing button defaults:
          // a StatTile label is uppercase and letter-spaced, and a term that
          // silently dropped out of that treatment would look like a mistake.
          'cursor-help border-b border-dotted border-slate-400 text-left',
          'font-[inherit] tracking-[inherit] [text-transform:inherit]',
          'hover:border-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500',
          className,
        )}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onClick={(event) => {
          // Tap to pin on touch, where there is no hover.
          event.preventDefault()
          setOpen((v) => !v)
        }}
      >
        {children ?? entry.title}
      </button>

      {open && (
        <span
          id={id}
          role="tooltip"
          // The trigger inherits its surroundings on purpose, but the panel
          // must not: dropped inside an uppercase, letter-spaced tile label it
          // would render the whole definition in capitals.
          className="absolute left-0 top-full z-30 mt-1 block w-72 rounded-lg border border-slate-200 bg-white p-3 text-left normal-case tracking-normal shadow-lg"
        >
          <span className="block text-xs font-semibold text-slate-900">{entry.title}</span>
          <span className="mt-1 block text-xs font-normal leading-relaxed text-slate-600">
            {entry.body}
          </span>
        </span>
      )}
    </span>
  )
}

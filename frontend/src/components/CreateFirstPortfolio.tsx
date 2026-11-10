import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '../lib/api'
import { ErrorBox } from './ui'
import type { Portfolio } from '../lib/types'

/**
 * Breaks the first-run deadlock.
 *
 * With no portfolio, the Portfolio page sent you to Import and Import replied
 * "create a portfolio first, then come back" — a loop with no exit anywhere in
 * the UI, because nothing ever offered to create one. The API has always had
 * `POST /portfolios` and needs only a name; the screen simply never asked.
 *
 * Rendered wherever that dead end was, so the answer is on the page that
 * raised the problem rather than one navigation away.
 */
export function CreateFirstPortfolio({ onCreated }: { onCreated?: (portfolio: Portfolio) => void }) {
  const queryClient = useQueryClient()
  const [name, setName] = useState('My portfolio')
  const [error, setError] = useState<string | null>(null)

  const create = useMutation({
    mutationFn: async () =>
      (await api.post<{ data: Portfolio }>('/portfolios', { name: name.trim() })).data.data,
    onSuccess: (portfolio) => {
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['portfolios'] })
      // The checklist's first two steps both turn on this, so it must not be
      // left showing "create a portfolio" against one that now exists.
      void queryClient.invalidateQueries({ queryKey: ['onboarding'] })
      onCreated?.(portfolio)
    },
    onError: (caught) => setError(errorMessage(caught)),
  })

  return (
    <form
      className="mx-auto w-full max-w-sm text-left"
      onSubmit={(event) => {
        event.preventDefault()
        if (name.trim() !== '') create.mutate()
      }}
    >
      {error !== null && <ErrorBox message={error} />}

      <label className="block text-xs font-medium text-slate-600" htmlFor="new-portfolio-name">
        Name it
      </label>
      <div className="mt-1 flex gap-2">
        <input
          id="new-portfolio-name"
          className="input flex-1"
          value={name}
          maxLength={120}
          onChange={(event) => setName(event.target.value)}
          placeholder="My portfolio"
        />
        <button
          type="submit"
          className="btn-primary shrink-0"
          disabled={create.isPending || name.trim() === ''}
        >
          {create.isPending ? 'Creating…' : 'Create'}
        </button>
      </div>
      <p className="mt-2 text-xs text-slate-500">
        Just a container — you can rename it later, and the import will fill it.
      </p>
    </form>
  )
}

import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import { useAuth } from './auth'
import type { Portfolio } from './types'

const STORAGE_KEY = 'nepse.portfolio'

/**
 * Which portfolio the money pages are about.
 *
 * Each page used to pick its own — Portfolio and Performance kept a local
 * selection, every other page silently took the first in the list — so after
 * switching to a second portfolio, the Tax, Attribution and import pages went
 * on answering for the first one, and an import could be committed into a
 * portfolio the user was not looking at. One context, kept in localStorage,
 * means every page answers for the same portfolio and the choice survives a
 * reload.
 */
interface PortfolioSelectionValue {
  portfolios: Portfolio[]
  isLoading: boolean
  isError: boolean
  active: Portfolio | null
  activeId: number | null
  setActiveId: (id: number) => void
}

const PortfolioSelectionContext = createContext<PortfolioSelectionValue | null>(null)

function readStored(): number | null {
  try {
    const value = Number(localStorage.getItem(STORAGE_KEY))

    return Number.isInteger(value) && value > 0 ? value : null
  } catch {
    return null
  }
}

export function PortfolioSelectionProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [storedId, setStoredId] = useState<number | null>(readStored)

  const query = useQuery({
    queryKey: ['portfolios'],
    queryFn: async () => (await api.get<{ data: Portfolio[] }>('/portfolios')).data.data,
    enabled: user !== null,
  })

  const setActiveId = useCallback((id: number) => {
    try {
      localStorage.setItem(STORAGE_KEY, String(id))
    } catch {
      // Storage can be unavailable (private mode); the choice still holds for this session.
    }
    setStoredId(id)
  }, [])

  const data = query.data
  const value = useMemo<PortfolioSelectionValue>(() => {
    const portfolios = data ?? []
    // A stored id that no longer exists (deleted, or another account's) falls
    // back to the default, which `/portfolios` always lists first.
    const active =
      portfolios.find((portfolio) => portfolio.id === storedId) ??
      portfolios.find((portfolio) => portfolio.is_default) ??
      portfolios[0] ??
      null

    return {
      portfolios,
      isLoading: query.isLoading,
      isError: query.isError,
      active,
      activeId: active?.id ?? null,
      setActiveId,
    }
  }, [data, storedId, setActiveId, query.isLoading, query.isError])

  return <PortfolioSelectionContext.Provider value={value}>{children}</PortfolioSelectionContext.Provider>
}

export function usePortfolioSelection(): PortfolioSelectionValue {
  const context = useContext(PortfolioSelectionContext)

  if (context === null) {
    throw new Error('usePortfolioSelection must be used inside PortfolioSelectionProvider')
  }

  return context
}

/** A picker for the header; renders nothing until there is a choice to make. */
export function PortfolioSwitcher() {
  const { portfolios, activeId, setActiveId } = usePortfolioSelection()

  if (portfolios.length < 2) return null

  return (
    <label className="flex items-center gap-1 text-xs text-slate-500">
      <span className="hidden sm:inline">Portfolio</span>
      <select
        aria-label="Portfolio"
        className="input w-auto py-1 text-sm"
        value={activeId ?? ''}
        onChange={(event) => setActiveId(Number(event.target.value))}
      >
        {portfolios.map((portfolio) => (
          <option key={portfolio.id} value={portfolio.id}>
            {portfolio.name}
          </option>
        ))}
      </select>
    </label>
  )
}

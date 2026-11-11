import { useQuery } from '@tanstack/react-query'
import { api, errorMessage } from '../lib/api'
import { AcquisitionChannelCard } from '../components/AcquisitionChannelCard'
import { ErrorBox, Spinner } from '../components/ui'
import { SetupNeeded } from '../components/GettingStarted'
import type { Portfolio } from '../lib/types'
import { usePortfolioSelection } from '../lib/portfolioSelection'

/**
 * Where a parcel came from, and what that does to the profit figures.
 *
 * Its own page rather than another card on Portfolio: that page is already
 * long, and this is a sit-down job — classifying parcels one at a time — not
 * something glanced at alongside holdings.
 */
export function Channels() {
  const { active: selected } = usePortfolioSelection()
  const portfolios = useQuery({
    queryKey: ['portfolios'],
    queryFn: async () => (await api.get<{ data: Portfolio[] }>('/portfolios')).data.data,
  })

  if (portfolios.isLoading) return <Spinner label="Loading portfolios…" />

  if (portfolios.isError) {
    return <ErrorBox message={errorMessage(portfolios.error)} onRetry={() => portfolios.refetch()} />
  }

  const active = selected ?? undefined

  if (active === undefined) {
    return (
      <SetupNeeded
        title="Nothing to classify yet"
        because="This page separates shares allotted to you at par from shares you chose to buy, which is what makes a trading result mean anything. It needs parcels to separate."
      />
    )
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">How you acquired each parcel</h1>
        <p className="mt-1 text-sm text-slate-500">
          IPO allotments arrive at par by lottery and are paid straight from your bank, so they never
          appear in a broker statement. Separating them from shares you chose to buy is what makes the
          trading result mean something.
        </p>
      </div>

      <AcquisitionChannelCard portfolioId={active.id} />
    </div>
  )
}

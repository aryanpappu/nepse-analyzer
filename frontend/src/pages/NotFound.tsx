import { Link } from 'react-router-dom'

export function NotFound() {
  return (
    <section className="card mx-auto max-w-lg space-y-4 p-6">
      <p className="text-sm font-medium text-slate-500">404</p>
      <h1 className="text-2xl font-semibold">Page not found</h1>
      <p className="text-sm text-slate-600">
        This address may have changed. Browse market data or open your portfolio to continue.
      </p>
      <div className="flex flex-wrap gap-3">
        <Link to="/" className="btn-primary">Market overview</Link>
        <Link to="/portfolio" className="btn-secondary">My portfolio</Link>
      </div>
    </section>
  )
}

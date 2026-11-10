import { Component } from 'react'
import type { ErrorInfo, ReactNode } from 'react'

/** Keep a failed screen recoverable without showing implementation details. */
export class ErrorBoundary extends Component<{ children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (import.meta.env.DEV) console.error(error, info.componentStack)
  }

  render() {
    if (this.state.failed) {
      return (
        <section className="card mx-auto max-w-lg space-y-4 p-6" role="alert">
          <h1 className="text-xl font-semibold">This page could not be displayed</h1>
          <p className="text-sm text-slate-600">
            Reload the page to try again. If the problem continues, return to the market overview.
          </p>
          <div className="flex flex-wrap gap-3">
            <button type="button" className="btn-primary" onClick={() => window.location.reload()}>
              Reload page
            </button>
            <a className="btn-secondary" href="/">Market overview</a>
          </div>
        </section>
      )
    }

    return this.props.children
  }
}

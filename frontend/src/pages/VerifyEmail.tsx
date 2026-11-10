import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, errorMessage } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Banner, ErrorBox } from '../components/ui'

export function VerifyEmail() {
  const [params] = useSearchParams()
  const { refreshProfile } = useAuth()
  const [busy, setBusy] = useState(false)
  const [verified, setVerified] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const verificationUrl = params.get('verification_url') ?? ''
  // Accept only our signed relative API route, never an arbitrary emailed URL.
  const validLink = /^\/api\/auth\/email\/verify\/\d+\/[a-f0-9]{40}\?[^#]*$/.test(verificationUrl)

  async function verify() {
    setBusy(true)
    setError(null)
    try {
      await api.get(verificationUrl.slice('/api'.length))
      await refreshProfile()
      setVerified(true)
    } catch (caught) {
      setError(errorMessage(caught, 'This link is invalid or expired. Request a new one in Settings.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="card mx-auto max-w-lg space-y-4 p-6">
      <h1 className="text-2xl font-semibold">Verify your email</h1>
      {verified ? (
        <Banner tone="success">Your email address has been verified.</Banner>
      ) : validLink ? (
        <>
          <p className="text-sm text-slate-600">Confirm your email address for the account you are signed in to.</p>
          <button type="button" className="btn-primary" disabled={busy} onClick={verify}>
            {busy ? 'Verifying…' : 'Verify email address'}
          </button>
        </>
      ) : <ErrorBox message="This verification link is incomplete. Request a new one in Settings." />}
      {error !== null && <ErrorBox message={error} />}
      <p><Link to="/settings" className="text-sm font-medium underline">Open Settings</Link></p>
    </section>
  )
}

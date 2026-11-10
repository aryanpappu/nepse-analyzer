import { useState } from 'react'
import { api, errorMessage } from '../lib/api'
import { useAuth } from '../lib/auth'
import { Banner, Card, ErrorBox } from './ui'

export function EmailVerification() {
  const { user } = useAuth()
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  if (user === null) return null

  async function sendLink() {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      const response = await api.post<{ message: string }>('/auth/email/verification-notification')
      setNotice(response.data.message)
    } catch (caught) {
      setError(errorMessage(caught, 'The verification email could not be sent. Please try again.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card title="Email verification">
      <div className="space-y-3">
        <p className="text-sm text-slate-600">
          {user.email_verified_at
            ? `${user.email} is verified.`
            : `Confirm that ${user.email} belongs to you. You can continue using your portfolio while verification is pending.`}
        </p>
        {!user.email_verified_at && (
          <button type="button" className="btn-secondary" disabled={busy} onClick={sendLink}>
            {busy ? 'Sending…' : 'Send verification email'}
          </button>
        )}
        {notice !== null && <Banner tone="success">{notice}</Banner>}
        {error !== null && <ErrorBox message={error} />}
      </div>
    </Card>
  )
}

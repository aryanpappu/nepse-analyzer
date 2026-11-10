import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'nepse.token'

export const api = axios.create({
  // Relative base: Vite proxies /api to Laravel in development, and in
  // production the two are served from the same origin. No env var to forget.
  baseURL: import.meta.env.VITE_API_URL ?? '/api',
  headers: { Accept: 'application/json' },
  timeout: 180_000,
})

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token === null) {
    localStorage.removeItem(TOKEN_KEY)
  } else {
    localStorage.setItem(TOKEN_KEY, token)
  }
}

api.interceptors.request.use((config) => {
  const token = getToken()

  if (token !== null) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    // A 401 means the stored token is dead; clearing it sends the user back to
    // the login screen instead of leaving the app in a half-authenticated state
    // that fails every request.
    if (error.response?.status === 401 && getToken() !== null) {
      setToken(null)
      // Avoid a redirect loop when the failing call *is* the login attempt.
      if (!window.location.pathname.startsWith('/login')) {
        window.location.assign('/login')
      }
    }

    return Promise.reject(error)
  },
)

/**
 * Pull a human-usable message out of an axios error.
 *
 * Laravel replies in three shapes depending on what failed: a plain `message`,
 * a validation `errors` map, and a bare string for unhandled cases. The UI
 * should never show "Request failed with status code 422" to a user, so all
 * three are normalised here.
 */
export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (!axios.isAxiosError(error)) {
    return error instanceof Error ? error.message : fallback
  }

  const data = error.response?.data as
    | { message?: string; detail?: string; errors?: Record<string, string[]> }
    | string
    | undefined

  if (typeof data === 'string' && data.trim() !== '') {
    return data
  }

  if (data !== undefined && typeof data === 'object') {
    if (data.errors !== undefined) {
      const first = Object.values(data.errors).flat()[0]
      if (typeof first === 'string') {
        return first
      }
    }

    if (typeof data.message === 'string' && data.message.trim() !== '') {
      return data.detail !== undefined && data.detail !== null
        ? `${data.message} (${data.detail})`
        : data.message
    }
  }

  if (error.code === 'ECONNABORTED') {
    return 'The request took too long. Please try again.'
  }

  if (error.response === undefined) {
    return 'We could not connect. Check your internet connection and try again.'
  }

  return fallback
}

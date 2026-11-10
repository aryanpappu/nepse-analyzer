import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, getToken, setToken } from './api'
import type { AuthUser, ProfileUpdate } from './types'

interface AuthState {
  user: AuthUser | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  register: (name: string, email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refreshProfile: () => Promise<void>
  /**
   * Save profile / comparison settings and adopt the server's response as the
   * new user. Returning the fresh user rather than re-fetching keeps the
   * `_effective` fields in step with what was actually stored.
   */
  updateProfile: (changes: ProfileUpdate) => Promise<AuthUser>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [user, setUser] = useState<AuthUser | null>(null)
  // Starts true only when a token exists — with no token there is nothing to
  // verify, so the app renders the login screen immediately rather than
  // flashing a spinner.
  const [loading, setLoading] = useState<boolean>(getToken() !== null)

  useEffect(() => {
    if (getToken() === null) {
      return
    }

    let cancelled = false

    api
      .get<{ data: AuthUser }>('/auth/me')
      .then((response) => {
        if (!cancelled) setUser(response.data.data)
      })
      .catch(() => {
        // The interceptor already cleared a dead token.
        if (!cancelled) setUser(null)
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    const response = await api.post<{ data: { token: string; user: AuthUser } }>('/auth/login', {
      email,
      password,
    })

    await queryClient.cancelQueries()
    queryClient.clear()
    setToken(response.data.data.token)
    setUser(response.data.data.user)
  }, [queryClient])

  const register = useCallback(async (name: string, email: string, password: string) => {
    const response = await api.post<{ data: { token: string; user: AuthUser } }>('/auth/register', {
      name,
      email,
      password,
      password_confirmation: password,
    })

    await queryClient.cancelQueries()
    queryClient.clear()
    setToken(response.data.data.token)
    setUser(response.data.data.user)
  }, [queryClient])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // Even if revoking server-side fails, drop the local token — the user
      // asked to sign out and must not stay logged in.
    }

    await queryClient.cancelQueries()
    queryClient.clear()
    setToken(null)
    setUser(null)
  }, [queryClient])

  const refreshProfile = useCallback(async () => {
    const response = await api.get<{ data: AuthUser }>('/auth/me')
    setUser(response.data.data)
  }, [])

  const updateProfile = useCallback(async (changes: ProfileUpdate): Promise<AuthUser> => {
    const response = await api.patch<{ data: AuthUser }>('/auth/me', changes)
    setUser(response.data.data)

    return response.data.data
  }, [])

  const value = useMemo<AuthState>(
    () => ({ user, loading, login, register, logout, updateProfile, refreshProfile }),
    [user, loading, login, register, logout, updateProfile, refreshProfile],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthState {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider.')
  }

  return context
}

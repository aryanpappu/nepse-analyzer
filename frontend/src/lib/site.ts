import { useQuery } from '@tanstack/react-query'
import { api } from './api'

export interface SiteInformation {
  name: string
  operator_name: string | null
  contact_email: string | null
  privacy_url: string | null
  terms_url: string | null
  /** Private instance: market data needs a signed-in user. */
  require_login: boolean
  /** Self-service sign-up; when false only admins create accounts. */
  registration_open: boolean
}

/** Operator settings shared by the login page, the route gate and About. */
export function useSiteInformation() {
  return useQuery({
    queryKey: ['site-information'],
    queryFn: async () => (await api.get<{ data: SiteInformation }>('/site-information')).data.data,
    staleTime: 300_000,
  })
}

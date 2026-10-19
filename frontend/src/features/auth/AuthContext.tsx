import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, tokenStorage } from '@/lib/api'
import type { Role, User } from '@/types'

interface AuthContextValue {
  user: User | null
  loading: boolean
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
  hasRole: (...roles: Role[]) => boolean
  /** Boleh mencatat transaksi kas, transfer, master data (admin & bendahara). */
  canWrite: boolean
  /** Boleh membuat pengajuan dana & LPJ (admin, bendahara, sekretariat). */
  canManageFunds: boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(() => tokenStorage.get() !== null)

  useEffect(() => {
    if (!tokenStorage.get()) return
    api
      .get<{ data: User }>('/me')
      .then((res) => setUser(res.data.data))
      .catch(() => tokenStorage.clear())
      .finally(() => setLoading(false))
  }, [])

  const login = useCallback(async (username: string, password: string) => {
    const res = await api.post<{ token: string; user: User }>('/login', { username, password })
    tokenStorage.set(res.data.token)
    setUser(res.data.user)
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/logout')
    } finally {
      tokenStorage.clear()
      setUser(null)
    }
  }, [])

  const value = useMemo<AuthContextValue>(() => {
    const hasRole = (...roles: Role[]) => !!user && roles.includes(user.role)
    return {
      user,
      loading,
      login,
      logout,
      hasRole,
      canWrite: hasRole('admin', 'bendahara'),
      canManageFunds: hasRole('admin', 'bendahara', 'sekretariat'),
    }
  }, [user, loading, login, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth harus dipakai di dalam AuthProvider')
  return ctx
}

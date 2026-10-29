import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, tokenStorage } from '@/lib/api'
import type { Module, PermissionLevel, Role, User } from '@/types'

interface AuthContextValue {
  user: User | null
  loading: boolean
  login: (username: string, password: string) => Promise<void>
  logout: () => Promise<void>
  hasRole: (...roles: Role[]) => boolean
  /** Hak akses modul sesuai menu Hak Akses (admin selalu penuh). */
  can: (module: Module, level?: Exclude<PermissionLevel, 'none'>) => boolean
}

const RANK: Record<PermissionLevel, number> = { none: 0, view: 1, manage: 2 }

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
    const can = (module: Module, level: Exclude<PermissionLevel, 'none'> = 'view') =>
      !!user && (user.role === 'admin' || RANK[user.permissions?.[module] ?? 'none'] >= RANK[level])
    return { user, loading, login, logout, hasRole, can }
  }, [user, loading, login, logout])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth harus dipakai di dalam AuthProvider')
  return ctx
}

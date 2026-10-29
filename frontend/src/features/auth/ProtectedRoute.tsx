import { Navigate, Outlet, useLocation } from 'react-router'
import { Loader2 } from 'lucide-react'
import { useAuth } from './AuthContext'
import type { Module, Role } from '@/types'

interface Props {
  roles?: Role[]
  /** Modul hak akses (menu Hak Akses) yang wajib dimiliki. */
  module?: Module
  level?: 'view' | 'manage'
}

export function ProtectedRoute({ roles, module, level = 'view' }: Props) {
  const { user, loading, hasRole, can } = useAuth()
  const location = useLocation()

  if (loading) {
    return (
      <div className="flex h-svh items-center justify-center text-muted-foreground">
        <Loader2 className="size-6 animate-spin" />
      </div>
    )
  }

  if (!user) return <Navigate to="/login" replace state={{ from: location }} />
  if (roles && !hasRole(...roles)) return <Navigate to="/" replace />
  if (module && !can(module, level)) return <Navigate to="/" replace />

  return <Outlet />
}

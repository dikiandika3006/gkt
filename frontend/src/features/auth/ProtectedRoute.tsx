import { Navigate, Outlet, useLocation } from 'react-router'
import { Loader2 } from 'lucide-react'
import { useAuth } from './AuthContext'
import type { Role } from '@/types'

export function ProtectedRoute({ roles }: { roles?: Role[] }) {
  const { user, loading, hasRole } = useAuth()
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

  return <Outlet />
}

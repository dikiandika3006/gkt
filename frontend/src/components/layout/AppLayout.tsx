import { Suspense } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { BarChart3, CalendarCheck, ClipboardList, FolderTree, History, LayoutDashboard, Loader2, LogOut, ReceiptText, Users, Wallet } from 'lucide-react'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarTrigger,
} from '@/components/ui/sidebar'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Separator } from '@/components/ui/separator'
import { useAuth } from '@/features/auth/AuthContext'
import type { Role } from '@/types'

interface NavItem {
  to: string
  label: string
  icon: typeof LayoutDashboard
  roles?: Role[]
}

const navGroups: { label: string; items: NavItem[] }[] = [
  {
    label: 'Utama',
    items: [
      { to: '/', label: 'Dashboard', icon: LayoutDashboard },
      { to: '/transactions', label: 'Transaksi Kas', icon: ReceiptText },
      { to: '/recurring', label: 'Biaya Rutin', icon: CalendarCheck },
      { to: '/fund-requests', label: 'Kas Sekretariat', icon: ClipboardList },
      { to: '/reports', label: 'Laporan', icon: BarChart3 },
    ],
  },
  {
    label: 'Master Data',
    items: [
      { to: '/categories', label: 'Kategori', icon: FolderTree },
      { to: '/cash-accounts', label: 'Kas & Bank', icon: Wallet },
      { to: '/users', label: 'Pengguna', icon: Users, roles: ['admin'] },
      { to: '/activity-logs', label: 'Log Aktivitas', icon: History, roles: ['admin'] },
    ],
  },
]

const titles: Record<string, string> = Object.fromEntries(navGroups.flatMap((g) => g.items.map((i) => [i.to, i.label])))

export function AppLayout() {
  const { user, hasRole, logout } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()

  const isActive = (to: string) => (to === '/' ? location.pathname === '/' : location.pathname.startsWith(to))

  const onLogout = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <SidebarProvider>
      <Sidebar collapsible="icon">
        <SidebarHeader>
          <SidebarMenu>
            <SidebarMenuItem>
              <SidebarMenuButton size="lg" render={<NavLink to="/" />}>
                <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-white p-0.5">
                  <img src={`${import.meta.env.BASE_URL}logo-gkt.png`} alt="Logo GKT Sinai" className="size-full object-contain" />
                </div>
                <div className="grid flex-1 text-left text-sm leading-tight">
                  <span className="truncate font-semibold">Keuangan Gereja</span>
                  <span className="truncate text-xs text-muted-foreground">GKT</span>
                </div>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
          {navGroups.map((group) => (
            <SidebarGroup key={group.label}>
              <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {group.items
                    .filter((item) => !item.roles || hasRole(...item.roles))
                    .map((item) => (
                      <SidebarMenuItem key={item.to}>
                        <SidebarMenuButton isActive={isActive(item.to)} tooltip={item.label} render={<NavLink to={item.to} />}>
                          <item.icon />
                          <span>{item.label}</span>
                        </SidebarMenuButton>
                      </SidebarMenuItem>
                    ))}
                </SidebarMenu>
              </SidebarGroupContent>
            </SidebarGroup>
          ))}
        </SidebarContent>

        <SidebarFooter>
          <SidebarMenu>
            <SidebarMenuItem>
              <div className="flex items-center gap-2 px-1 py-1.5 group-data-[collapsible=icon]:hidden">
                <Avatar className="size-8">
                  <AvatarFallback>{user?.name.slice(0, 2).toUpperCase()}</AvatarFallback>
                </Avatar>
                <div className="grid flex-1 text-sm leading-tight">
                  <span className="truncate font-medium">{user?.name}</span>
                  <span className="truncate text-xs text-muted-foreground">{user?.role_label}</span>
                </div>
              </div>
            </SidebarMenuItem>
            <SidebarMenuItem>
              <SidebarMenuButton tooltip="Keluar" onClick={onLogout}>
                <LogOut />
                <span>Keluar</span>
              </SidebarMenuButton>
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarFooter>
      </Sidebar>

      <SidebarInset>
        <header className="flex h-14 shrink-0 items-center gap-2 border-b px-4">
          <SidebarTrigger className="-ml-1" />
          <Separator orientation="vertical" className="mr-2 h-4" />
          <span className="text-sm font-medium">{titles[location.pathname] ?? ''}</span>
        </header>
        <main className="flex flex-1 flex-col gap-6 p-4 md:p-6">
          <Suspense fallback={<Loader2 className="mx-auto mt-10 size-6 animate-spin text-muted-foreground" />}>
            <Outlet />
          </Suspense>
        </main>
      </SidebarInset>
    </SidebarProvider>
  )
}

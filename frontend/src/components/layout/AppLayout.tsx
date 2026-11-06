import { Fragment, Suspense, useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { BarChart3, CalendarCheck, ChevronDown, ClipboardList, FolderTree, HandCoins, History, Info, LayoutDashboard, Loader2, LogOut, PiggyBank, ReceiptText, Settings, Settings2, ShieldCheck, Users, Wallet } from 'lucide-react'
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
  useSidebar,
} from '@/components/ui/sidebar'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Separator } from '@/components/ui/separator'
import { AssistantChat } from '@/features/assistant/AssistantChat'
import { useAuth } from '@/features/auth/AuthContext'
import { REPORT_SECTIONS, REPORTS, reportPath } from '@/features/reports/registry'
import { cn } from '@/lib/utils'
import type { Module, Role } from '@/types'

interface NavItem {
  to: string
  label: string
  icon: typeof LayoutDashboard
  roles?: Role[]
  /** Tampil bila punya hak Lihat pada salah satu modul ini. */
  modules?: Module[]
}

const navGroups: { label: string; items: NavItem[] }[] = [
  {
    label: 'Utama',
    items: [
      { to: '/', label: 'Dashboard', icon: LayoutDashboard },
      { to: '/transactions', label: 'Mutasi', icon: ReceiptText, modules: ['transactions'] },
      { to: '/offerings', label: 'Rekap Persembahan', icon: HandCoins, modules: ['offerings'] },
      { to: '/recurring', label: 'Biaya Rutin', icon: CalendarCheck, modules: ['recurring'] },
      { to: '/budgets', label: 'Anggaran', icon: PiggyBank, modules: ['budgets'] },
      { to: '/fund-requests', label: 'Kas Sekretariat', icon: ClipboardList, modules: ['fund_requests'] },
    ],
  },
  {
    label: 'Master Data',
    items: [
      { to: '/categories', label: 'Kategori', icon: FolderTree, modules: ['categories'] },
      { to: '/cash-accounts', label: 'Kas & Bank', icon: Wallet, modules: ['cash_accounts'] },
      { to: '/offerings/settings', label: 'Pos Persembahan', icon: Settings2, modules: ['offerings'] },
      { to: '/users', label: 'Pengguna', icon: Users, roles: ['admin'] },
      { to: '/activity-logs', label: 'Log Aktivitas', icon: History, roles: ['admin'] },
      { to: '/permissions', label: 'Hak Akses', icon: ShieldCheck, roles: ['admin'] },
    ],
  },
  {
    label: 'Setting',
    items: [
      { to: '/settings', label: 'Setting', icon: Settings, roles: ['admin'] },
      { to: '/about', label: 'About', icon: Info, roles: ['admin'] },
    ],
  },
]

const titles: Record<string, string> = Object.fromEntries([
  ...navGroups.flatMap((g) => g.items.map((i) => [i.to, i.label])),
  ...REPORTS.map((r) => [reportPath(r), `Laporan · ${r.label}`]),
])

const REPORTS_OPEN_KEY = 'sidebar-reports-open'

/** Grup Laporan yang bisa dilipat; isinya dikelompokkan per bagian (Bulanan, Dana, Analisa, Tahunan). */
function ReportsNav({ isActive }: { isActive: (to: string) => boolean }) {
  const { can } = useAuth()
  const location = useLocation()
  const collapsed = useSidebar().state === 'collapsed'
  const [open, setOpen] = useState(() => {
    try {
      return localStorage.getItem(REPORTS_OPEN_KEY) !== '0'
    } catch {
      return true
    }
  })
  const reports = REPORTS.filter((r) => can(r.module))
  if (reports.length === 0) return null

  // Saat membuka halaman laporan atau sidebar mode ikon, grup selalu tampil terbuka.
  const expanded = open || collapsed || location.pathname.startsWith('/reports')
  const toggle = () => {
    const next = !expanded
    setOpen(next)
    try {
      localStorage.setItem(REPORTS_OPEN_KEY, next ? '1' : '0')
    } catch {
      // abaikan: penyimpanan browser tidak tersedia
    }
  }

  return (
    <SidebarGroup>
      <SidebarGroupLabel render={<button type="button" onClick={toggle} />} className="cursor-pointer hover:text-sidebar-foreground">
        <BarChart3 className="mr-2" /> Laporan
        <ChevronDown className={cn('ml-auto transition-transform', !expanded && '-rotate-90')} />
      </SidebarGroupLabel>
      {expanded && (
        <SidebarGroupContent>
          {REPORT_SECTIONS.map((section) => {
            const items = reports.filter((r) => r.section === section)
            if (items.length === 0) return null

            return (
              <SidebarMenu key={section}>
                <p className="px-2 pt-2 pb-0.5 text-[11px] uppercase tracking-wide text-sidebar-foreground/50 group-data-[collapsible=icon]:hidden">{section}</p>
                {items.map((r) => (
                  <SidebarMenuItem key={r.slug}>
                    <SidebarMenuButton isActive={isActive(reportPath(r))} tooltip={r.label} render={<NavLink to={reportPath(r)} />}>
                      <r.icon />
                      <span>{r.label}</span>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                ))}
              </SidebarMenu>
            )
          })}
        </SidebarGroupContent>
      )}
    </SidebarGroup>
  )
}

export function AppLayout() {
  const { user, hasRole, can, logout } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()

  // Menu aktif = path terpanjang yang cocok, agar /offerings/settings tidak ikut menyalakan /offerings.
  const activeTo = [...navGroups.flatMap((g) => g.items.map((i) => i.to)), ...REPORTS.map(reportPath)]
    .filter((to) => (to === '/' ? location.pathname === '/' : location.pathname.startsWith(to)))
    .sort((a, b) => b.length - a.length)[0]
  const isActive = (to: string) => to === activeTo

  // Menu disaring per role & hak akses modul; kelompok tanpa menu tidak ditampilkan.
  const visibleGroups = navGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => (!item.roles || hasRole(...item.roles)) && (!item.modules || item.modules.some((m) => can(m)))),
    }))
    .filter((group) => group.items.length > 0)

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
          {visibleGroups.map((group) => (
            <Fragment key={group.label}>
            <SidebarGroup>
              <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
              <SidebarGroupContent>
                <SidebarMenu>
                  {group.items.map((item) => (
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
            {group.label === 'Utama' && <ReportsNav isActive={isActive} />}
            </Fragment>
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
        {/* pb ekstra: ruang untuk tombol "Tanya Keuangan" yang mengambang */}
        <main className="flex flex-1 flex-col gap-6 p-4 pb-24 md:p-6 md:pb-24">
          <ErrorBoundary resetKey={location.pathname}>
            <Suspense fallback={<Loader2 className="mx-auto mt-10 size-6 animate-spin text-muted-foreground" />}>
              <Outlet />
            </Suspense>
          </ErrorBoundary>
        </main>
      </SidebarInset>
      {can('dashboard') && <AssistantChat />}
    </SidebarProvider>
  )
}

import { Badge } from '@/components/ui/badge'
import type { FundRequestStatus } from '@/types'

const VARIANT: Record<FundRequestStatus, 'outline' | 'secondary' | 'default'> = {
  diajukan: 'outline',
  ditransfer: 'secondary',
  lpj_sebagian: 'secondary',
  selesai: 'default',
}

export function StatusBadge({ status, label }: { status: FundRequestStatus; label: string }) {
  return <Badge variant={VARIANT[status]}>{label}</Badge>
}


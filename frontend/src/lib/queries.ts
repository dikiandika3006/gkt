import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { CashAccount, Category, RecurringExpense, TransactionType } from '@/types'

export function useCategories(params: { type?: TransactionType | ''; activeOnly?: boolean; excludeTransfer?: boolean } = {}) {
  return useQuery({
    queryKey: ['categories', params],
    queryFn: async () =>
      (
        await api.get<{ data: Category[] }>('/categories', {
          params: {
            type: params.type || undefined,
            active_only: params.activeOnly ? 1 : undefined,
            exclude_transfer: params.excludeTransfer ? 1 : undefined,
          },
        })
      ).data.data,
  })
}

export function useCashAccounts(params: { activeOnly?: boolean } = {}) {
  return useQuery({
    queryKey: ['cash-accounts', params],
    queryFn: async () =>
      (await api.get<{ data: CashAccount[] }>('/cash-accounts', { params: { active_only: params.activeOnly ? 1 : undefined } })).data
        .data,
  })
}

export function useRecurringExpenses() {
  return useQuery({
    queryKey: ['recurring-expenses'],
    queryFn: async () => (await api.get<{ data: RecurringExpense[] }>('/recurring-expenses')).data.data,
  })
}

export function useCounterparties(search: string) {
  return useQuery({
    queryKey: ['counterparties', search],
    queryFn: async () => (await api.get<{ data: string[] }>('/counterparties', { params: { search: search || undefined } })).data.data,
    staleTime: 60_000,
  })
}

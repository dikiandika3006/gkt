import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowRight, Bot, Loader2, MessageCircleQuestion, RotateCcw, SendHorizontal } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { api, errorMessage } from '@/lib/api'
import { formatRupiah } from '@/lib/format'
import { cn } from '@/lib/utils'
import type { AssistantAnswer } from '@/types'

type Message = { id: number; role: 'user' } & { text: string } | ({ id: number; role: 'assistant' } & AssistantAnswer)

const STORAGE_KEY = 'gkt.assistant.messages'

function loadMessages(): Message[] {
  try {
    return JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '[]') as Message[]
  } catch {
    return []
  }
}

/** Tombol mengambang + panel chat "Tanya Keuangan" (tanya-jawab berdasarkan data, tanpa AI luar). */
export function AssistantChat() {
  const [open, setOpen] = useState(false)
  const [input, setInput] = useState('')
  const [messages, setMessages] = useState<Message[]>(loadMessages)
  const listRef = useRef<HTMLDivElement>(null)

  const starters = useQuery({
    queryKey: ['assistant-starters'],
    queryFn: async () => (await api.get<{ questions: string[] }>('/assistant/starters')).data.questions,
    enabled: open,
    staleTime: Infinity,
  })

  const ask = useMutation({
    mutationFn: async (question: string) => (await api.post<AssistantAnswer>('/assistant/ask', { question })).data,
    onSuccess: (answer) => setMessages((m) => [...m, { id: Date.now(), role: 'assistant', ...answer }]),
    onError: (err) =>
      setMessages((m) => [
        ...m,
        { id: Date.now(), role: 'assistant', text: errorMessage(err, 'Maaf, terjadi kesalahan. Coba lagi.'), items: [], suggestions: [], link: null, intent: 'error' },
      ]),
  })

  useEffect(() => {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(messages.slice(-40)))
    } catch {
      // Penyimpanan tidak tersedia (mode privat) — riwayat cukup di memori.
    }
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' })
  }, [messages, ask.isPending])

  const send = (question: string) => {
    const q = question.trim()
    if (!q || ask.isPending) return
    setMessages((m) => [...m, { id: Date.now(), role: 'user', text: q }])
    setInput('')
    ask.mutate(q)
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    send(input)
  }

  return (
    <>
      <Button
        onClick={() => setOpen(true)}
        aria-label="Tanya Keuangan"
        className="fixed right-4 bottom-4 z-40 h-12 gap-2 rounded-full px-4 shadow-lg sm:right-6 sm:bottom-6"
      >
        <MessageCircleQuestion className="size-5" />
        <span className="hidden sm:inline">Tanya Keuangan</span>
      </Button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent className="gap-0 p-0 data-[side=right]:w-full data-[side=right]:sm:max-w-[420px]">
          <SheetHeader className="border-b pr-12">
            <SheetTitle className="flex items-center gap-2">
              <span className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-primary">
                <Bot className="size-4.5" />
              </span>
              Tanya Keuangan
            </SheetTitle>
            <SheetDescription>Jawaban dihitung langsung dari data transaksi, anggaran & biaya rutin.</SheetDescription>
          </SheetHeader>

          <div ref={listRef} className="flex-1 space-y-3 overflow-y-auto p-4">
            {messages.length === 0 && (
              <div className="space-y-3">
                <Bubble role="assistant">
                  Halo! Silakan tanya tentang keuangan gereja, misalnya saldo, pengeluaran per kategori, atau anggaran. Sebutkan periodenya juga ("bulan lalu",
                  "September", "tahun 2025").
                </Bubble>
                <Chips questions={starters.data ?? []} onPick={send} />
              </div>
            )}

            {messages.map((m) =>
              m.role === 'user' ? (
                <Bubble key={m.id} role="user">
                  {m.text}
                </Bubble>
              ) : (
                <div key={m.id} className="space-y-2">
                  <Bubble role="assistant">
                    <p>{m.text}</p>
                    {m.items.length > 0 && (
                      <ul className="mt-2 grid gap-1 border-t pt-2">
                        {m.items.map((item, i) => (
                          <li key={`${item.label}-${i}`} className="flex items-baseline justify-between gap-3 text-xs">
                            <span className="min-w-0">
                              <span className="block truncate">{item.label}</span>
                              {item.note && <span className="text-muted-foreground">{item.note}</span>}
                            </span>
                            <span className={cn('shrink-0 font-medium tabular-nums', item.value < 0 && 'text-destructive')}>{formatRupiah(item.value)}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                    {m.link && (
                      <Link
                        to={m.link}
                        onClick={() => setOpen(false)}
                        className="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                      >
                        Lihat detail <ArrowRight className="size-3" />
                      </Link>
                    )}
                  </Bubble>
                  <Chips questions={m.suggestions} onPick={send} />
                </div>
              ),
            )}

            {ask.isPending && (
              <Bubble role="assistant">
                <span className="flex items-center gap-2 text-muted-foreground">
                  <Loader2 className="size-3.5 animate-spin" /> Sedang menghitung…
                </span>
              </Bubble>
            )}
          </div>

          <form onSubmit={onSubmit} className="flex items-center gap-2 border-t p-3">
            {messages.length > 0 && (
              <Button type="button" variant="ghost" size="icon" aria-label="Mulai ulang percakapan" onClick={() => setMessages([])}>
                <RotateCcw />
              </Button>
            )}
            <Input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Tulis pertanyaan…"
              maxLength={300}
              aria-label="Pertanyaan"
              className="h-10"
            />
            <Button type="submit" size="icon" className="size-10 shrink-0" disabled={!input.trim() || ask.isPending} aria-label="Kirim">
              <SendHorizontal />
            </Button>
          </form>
        </SheetContent>
      </Sheet>
    </>
  )
}

function Bubble({ role, children }: { role: 'user' | 'assistant'; children: React.ReactNode }) {
  return (
    <div className={cn('flex', role === 'user' && 'justify-end')}>
      <div
        className={cn(
          'max-w-[85%] rounded-2xl px-3 py-2 text-sm',
          role === 'user' ? 'rounded-br-sm bg-primary text-primary-foreground' : 'rounded-bl-sm bg-muted text-foreground',
        )}
      >
        {children}
      </div>
    </div>
  )
}

function Chips({ questions, onPick }: { questions: string[]; onPick: (q: string) => void }) {
  if (questions.length === 0) return null

  return (
    <div className="flex flex-wrap gap-1.5">
      {questions.map((q) => (
        <button
          key={q}
          type="button"
          onClick={() => onPick(q)}
          className="rounded-full border border-primary/25 bg-primary/5 px-3 py-1 text-xs text-primary transition-colors hover:bg-primary/10"
        >
          {q}
        </button>
      ))}
    </div>
  )
}

@php
    /** @var \Illuminate\Support\Collection $others */
    $writing = $others->where('activity', \App\Models\TicketPresence::WRITING);
    $viewing = $others->where('activity', '!=', \App\Models\TicketPresence::WRITING);
@endphp
<div wire:poll.10s="heartbeat">
    @if ($writing->isNotEmpty())
        <div style="border-radius:.75rem;padding:.6rem 1rem;background:rgb(254 243 199);color:rgb(120 53 15);border:1px solid rgb(252 211 77);font-size:.875rem">
            ✍️ <strong>{{ $writing->map(fn ($p) => $p->user->name)->join(', ', ' und ') }}</strong>
            {{ $writing->count() === 1 ? 'schreibt' : 'schreiben' }} gerade eine Antwort oder Notiz.
        </div>
    @endif
    @if ($viewing->isNotEmpty())
        <div style="margin-top:{{ $writing->isNotEmpty() ? '.5rem' : '0' }};border-radius:.75rem;padding:.5rem 1rem;background:rgb(241 245 249);color:rgb(51 65 85);font-size:.875rem">
            👀 Auch geöffnet von {{ $viewing->map(fn ($p) => $p->user->name)->join(', ', ' und ') }}
        </div>
    @endif
</div>

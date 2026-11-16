@php
    use App\Enums\MessageType;
    use App\Support\HtmlCleaner;
    use App\Support\QuoteStripper;

    /** @var \App\Models\Ticket $ticket */
    $tz = $ticket->tenant->timezone ?: 'Europe/Berlin';
    $timeline = $ticket->messages->map(fn ($m) => ['kind' => 'message', 'at' => $m->created_at, 'item' => $m])
        ->concat($ticket->events->where('type', '!=', 'created')->map(fn ($e) => ['kind' => 'event', 'at' => $e->created_at, 'item' => $e]))
        ->sortBy(fn ($row) => $row['at']?->getTimestamp())
        ->values();
    $userNames = \App\Models\User::query()->pluck('name', 'id');
@endphp

<style>
    .anl-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
    @media (min-width: 1024px) { .anl-grid { grid-template-columns: minmax(0, 1fr) 20rem; } }
    .anl-msg { border-radius: .75rem; border: 1px solid rgb(226 232 240); background: #fff; padding: 1rem 1.25rem; }
    .dark .anl-msg { background: rgb(15 23 42); border-color: rgb(51 65 85); }
    .anl-msg.reply { border-left: 4px solid #4f46e5; }
    .anl-msg.auto { border-left: 4px solid rgb(148 163 184); opacity: .85; }
    .anl-msg.note { background: #fffbeb; border-color: #fcd34d; }
    .dark .anl-msg.note { background: rgb(69 26 3 / .4); border-color: rgb(180 83 9); }
    .anl-head { display: flex; flex-wrap: wrap; gap: .5rem; align-items: baseline; justify-content: space-between; margin-bottom: .5rem; font-size: .875rem; }
    .anl-meta { color: rgb(100 116 139); font-size: .8rem; }
    .anl-body { white-space: pre-wrap; word-break: break-word; font-size: .9rem; line-height: 1.55; }
    .anl-files { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
    .anl-file { display: inline-flex; gap: .35rem; align-items: center; font-size: .8rem; border: 1px solid rgb(203 213 225); border-radius: .5rem; padding: .25rem .6rem; }
    .anl-event { font-size: .8rem; color: rgb(100 116 139); text-align: center; }
    .anl-dl { display: grid; grid-template-columns: auto 1fr; gap: .4rem .75rem; font-size: .875rem; }
    .anl-dl dt { color: rgb(100 116 139); }
    .anl-iframe { width: 100%; min-height: 8rem; border: 1px solid rgb(226 232 240); border-radius: .5rem; background: #fff; margin-top: .5rem; }
    details > summary { cursor: pointer; font-size: .8rem; color: #4f46e5; margin-top: .5rem; }
</style>

<div class="anl-grid">
    <div style="display: flex; flex-direction: column; gap: 1rem;">
        @foreach ($timeline as $row)
            @if ($row['kind'] === 'event')
                @php
                    $e = $row['item'];
                    $who = $e->user?->name ?? 'System';
                    $text = match ($e->type) {
                        'assigned' => $e->data['to'] ?? null
                            ? "{$who} hat das Ticket ".($userNames[$e->data['to']] ?? 'jemandem').' zugewiesen'
                            : "{$who} hat die Zuweisung entfernt",
                        'status' => "{$who}: Status ".($e->data['from'] ?? '–').' → '.($e->data['to'] ?? '–')
                            .(isset($e->data['auto']) ? " (automatisch nach {$e->data['auto']} Tagen)" : '')
                            .(isset($e->data['merged']) ? ' (zusammengeführt)' : ''),
                        'reminder' => 'Erinnerung an den Kunden verschickt',
                        'fields' => "{$who}: ".collect($e->data['changes'] ?? [])->map(fn ($v, $k) => "{$k} → {$v}")->implode(', '),
                        'rule' => 'Regel „'.($e->data['rule'] ?? '–').'“ angewendet'.(isset($e->data['team']) ? ' · Team → '.$e->data['team'] : ''),
                        'merged_into' => "{$who} hat dieses Ticket in ".($e->data['ticket'] ?? '–').' zusammengeführt ('.($e->data['messages'] ?? 0).' Nachricht(en) verschoben)',
                        'merged_from' => "{$who} hat ".($e->data['ticket'] ?? '–').' hierher zusammengeführt ('.($e->data['messages'] ?? 0).' Nachricht(en) übernommen)',
                        'anonymized' => "{$who}: Kontaktdaten auf Wunsch gelöscht (Datenschutz)",
                        'category' => "{$who}: Kategorie ".($e->data['from'] ?? '–').' → '.($e->data['to'] ?? '–'),
                        'priority' => "{$who}: Priorität → ".(\App\Enums\TicketPriority::tryFrom($e->data['to'] ?? '')?->getLabel() ?? '–'),
                        'sla_breach' => 'Frist überschritten: '.(\App\Models\SlaPolicy::METRICS[$e->data['metric'] ?? ''] ?? 'SLA'),
                        default => "{$who}: {$e->type}",
                    };
                @endphp
                <div class="anl-event">{{ $text }} · {{ $e->created_at?->timezone($tz)->format('d.m.Y H:i') }}</div>
            @else
                @php
                    $m = $row['item'];
                    $class = match ($m->type) { MessageType::Reply => 'reply', MessageType::Note => 'note', MessageType::Auto => 'auto', default => 'customer' };
                    $full = (string) $m->body_text;
                    $short = $m->type === MessageType::Customer ? QuoteStripper::strip($full) : $full;
                    $html = $m->body_html;
                    if ($html) {
                        foreach ($m->attachments->whereNotNull('content_id') as $a) {
                            $html = str_replace(HtmlCleaner::CID_PREFIX.rawurlencode($a->content_id), route('attachments.show', $a).'?inline=1', $html);
                        }
                        $csp = "default-src 'none'; img-src ".url('/')." data:; style-src 'unsafe-inline'";
                        $srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="'.e($csp).'"><base target="_blank"><style>body{font-family:system-ui,sans-serif;font-size:14px;margin:12px;color:#0f172a}img{max-width:100%;height:auto}</style></head><body>'.$html.'</body></html>';
                    }
                @endphp
                <article class="anl-msg {{ $class }}">
                    <div class="anl-head">
                        <div>
                            <strong>{{ $m->authorName() }}</strong>
                            @if ($m->type === MessageType::Customer && $m->from_email)
                                <span class="anl-meta">&lt;{{ $m->from_email }}&gt;</span>
                            @endif
                            @if ($m->type === MessageType::Note)
                                <x-filament::badge color="warning" size="sm" style="display:inline-flex">Interne Notiz</x-filament::badge>
                            @endif
                            @if ($m->type === MessageType::Auto)
                                <x-filament::badge color="gray" size="sm" style="display:inline-flex">Automatische Eingangsbestätigung</x-filament::badge>
                            @endif
                        </div>
                        <div class="anl-meta">
                            {{ $m->created_at?->timezone($tz)->format('d.m.Y H:i') }}
                            @if (in_array($m->type, [MessageType::Reply, MessageType::Auto], true))
                                ·
                                @switch($m->delivery_status)
                                    @case('sent') <span style="color:#16a34a">gesendet</span> @break
                                    @case('failed') <span style="color:#dc2626" title="{{ $m->delivery_error }}">Versand fehlgeschlagen – wird wiederholt</span> @break
                                    @default <span>wird gesendet …</span>
                                @endswitch
                            @endif
                        </div>
                    </div>

                    @if ($m->type !== MessageType::Note && (count($m->cc ?? []) > 0))
                        <div class="anl-meta" style="margin-bottom:.4rem">CC: {{ collect($m->cc)->pluck('email')->join(', ') }}</div>
                    @endif

                    <div class="anl-body">{{ $short !== '' ? $short : '(kein Text)' }}</div>

                    @if ($short !== trim($full))
                        <details><summary>Zitierten Text anzeigen</summary><div class="anl-body anl-meta" style="margin-top:.5rem">{{ $full }}</div></details>
                    @endif

                    @if ($html)
                        <details x-data>
                            <summary>Originalansicht (HTML)</summary>
                            <iframe class="anl-iframe" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                    referrerpolicy="no-referrer" srcdoc="{{ $srcdoc }}"
                                    x-on:load="$el.style.height = ($el.contentDocument?.body?.scrollHeight ?? 300) + 30 + 'px'"></iframe>
                        </details>
                    @endif

                    @php $files = $m->attachments->where('inline', false); @endphp
                    @if ($files->isNotEmpty())
                        <div class="anl-files">
                            @foreach ($files as $a)
                                <a class="anl-file" href="{{ route('attachments.show', $a) }}">
                                    <x-filament::icon icon="heroicon-o-paper-clip" style="width:1rem;height:1rem" />
                                    {{ $a->filename }} <span class="anl-meta">({{ $a->humanSize() }})</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endif
        @endforeach
    </div>

    <aside style="display:flex;flex-direction:column;gap:1rem">
        <x-filament::section>
            <x-slot name="heading">Details</x-slot>
            <dl class="anl-dl">
                <dt>Mandant</dt><dd>{{ $ticket->tenant->name }}</dd>
                <dt>Status</dt><dd><x-filament::badge :color="\App\Support\BadgeColor::resolve($ticket->status->color)" style="display:inline-flex">{{ $ticket->status->name }}</x-filament::badge></dd>
                <dt>Kategorie</dt><dd>@if ($ticket->category)<x-filament::badge :color="\App\Support\BadgeColor::resolve($ticket->category->color)" style="display:inline-flex">{{ $ticket->category->name }}</x-filament::badge>@else – @endif</dd>
                <dt>Priorität</dt><dd><x-filament::badge :color="$ticket->priority->getColor()" style="display:inline-flex">{{ $ticket->priority->getLabel() }}</x-filament::badge></dd>
                <dt>Bearbeiter</dt><dd>{{ $ticket->assignee?->name ?? '– niemand –' }}</dd>
                <dt>Team</dt><dd>{{ $ticket->team?->name ?? '–' }}</dd>
                <dt>Eingang</dt><dd>{{ $ticket->channel === 'api' ? 'Anwendung (API)' : 'E-Mail' }}</dd>
                <dt>Postfach</dt><dd>{{ $ticket->mailbox?->email ?? '–' }}</dd>
                <dt>Erstellt</dt><dd>{{ $ticket->created_at?->timezone($tz)->format('d.m.Y H:i') }}</dd>
                @if ($ticket->followUpOf)
                    <dt>Folge von</dt>
                    <dd><a href="{{ route('filament.app.resources.tickets.view', $ticket->followUpOf) }}" style="color:#4f46e5">{{ $ticket->followUpOf->reference() }}</a></dd>
                @endif
            </dl>
        </x-filament::section>

        @php $customFields = \App\Models\CustomField::for((int) $ticket->tenant_id); @endphp
        @if ($customFields->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Felder</x-slot>
                <dl class="anl-dl">
                    @foreach ($customFields as $field)
                        <dt>{{ $field->label }}</dt><dd style="word-break:break-word">{{ $field->display($ticket->fields[$field->key] ?? null) ?? '–' }}</dd>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif

        @php $timeEntries = $ticket->timeEntries()->with('user')->get(); @endphp
        @if ($timeEntries->isNotEmpty())
            <x-filament::section>
                <x-slot name="heading">Zeit: {{ \App\Models\TimeEntry::format((int) $timeEntries->sum('minutes')) }}</x-slot>
                <dl class="anl-dl">
                    @foreach ($timeEntries as $entry)
                        <dt>{{ $entry->worked_on->format('d.m.') }} · {{ $entry->user?->name ?? '–' }}</dt>
                        <dd>{{ $entry->minutes }} Min.@if ($entry->note) · {{ $entry->note }}@endif</dd>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif

        @php $slaRows = \App\Sla\SlaDisplay::rows($ticket); @endphp
        @if ($slaRows !== [])
            <x-filament::section>
                <x-slot name="heading">Fristen (SLA)</x-slot>
                <x-slot name="description">{{ $ticket->slaPolicy?->name }}{{ $ticket->slaPolicy?->all_day ? ' · rund um die Uhr' : ' · in Geschäftszeiten' }}</x-slot>
                <dl class="anl-dl">
                    @foreach ($slaRows as $row)
                        <dt>{{ $row['label'] }}</dt>
                        <dd style="color: {{ ['danger' => '#dc2626', 'warning' => '#d97706', 'success' => '#16a34a'][$row['color']] ?? 'inherit' }}; {{ $row['color'] !== 'gray' ? 'font-weight:600' : '' }}">{{ $row['text'] }}</dd>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif

        @if (! empty($ticket->context))
            @php
                $labels = ['url' => 'Seite', 'app_version' => 'Version', 'browser' => 'Browser', 'os' => 'System', 'device' => 'Gerät',
                    'error_message' => 'Fehler', 'correlation_id' => 'Korrelations-ID', 'occurred_at' => 'Zeitpunkt', 'role' => 'Rolle'];
            @endphp
            <x-filament::section>
                <x-slot name="heading">Technische Details</x-slot>
                <x-slot name="description">Von der Anwendung mitgeschickt, nur intern sichtbar</x-slot>
                <dl class="anl-dl">
                    @foreach ($ticket->context as $key => $value)
                        <dt>{{ $labels[$key] ?? $key }}</dt>
                        <dd style="word-break:break-all; font-family: ui-monospace, monospace; font-size: .8rem">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                    @endforeach
                </dl>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">Anfragende Person</x-slot>
            <dl class="anl-dl">
                <dt>Name</dt><dd>{{ $ticket->contact->name ?? '–' }}</dd>
                <dt>E-Mail</dt><dd style="word-break:break-all">{{ $ticket->contact->email }}</dd>
                @if ($ticket->contact->external_user_id)
                    <dt>Nutzer-ID</dt><dd style="word-break:break-all">{{ $ticket->contact->external_user_id }}</dd>
                @endif
                @if ($ticket->participants->isNotEmpty())
                    <dt>Beteiligt</dt><dd style="word-break:break-all">{{ $ticket->participants->pluck('email')->join(', ') }}</dd>
                @endif
            </dl>
        </x-filament::section>
    </aside>
</div>

<div>
    <div style="font-size:.875rem; font-weight:500; margin-bottom:.375rem;">Vorschau</div>
    @if ($srcdoc)
        <iframe sandbox="allow-same-origin" referrerpolicy="no-referrer" srcdoc="{{ $srcdoc }}"
                style="width:100%; min-height:6rem; border:1px solid rgb(226 232 240); border-radius:.5rem; background:#fff;"
                x-data x-on:load="$el.style.height = (($el.contentDocument?.body?.scrollHeight ?? 120) + 30) + 'px'"></iframe>
        <div style="font-size:.75rem; color:rgb(100 116 139); margin-top:.25rem;">So erscheint die Signatur beim Kunden. Platzhalter sind mit deinem Namen gefüllt.</div>
    @else
        <div style="font-size:.8rem; color:rgb(100 116 139);">Noch keine Signatur hinterlegt.</div>
    @endif
</div>

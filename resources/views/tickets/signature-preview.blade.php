@if ($team)
    <div style="border:1px dashed rgb(203 213 225); border-radius:.5rem; padding:.5rem .75rem;">
        <div style="font-size:.8rem; color:rgb(100 116 139); margin-bottom:.25rem;">
            Signatur „{{ $team->name }}“ wird unter der Nachricht angehängt:
        </div>
        <iframe sandbox="allow-same-origin" referrerpolicy="no-referrer" srcdoc="{{ $srcdoc }}"
                style="width:100%; min-height:5rem; border:0; background:#fff; border-radius:.375rem;"
                x-data x-on:load="$el.style.height = (($el.contentDocument?.body?.scrollHeight ?? 120) + 24) + 'px'"></iframe>
    </div>
@endif

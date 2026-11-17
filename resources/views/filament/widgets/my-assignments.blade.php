<x-filament-widgets::widget class="anliego-my-assignments">
    <style>
        .anliego-assignments-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 640px) { .anliego-assignments-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .anliego-assignments-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    </style>
    <div class="anliego-assignments-grid">
        @foreach ($this->getBlocks() as $block)
            <x-filament::section :heading="$block['heading']" compact>
                @if (count($block['items']))
                    <ul style="display: flex; flex-direction: column; gap: 0.5rem;">
                        @foreach ($block['items'] as $item)
                            <li style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;">
                                <span style="display: flex; align-items: center; gap: 0.5rem; min-width: 0;">
                                    <span aria-hidden="true" style="flex: none; width: 0.6rem; height: 0.6rem; border-radius: 9999px; background: {{ $item['color'] ?? '#9ca3af' }};"></span>
                                    <span style="min-width: 0; overflow-wrap: break-word; hyphens: auto;" lang="de">
                                        <x-filament::link :href="$item['url']" :title="'Offene Tickets: '.$item['label']">
                                            {{ $item['label'] }}
                                        </x-filament::link>
                                        @if ($item['hint'])
                                            <span class="fi-color-gray" style="display: block; font-size: 0.75rem; opacity: 0.7;">{{ $item['hint'] }}</span>
                                        @endif
                                    </span>
                                </span>
                                <span style="flex: none;">
                                    <x-filament::badge :color="$item['open'] ? 'primary' : 'gray'" :tooltip="'offene Tickets'">
                                        {{ $item['open'] }}
                                    </x-filament::badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($block['empty'] !== '')
                    <p style="font-size: 0.875rem; opacity: 0.7;">{{ $block['empty'] }}</p>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>

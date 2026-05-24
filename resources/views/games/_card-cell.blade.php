{{--
  $cell   — array with keys: exists, is_face_up, value, position
  $canSwap — bool: player can place their held card here
  $canFlip — bool: player can flip this face-down card
  Cards use w-full + aspect-[2/3] so size is driven by the grid column width (--cw).
--}}
@if (! $cell['exists'])
    <div class="aspect-[2/3] w-full rounded border border-dashed border-zinc-200 dark:border-zinc-700"></div>

@elseif ($cell['is_face_up'])
    <button
        @if ($canSwap)
            wire:click="placeCard({{ $cell['position'] }})"
            class="flex aspect-[2/3] w-full cursor-pointer flex-col overflow-hidden rounded border-2 border-transparent font-bold transition hover:scale-105 hover:border-accent {{ \App\View\CardColor::fromValue($cell['value']) }}"
        @else
            class="flex aspect-[2/3] w-full cursor-default flex-col overflow-hidden rounded font-bold {{ \App\View\CardColor::fromValue($cell['value']) }}"
        @endif
        type="button"
    >
        <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);">
            <span class="leading-none" style="font-size: clamp(7px, 2.5dvh, 16px);">{{ $cell['value'] }}</span>
        </div>
        <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
        <div class="flex flex-1 items-center justify-center">
            <span class="leading-none" style="font-size: clamp(7px, 2.5dvh, 16px);">{{ $cell['value'] }}</span>
        </div>
    </button>

@else
    <button
        @if ($canSwap)
            wire:click="placeCard({{ $cell['position'] }})"
            class="aspect-[2/3] w-full cursor-pointer overflow-hidden rounded border-2 border-transparent transition hover:scale-105 hover:border-accent"
        @elseif ($canFlip)
            wire:click="flipCard({{ $cell['position'] }})"
            class="aspect-[2/3] w-full cursor-pointer overflow-hidden rounded border-2 border-transparent transition hover:scale-105 hover:border-yellow-400"
        @else
            class="aspect-[2/3] w-full cursor-default overflow-hidden rounded"
        @endif
        type="button"
    >
        <x-card-back class="h-full w-full rounded" />
    </button>
@endif

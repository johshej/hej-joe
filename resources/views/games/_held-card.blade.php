{{--
  $value — int card value
  Uses CSS variable --cw (set on the layout container) for width; aspect-ratio sets height.
--}}
<div class="flex shrink-0 flex-col overflow-hidden rounded border-2 border-accent font-bold {{ \App\View\CardColor::fromValue($value) }}"
     style="width: var(--cw); aspect-ratio: 2/3;">
    <div class="flex flex-1 items-center justify-center" style="transform: rotate(180deg);">
        <span class="leading-none" style="font-size: calc(var(--cw) * 0.42);">{{ $value }}</span>
    </div>
    <div class="mx-auto h-px w-3/4 shrink-0 bg-current/20"></div>
    <div class="flex flex-1 items-center justify-center">
        <span class="leading-none" style="font-size: calc(var(--cw) * 0.42);">{{ $value }}</span>
    </div>
</div>

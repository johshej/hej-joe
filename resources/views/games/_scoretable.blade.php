{{--
  $players      — array of player data
  $roundScores  — array keyed by player id → round → score
  $currentRound — int current round number (scores up to currentRound - 1 are complete)
--}}
@php $completedRounds = $currentRound - 1; @endphp

<table class="w-full text-sm">
    <thead>
        <tr class="border-b border-zinc-200 dark:border-zinc-700">
            <th class="py-1.5 text-left font-medium text-zinc-500">{{ __('Player') }}</th>
            @for ($r = 1; $r <= max($completedRounds, 1); $r++)
                <th class="px-1.5 py-1.5 text-center font-medium text-zinc-500 {{ $r === $completedRounds ? 'text-accent' : '' }}">{{ $r }}</th>
            @endfor
            <th class="py-1.5 text-right font-bold">{{ __('Total') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach (collect($players)->sortBy('total_score') as $player)
            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                <td class="py-1.5 font-medium">
                    @if ($player['is_winner'])
                        <flux:icon name="trophy" class="mr-1 inline size-3.5 text-yellow-500" />
                    @endif
                    {{ $player['name'] }}
                </td>
                @for ($r = 1; $r <= max($completedRounds, 1); $r++)
                    @php $score = $roundScores[$player['id']][$r] ?? null; @endphp
                    <td class="px-1.5 py-1.5 text-center {{ $r === $completedRounds ? 'font-semibold' : '' }}">
                        @if ($score !== null)
                            <span @class(['text-green-600 dark:text-green-400' => $score < 0])>{{ $score > 0 ? '+' : '' }}{{ $score }}</span>
                        @else
                            <span class="text-zinc-300">—</span>
                        @endif
                    </td>
                @endfor
                <td class="py-1.5 text-right font-bold">{{ $player['total_score'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

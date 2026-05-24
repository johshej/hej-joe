@props(['class' => ''])
<svg viewBox="0 0 60 90" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => $class]) }}>
    {{-- Background --}}
    <rect width="60" height="90" fill="#1e293b" rx="3"/>

    {{-- Outer border --}}
    <rect x="3" y="3" width="54" height="84" fill="none" stroke="#334155" stroke-width="1" rx="2"/>

    {{-- Diamond body --}}
    <path d="M30 18 L48 45 L30 72 L12 45 Z" fill="#0f172a" stroke="#3b82f6" stroke-width="1.5" stroke-opacity="0.55"/>

    {{-- Inner diamond --}}
    <path d="M30 27 L42 45 L30 63 L18 45 Z" fill="none" stroke="#3b82f6" stroke-width="1" stroke-opacity="0.3"/>

    {{-- HJ monogram --}}
    <text
        x="30" y="47"
        text-anchor="middle"
        dominant-baseline="middle"
        fill="#60a5fa"
        fill-opacity="0.85"
        font-family="Georgia, 'Times New Roman', serif"
        font-size="11"
        font-weight="bold"
        letter-spacing="2"
    >HJ</text>

    {{-- Corner dots --}}
    <circle cx="7" cy="7" r="1.5" fill="#334155"/>
    <circle cx="53" cy="7" r="1.5" fill="#334155"/>
    <circle cx="7" cy="83" r="1.5" fill="#334155"/>
    <circle cx="53" cy="83" r="1.5" fill="#334155"/>
</svg>

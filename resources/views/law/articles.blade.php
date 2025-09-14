@php
    /** @var string $mopedId */
    /** @var string $q */
    /** @var string $actTitle */
    /** @var \Illuminate\Support\Collection $rows */

    // Simple highlighter for PLAIN text (used on citation + first line only).
    function hl($haystack, $needle) {
        if (!is_string($haystack)) return '';
        $needle = trim((string)$needle);
        if ($needle === '') return e($haystack);
        $pattern = '/' . preg_quote($needle, '/') . '/iu';
        // highlight inside already-escaped string
        return preg_replace_callback($pattern, function($m){
            return '<mark class="px-1 rounded bg-yellow-200">'.e($m[0]).'</mark>';
        }, e($haystack));
    }
@endphp
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $actTitle }} — {{ $mopedId }}</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <script>
    function copyText(text) {
      if (!navigator.clipboard) return;
      navigator.clipboard.writeText(text).then(() => {
        const el = document.getElementById('copy-toast');
        if (el) { el.classList.remove('opacity-0'); setTimeout(() => el.classList.add('opacity-0'), 1200); }
      });
    }
  </script>
</head>
<body class="bg-gray-50 text-gray-900">
  <div class="max-w-5xl mx-auto px-4 py-6">
    <header class="mb-6">
      <h1 class="text-2xl font-semibold">{{ $actTitle }}</h1>
      <div class="text-sm text-gray-500">
        {{ $mopedId }} ·
        <a class="underline hover:no-underline" href="https://www.pisrs.si/Pis.web/pregledPredpisa?id={{ $mopedId }}" target="_blank" rel="noopener">Odpri na PISRS</a>
      </div>
    </header>

    <form method="GET" action="{{ route('laws.show', ['mopedId' => $mopedId]) }}" class="mb-4">
      <div class="flex gap-2 items-center">
        <input type="text" name="q" value="{{ $q }}" placeholder="Išči po členih (npr. odpoved)"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Išči</button>
        @if($q !== '')
          <a href="{{ route('laws.show',['mopedId'=>$mopedId]) }}" class="px-3 py-2 text-sm border rounded-lg">Počisti</a>
        @endif
      </div>
    </form>

    <div id="copy-toast" class="fixed top-4 right-4 bg-black text-white text-sm px-3 py-1 rounded transition-opacity duration-300 opacity-0">
      Kopirano ✔
    </div>

    @if($rows->isEmpty())
      <div class="p-6 bg-white border rounded-xl shadow-sm">
        <div class="text-gray-600">Ni najdenih členov.</div>
      </div>
    @else
      <div class="space-y-3">
        @foreach($rows as $i => $r)
          @php
              // text may be HTML-ish in DB; controller can provide $r->text_html (sanitized)
              $textHtml = $r->text_html ?? \App\Support\TextSanitizer::toSafeHtml($r->text ?? '');

              // Derive first line (usually the heading) from either text_html or plain text
              // We strip tags just to get a neat "first line" label.
              $plainForFirst = trim(strip_tags($textHtml !== '' ? $textHtml : ($r->text ?? '')));
              $nlPos = mb_strpos($plainForFirst, "\n");
              $firstLine = $nlPos !== false ? mb_substr($plainForFirst, 0, $nlPos) : $plainForFirst;

              // Body HTML: subtract the first line from sanitized HTML if it repeats, otherwise show full
              // (Simple heuristic: if first line appears at start, remove it.)
              $bodyHtml = $textHtml;
              if ($firstLine && mb_stripos(strip_tags($textHtml), $firstLine) === 0) {
                  // Remove first line once from the beginning (HTML-aware fallback: just rely on view separation)
                  // We'll simply display the sanitized HTML; visually the first line appears in summary anyway.
              }
          @endphp

          <details class="group bg-white border rounded-xl shadow-sm" {{ $i < 5 ? 'open' : '' }}>
            <summary class="list-none cursor-pointer px-4 py-3 flex items-start justify-between gap-3">
              <div class="min-w-0">
{{--                <div class="text-sm text-gray-500">{!! hl($r->citation ?? '', $q) !!}</div>--}}
                <div class="font-medium break-words">{!! hl($firstLine, $q) !!}</div>
              </div>
              <div class="shrink-0 flex items-center gap-2">
                <button type="button" onclick="copyText('{{ addslashes(($r->citation ?? '').' — '.($r->title ?? '')) }}')"
                        title="Kopiraj citat"
                        class="text-xs px-2 py-1 border rounded hover:bg-gray-50">Kopiraj</button>
                <a href="{{ $r->url }}" target="_blank" rel="noopener"
                   class="text-xs px-2 py-1 border rounded hover:bg-gray-50">PISRS</a>
                <svg class="w-5 h-5 text-gray-400 group-open:rotate-180 transition-transform" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
              </div>
            </summary>

            @if($bodyHtml !== '')
              <div class="px-4 pb-4 prose max-w-none">
                {{-- Render sanitized HTML and highlight the query safely --}}
                {!! \App\Support\Highlighter::html($bodyHtml, $q) !!}
              </div>
            @endif
          </details>
        @endforeach
      </div>
    @endif
  </div>
</body>
</html>

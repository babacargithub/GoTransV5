<x-ambassador-program-document.layout :title="$documentTitle.' — Globe One Transport'">
    <x-slot:head>
        <style>
            /* Copy protection: nothing can be selected, dragged or long-press-saved. */
            body, .document, .document * {
                -webkit-user-select: none; user-select: none;
                -webkit-touch-callout: none;
                -webkit-user-drag: none;
            }

            /*
             * Reading window: the document is blurred by default (so it stays unreadable when JavaScript is off),
             * and a sharp copy of it, masked to a band of a few lines, is laid on top at a fixed height of the screen.
             * Scrolling moves the text through the band: what is being read is sharp, everything else is blurred.
             */
            .reader { position: relative; margin: calc(45vh - 110px) 0 55vh; }
            .document--blurred { filter: blur(7px); }
            .document--sharp {
                position: absolute; top: 0; left: 0; right: 0;
                pointer-events: none;
                --reading-window-top: -200px; --reading-window-bottom: -100px; --reading-window-fade: 14px;
                -webkit-mask-image: linear-gradient(to bottom, transparent var(--reading-window-top), #000 calc(var(--reading-window-top) + var(--reading-window-fade)), #000 calc(var(--reading-window-bottom) - var(--reading-window-fade)), transparent var(--reading-window-bottom));
                mask-image: linear-gradient(to bottom, transparent var(--reading-window-top), #000 calc(var(--reading-window-top) + var(--reading-window-fade)), #000 calc(var(--reading-window-bottom) - var(--reading-window-fade)), transparent var(--reading-window-bottom));
            }
            .reader-notice { position: fixed; left: 0; right: 0; bottom: 0; padding: .6rem 1rem; text-align: center; font-size: .85rem; background: #0f172a; color: #fff; }
        </style>
    </x-slot:head>

    <div class="reader" id="reader">
        <article class="document document--blurred" id="document-blurred">{!! $documentHtml !!}</article>
    </div>
    <noscript><div class="reader-notice">Activez JavaScript pour lire ce document.</div></noscript>

    <x-slot:scripts>
        <script>
            (function () {
                var blockEvent = function (event) { event.preventDefault(); };

                ['copy', 'cut', 'contextmenu', 'selectstart', 'dragstart'].forEach(function (eventName) {
                    document.addEventListener(eventName, blockEvent);
                });

                // Ctrl/Cmd + C, X, A (select all), S (save), P (print), U (view source)
                document.addEventListener('keydown', function (event) {
                    var blockedShortcutKeys = ['c', 'x', 'a', 's', 'p', 'u'];
                    if ((event.ctrlKey || event.metaKey) && blockedShortcutKeys.indexOf(event.key.toLowerCase()) !== -1) {
                        event.preventDefault();
                    }
                });

                // A copy that slipped through still puts nothing useful on the clipboard.
                document.addEventListener('copy', function (event) {
                    if (event.clipboardData) { event.clipboardData.setData('text/plain', ''); }
                });

                // Reading window
                var linesVisibleAtOnce = 7;
                var readingLineAsShareOfViewportHeight = 0.45;
                var reader = document.getElementById('reader');
                var blurredDocument = document.getElementById('document-blurred');

                var sharpDocument = blurredDocument.cloneNode(true);
                sharpDocument.removeAttribute('id');
                sharpDocument.className = 'document document--sharp';
                sharpDocument.setAttribute('aria-hidden', 'true');
                reader.appendChild(sharpDocument);

                var isUpdateScheduled = false;

                function moveReadingWindow() {
                    isUpdateScheduled = false;
                    var lineHeightInPixels = parseFloat(getComputedStyle(blurredDocument).lineHeight) || 27;
                    var windowHeightInPixels = lineHeightInPixels * linesVisibleAtOnce;
                    var readingLineInViewport = window.innerHeight * readingLineAsShareOfViewportHeight;
                    var windowTopInReader = readingLineInViewport - windowHeightInPixels / 2 - reader.getBoundingClientRect().top;

                    sharpDocument.style.setProperty('--reading-window-top', windowTopInReader + 'px');
                    sharpDocument.style.setProperty('--reading-window-bottom', (windowTopInReader + windowHeightInPixels) + 'px');
                }

                function scheduleReadingWindowMove() {
                    if (!isUpdateScheduled) {
                        isUpdateScheduled = true;
                        window.requestAnimationFrame(moveReadingWindow);
                    }
                }

                window.addEventListener('scroll', scheduleReadingWindowMove, { passive: true });
                window.addEventListener('resize', scheduleReadingWindowMove);
                moveReadingWindow();
            })();
        </script>
    </x-slot:scripts>
</x-ambassador-program-document.layout>

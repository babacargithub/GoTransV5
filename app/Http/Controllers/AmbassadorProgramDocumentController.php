<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Serves the ambassador program document through a one-time URL. The URL is a temporary signed route (tamper-proof
 * and time-limited); the "already opened" flag lives in the cache. The first open shows the document, any later
 * open (reload included), a tampered URL or an expired one shows "Document expiré".
 */
class AmbassadorProgramDocumentController extends Controller
{
    /**
     * Link-preview crawlers (WhatsApp, Telegram, Facebook...) fetch a URL the moment it is pasted in a chat.
     * They must never consume the link, or the candidate would find it already expired.
     */
    private const LINK_PREVIEW_USER_AGENT_PATTERN = '/bot|crawler|spider|preview|facebookexternalhit|whatsapp|telegram|slack|discord|linkedin|embedly|curl|wget/i';

    public function show(Request $request, string $link): Response
    {
        if ($request->isMethod('HEAD') || preg_match(self::LINK_PREVIEW_USER_AGENT_PATTERN, (string) $request->userAgent())) {
            return $this->withoutCaching(response(view('ambassador-program-document.preview-placeholder')));
        }

        if (! $request->hasValidSignature()) {
            return $this->withoutCaching(response(view('ambassador-program-document.expired'), 410));
        }

        $documentPath = config('app.ambassador_program_document_path');

        // Checked before consuming: a missing file must not burn a link.
        if (! is_file($documentPath)) {
            return $this->withoutCaching(response(view('ambassador-program-document.expired'), 503));
        }

        // Cache::add is atomic: when two requests race on the same link exactly one of them gets `true`.
        // The flag only has to outlive the signature, which is refused after its expiry anyway.
        $secondsUntilLinkExpires = max(1, (int) $request->query('expires') - now()->timestamp);
        $linkWasConsumed = Cache::add("ambassador-program-document-link-used:{$link}", true, $secondsUntilLinkExpires);

        if (! $linkWasConsumed) {
            return $this->withoutCaching(response(view('ambassador-program-document.expired'), 410));
        }

        $documentHtml = Str::markdown(file_get_contents($documentPath), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->withoutCaching(response(view('ambassador-program-document.show', [
            'documentHtml' => $documentHtml,
        ])));
    }

    private function withoutCaching(Response $response): Response
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}

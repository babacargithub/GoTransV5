import './bootstrap';

// Ambassador referral: remember the `?ref=` code of the shared link for 30 days, so it survives
// browsing from the landing page to the booking form (see App\Livewire\Website\StudentBooking).
const AMBASSADOR_REF_STORAGE_KEY = 'ambassador_ref';
const AMBASSADOR_REF_LIFETIME_MILLISECONDS = 30 * 24 * 60 * 60 * 1000;

try {
    const referralCodeFromUrl = new URLSearchParams(window.location.search).get('ref');

    if (referralCodeFromUrl && /^[a-zA-Z0-9_-]{1,50}$/.test(referralCodeFromUrl)) {
        localStorage.setItem(AMBASSADOR_REF_STORAGE_KEY, JSON.stringify({
            code: referralCodeFromUrl.toLowerCase(),
            expiresAt: Date.now() + AMBASSADOR_REF_LIFETIME_MILLISECONDS,
        }));
    }
} catch (storageUnavailable) {
    // Private mode or blocked storage: the code in the URL still works for a direct landing.
}

window.rememberedAmbassadorCode = () => {
    try {
        const remembered = JSON.parse(localStorage.getItem(AMBASSADOR_REF_STORAGE_KEY));

        return remembered && remembered.expiresAt > Date.now() ? remembered.code : null;
    } catch (storageUnavailable) {
        return null;
    }
};

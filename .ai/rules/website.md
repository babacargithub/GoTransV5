---
paths:
  - 'app/Livewire/Website/**'
---

# Website

## Public website funnel = full-page Livewire + plain Tailwind, reuse the mobile backend
Interactive public-website pages (booking funnel, etc.) are class-based full-page Livewire components in app/Livewire/Website/, views in resources/views/livewire/website/. Use plain Tailwind + brand-* tokens, NOT Flux (matches the hand-rolled resources/views/website/** pages). Per-page SEO via render()'s `->layout('components.layouts.website', ['title'=>, 'description'=>, 'robots'=>])`; transactional pages (forms, "my booking") are robots=noindex.

Reuse the untouched mobile booking backend, do not re-implement business rules:
- plain-Request controller methods (e.g. MobileAppController@generatePaymentUrlForMultipleBooking): back-office style — request()->merge([...]) then app(MobileAppController::class)->method(request()); catch ValidationException + \Throwable.
- FormRequest-typed methods (MobileAppController@saveMultipleBookings takes MobileMultipleBookingRequest): build it manually — MobileMultipleBookingRequest::create('/', 'POST', $payload), setContainer(app()), setRedirector(app('redirect')), a bound Illuminate\Routing\Route as the route resolver carrying the route param (e.g. `depart`), then ->validateResolved() (its failedValidation throws HttpResponseException JSON 422 — catch it). ALWAYS include bus_id in the booking payload (default $depart->getBusForBooking()?->id) — normalizeBookings() reads it straight from validated().

bookings.uuid (nullable, indexed) is the public non-enumerable key of a booking group; all bookings of a group share one uuid. Live form validation ports GolobOneTransportMobile/src/utils/phone_number_validator.js (phone `^7[7680][0-9]{7}$`, validateFullName split). Non-refundable warning text = app_params.data.warning_message_before_booking ({ticketPrice}/{payment_method} placeholders).

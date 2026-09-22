<?php

namespace App\Http\Resources;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResponseResource extends JsonResource
{
    public string $paymentMethod = '';

    public array $data = [];

    public function __construct(Response $response, string $paymentMethod)
    {
        parent::__construct($response);
        $this->paymentMethod = $paymentMethod;
        $this->data = [
            'status' => $response->status(),
            'message' => $response->json()['message'] ?? null,
            'paymentResponse' => $response->json(),
        ];
    }

    public function toArray(Request $request): array
    {
        return $this->data;

    }

    public function waveLaunchUrl(): ?string
    {
        return $this->data['paymentResponse']['wave_launch_url'] ?? null;
    }

    public function isOK(): bool
    {
        return isset($this->data['status']) && $this->data['status'] == 200;

    }

    /**
     * The human-readable reason the payment gateway rejected the request, if it gave one.
     *
     * Wave answers with a top-level `message` (and sometimes a `details` array of field errors);
     * Orange Money (Orange Sonatel eWallet API) answers RFC-7807 style with `detail` / `title`.
     * This mirrors the mobile app's PaymentResponseHandler.getOmPaymentError(), which reads
     * `paymentResponse.detail`, but also covers the Wave shape and the field-level `details`.
     */
    public function paymentGatewayErrorMessage(): ?string
    {
        $gatewayResponseBody = $this->data['paymentResponse'] ?? null;

        if (is_array($gatewayResponseBody)) {
            $nestedFieldErrorMessages = collect($gatewayResponseBody['details'] ?? [])
                ->pluck('message')
                ->filter()
                ->implode(' ');

            $gatewayErrorMessage = $gatewayResponseBody['detail']
                ?? $gatewayResponseBody['message']
                ?? $gatewayResponseBody['title']
                ?? ($nestedFieldErrorMessages !== '' ? $nestedFieldErrorMessages : null);

            if (filled($gatewayErrorMessage)) {
                return (string) $gatewayErrorMessage;
            }
        }

        return $this->data['message'] ?? null;
    }
}

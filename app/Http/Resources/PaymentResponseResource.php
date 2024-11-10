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
        'message' => $response->json()['message']??null,
        'paymentResponse' => $response->json(),
            ];
    }

    public function toArray(Request $request): array
    {
        return $this->data;

    }

    public function waveLaunchUrl(): ?string
    {
        return $this->data['paymentResponse']['wave_launch_url'] ??null;
    }

    public function isOK(): bool
    {
        return isset($this->data['status']) && $this->data['status'] == 200;

    }




}

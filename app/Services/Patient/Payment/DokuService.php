<?php

namespace App\Services\Patient\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DokuService
{
    private const CHECKOUT_TARGET = '/checkout/v1/payment';

    protected string $baseUrl;
    protected string $clientId;
    protected string $secretKey;
    protected int $paymentDueDate;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Config::get('doku.base_url', 'https://api.doku.com'), '/');
        $this->clientId = trim((string) Config::get('doku.client_id', ''));
        $this->secretKey = trim((string) Config::get('doku.secret_key', ''));
        $this->paymentDueDate = max(1, (int) Config::get('doku.payment_due_date', 60));
    }

    /**
     * Check whether DOKU credentials are configured in environment.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== ''
            && $this->clientId !== ''
            && $this->secretKey !== '';
    }

    /**
     * Generate Base64(SHA-256) digest of raw JSON request/notification body.
     */
    public function generateDigest(string $jsonBody): string
    {
        return base64_encode(hash('sha256', $jsonBody, true));
    }

    /**
     * Generate DOKU HMACSHA256 signature header value from component signature.
     *
     * Component Signature (separated by \n, no trailing newline):
     * Client-Id:<clientId>
     * Request-Id:<requestId>
     * Request-Timestamp:<requestTimestamp>
     * Request-Target:<requestTarget>
     * Digest:<digest>
     */
    public function generateSignature(
        string $clientId,
        string $requestId,
        string $requestTimestamp,
        string $requestTarget,
        string $digest
    ): string {
        $componentSignature = implode("\n", [
            'Client-Id:' . $clientId,
            'Request-Id:' . $requestId,
            'Request-Timestamp:' . $requestTimestamp,
            'Request-Target:' . $requestTarget,
            'Digest:' . $digest,
        ]);

        return 'HMACSHA256=' . base64_encode(
            hash_hmac('sha256', $componentSignature, $this->secretKey, true)
        );
    }

    /**
     * Build required DOKU authentication headers for an API call.
     *
     * @return array<string, string>
     */
    public function buildAuthHeaders(
        string $requestTarget,
        string $jsonBody,
        ?string $requestId = null,
        ?string $requestTimestamp = null
    ): array {
        $reqId = $requestId ?: (string) Str::uuid();
        $reqTimestamp = $requestTimestamp ?: gmdate('Y-m-d\TH:i:s\Z');
        $digest = $this->generateDigest($jsonBody);
        $signature = $this->generateSignature(
            $this->clientId,
            $reqId,
            $reqTimestamp,
            $requestTarget,
            $digest
        );

        return [
            'Client-Id' => $this->clientId,
            'Request-Id' => $reqId,
            'Request-Timestamp' => $reqTimestamp,
            'Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Initiate a DOKU Checkout payment session (`POST /checkout/v1/payment`).
     *
     * Intentionally omits `payment_method_types` so available payment channels
     * automatically follow merchant configuration in DOKU Dashboard.
     *
     * @param  array<string, mixed>  $orderData
     * @param  array<string, mixed>  $customerData
     * @return array<string, mixed>
     */
    public function createCheckoutPayment(array $orderData, array $customerData = []): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'status' => 503,
                'message' => 'Kredensial DOKU (DOKU_CLIENT_ID / DOKU_SECRET_KEY) belum dikonfigurasi di .env.',
            ];
        }

        $frontendBase = rtrim(
            (string) (Config::get('doku.callback_url') ?: env('FRONTEND_URL', Config::get('app.url', 'https://aestheticpondokindah.com'))),
            '/'
        );
        $cancelBase = rtrim(
            (string) (Config::get('doku.callback_url_cancel') ?: env('FRONTEND_URL', Config::get('app.url', 'https://aestheticpondokindah.com'))),
            '/'
        );

        $callbackUrl = (string) ($orderData['callback_url'] ?? (Config::get('doku.callback_url') ?: ($frontendBase . '/membership')));
        $callbackUrlCancel = (string) ($orderData['callback_url_cancel'] ?? (Config::get('doku.callback_url_cancel') ?: ($cancelBase . '/membership/upgrade')));

        $orderPayload = [
            'amount' => (int) round((float) $orderData['amount']),
            'invoice_number' => (string) $orderData['invoice_number'],
            'currency' => 'IDR',
            'callback_url' => $callbackUrl,
            'callback_url_cancel' => $callbackUrlCancel,
        ];

        if (!empty($orderData['line_items']) && is_array($orderData['line_items'])) {
            $items = [];
            foreach ($orderData['line_items'] as $item) {
                $items[] = [
                    'id' => (string) ($item['id'] ?? 'item'),
                    'name' => substr((string) ($item['name'] ?? 'Pembayaran Layanan'), 0, 255),
                    'price' => (int) round((float) ($item['price'] ?? $orderPayload['amount'])),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ];
            }
            if ($items !== []) {
                $orderPayload['line_items'] = $items;
            }
        }

        $payload = [
            'order' => $orderPayload,
            'payment' => [
                'payment_due_date' => $this->paymentDueDate,
            ],
        ];

        $customerPayload = $this->sanitizeCustomerPayload($customerData);
        if ($customerPayload !== []) {
            $payload['customer'] = $customerPayload;
        }

        $jsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($jsonBody)) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Gagal menyusun payload JSON untuk DOKU.',
            ];
        }

        $headers = $this->buildAuthHeaders(self::CHECKOUT_TARGET, $jsonBody);
        $endpoint = $this->baseUrl . self::CHECKOUT_TARGET;

        try {
            $response = Http::timeout(30)
                ->withHeaders($headers)
                ->withBody($jsonBody, 'application/json')
                ->post($endpoint);

            $body = $response->json();
            if (!is_array($body)) {
                $body = [];
            }

            $paymentUrl = data_get($body, 'response.payment.url') ?? data_get($body, 'payment.url');
            $tokenId = data_get($body, 'response.payment.token_id') ?? data_get($body, 'payment.token_id');
            $expiredDate = data_get($body, 'response.payment.expired_date') ?? data_get($body, 'payment.expired_date');

            if ($response->successful() && is_string($paymentUrl) && $paymentUrl !== '') {
                Log::info('DOKU Checkout payment session created', [
                    'invoice_number' => $orderPayload['invoice_number'],
                    'amount' => $orderPayload['amount'],
                    'request_id' => $headers['Request-Id'],
                ]);

                return [
                    'success' => true,
                    'payment_url' => $paymentUrl,
                    'token_id' => $tokenId,
                    'expired_date' => $expiredDate,
                    'invoice_number' => $orderPayload['invoice_number'],
                    'request_id' => $headers['Request-Id'],
                    'raw' => $body,
                ];
            }

            $errorMessage = data_get($body, 'error.message')
                ?? data_get($body, 'message.0')
                ?? data_get($body, 'message')
                ?? 'DOKU API mengembalikan status HTTP ' . $response->status();

            if (is_array($errorMessage)) {
                $errorMessage = json_encode($errorMessage);
            }

            Log::warning('DOKU Checkout payment request failed', [
                'invoice_number' => $orderPayload['invoice_number'],
                'http_status' => $response->status(),
                'error' => $errorMessage,
                'request_id' => $headers['Request-Id'],
            ]);

            return [
                'success' => false,
                'status' => $response->status(),
                'message' => (string) $errorMessage,
                'raw' => $body,
            ];
        } catch (\Throwable $e) {
            Log::error('DOKU Checkout HTTP exception: ' . $e->getMessage(), [
                'invoice_number' => $orderPayload['invoice_number'],
            ]);

            return [
                'success' => false,
                'status' => 502,
                'message' => 'Tidak dapat terhubung ke gateway DOKU: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify incoming DOKU webhook signature using DOKU_SECRET_KEY.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $clientId = trim((string) $request->header('Client-Id', ''));
        $requestId = trim((string) $request->header('Request-Id', ''));
        $requestTimestamp = trim((string) $request->header('Request-Timestamp', ''));
        $receivedSignature = trim((string) $request->header('Signature', ''));

        if ($clientId === '' || $requestId === '' || $requestTimestamp === '' || $receivedSignature === '') {
            return false;
        }

        if (!hash_equals($this->clientId, $clientId)) {
            return false;
        }

        $rawBody = (string) $request->getContent();
        $digest = $this->generateDigest($rawBody);

        $candidateTargets = array_values(array_unique(array_filter([
            '/' . ltrim((string) $request->getPathInfo(), '/'),
            '/' . ltrim((string) $request->path(), '/'),
            '/api/payment/doku-webhook',
        ])));

        foreach ($candidateTargets as $target) {
            $expected = $this->generateSignature(
                $clientId,
                $requestId,
                $requestTimestamp,
                $target,
                $digest
            );

            if (hash_equals($expected, $receivedSignature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter & normalize optional customer attributes for DOKU Checkout payload.
     *
     * @param  array<string, mixed>  $customerData
     * @return array<string, string>
     */
    private function sanitizeCustomerPayload(array $customerData): array
    {
        $customer = [];

        if (!empty($customerData['id'])) {
            $customer['id'] = substr((string) $customerData['id'], 0, 64);
        }
        if (!empty($customerData['name'])) {
            $customer['name'] = substr(trim((string) $customerData['name']), 0, 255);
        }
        if (!empty($customerData['email']) && filter_var($customerData['email'], FILTER_VALIDATE_EMAIL)) {
            $customer['email'] = (string) $customerData['email'];
        }
        if (!empty($customerData['phone'])) {
            $digits = preg_replace('/[^\d]/', '', (string) $customerData['phone']);
            if (is_string($digits) && $digits !== '') {
                $customer['phone'] = substr($digits, 0, 20);
            }
        }

        return $customer;
    }
}

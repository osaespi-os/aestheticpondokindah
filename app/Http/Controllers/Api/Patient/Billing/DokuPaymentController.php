<?php

namespace App\Http\Controllers\Api\Patient\Billing;

use App\Events\PaymentSettled;
use App\Http\Controllers\Controller;
use App\Models\Patient\Billing\Invoice;
use App\Models\Patient\Billing\Payment;
use App\Models\Patient\Membership\MembershipTransaction;
use App\Services\Patient\Membership\MembershipService;
use App\Services\Patient\Payment\DokuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DokuPaymentController extends Controller
{
    private const LEVEL_ORDER = [
        'bronze' => 0,
        'gold' => 1,
        'platinum' => 2,
    ];

    private const UPGRADE_FEES = [
        'gold' => 499000,
        'platinum' => 1500000,
    ];

    private const BONUS_POINTS = [
        'gold' => 100,
        'platinum' => 300,
    ];

    public function __construct(
        private readonly DokuService $dokuService,
        private readonly MembershipService $membershipService
    ) {
    }

    /**
     * Initiate a DOKU Checkout payment session (`POST /api/payment/doku/create`).
     * Supports existing Invoice (`invoice_id`), MembershipTransaction (`transaction_id`),
     * or membership upgrade tier (`target_level`).
     */
    public function createPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_level' => ['nullable', 'in:gold,platinum'],
            'transaction_id' => ['nullable', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
        ]);

        if (
            empty($validated['invoice_id'])
            && empty($validated['transaction_id'])
            && empty($validated['target_level'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Parameter invoice_id, transaction_id, atau target_level wajib diisi.',
            ], 422);
        }

        if (!$this->dokuService->isConfigured()) {
            return response()->json([
                'success' => false,
                'code' => 'DOKU_NOT_CONFIGURED',
                'message' => 'Payment gateway DOKU belum dikonfigurasi.',
            ], 503);
        }

        $user = $request->user();

        if (!empty($validated['invoice_id'])) {
            return $this->createInvoiceCheckout($user, (int) $validated['invoice_id']);
        }

        return $this->createMembershipCheckout($user, $validated);
    }

    /**
     * Handle DOKU HTTP Notification webhook (`POST /api/payment/doku-webhook`).
     */
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->dokuService->verifyWebhookSignature($request)) {
            Log::warning('DOKU webhook signature verification failed', [
                'client_id' => $request->header('Client-Id'),
                'request_id' => $request->header('Request-Id'),
                'path' => $request->getPathInfo(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid DOKU signature',
            ], 401);
        }

        $payload = $request->json()->all();
        if (!is_array($payload) || $payload === []) {
            $payload = $request->all();
        }

        $invoiceNumber = trim((string) data_get($payload, 'order.invoice_number', ''));
        $transactionStatus = strtoupper(trim((string) data_get($payload, 'transaction.status', '')));
        $channelId = data_get($payload, 'channel.id')
            ?? data_get($payload, 'payment.payment_method')
            ?? data_get($payload, 'service.id');

        Log::info('DOKU webhook received & verified', [
            'invoice_number' => $invoiceNumber,
            'status' => $transactionStatus,
            'channel' => $channelId,
        ]);

        if ($invoiceNumber === '') {
            return response()->json(['status' => 'OK'], 200);
        }

        if ($transactionStatus === 'SUCCESS') {
            $this->settleSuccessfulOrder($invoiceNumber, $channelId, $payload);
        } elseif (in_array($transactionStatus, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {
            $this->recordNonSuccessWebhook($invoiceNumber, $transactionStatus, $channelId, $payload);
        }

        return response()->json(['status' => 'OK'], 200);
    }

    private function createInvoiceCheckout($user, int $invoiceId): JsonResponse
    {
        $invoice = Invoice::find($invoiceId);
        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak ditemukan.',
            ], 404);
        }

        if ((int) $invoice->user_id !== (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke tagihan invoice ini.',
            ], 403);
        }

        if ($invoice->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Invoice sudah berstatus lunas.',
            ], 422);
        }

        $dokuResponse = $this->dokuService->createCheckoutPayment(
            [
                'amount' => (int) round((float) $invoice->amount),
                'invoice_number' => $invoice->invoice_number,
                'line_items' => [
                    [
                        'id' => 'INV-' . $invoice->id,
                        'name' => $invoice->description ?: ('Pembayaran Invoice ' . $invoice->invoice_number),
                        'price' => (int) round((float) $invoice->amount),
                        'quantity' => 1,
                    ],
                ],
            ],
            [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->whatsapp ?? '',
            ]
        );

        if (!$dokuResponse['success']) {
            return response()->json([
                'success' => false,
                'message' => $dokuResponse['message'] ?? 'Gagal membuat sesi pembayaran DOKU.',
            ], $dokuResponse['status'] >= 400 && $dokuResponse['status'] < 600 ? $dokuResponse['status'] : 502);
        }

        $tokenId = !empty($dokuResponse['token_id'])
            ? (string) $dokuResponse['token_id']
            : ('DOKU-' . $invoice->invoice_number . '-' . strtoupper(Str::random(4)));

        $payment = Payment::updateOrCreate(
            [
                'invoice_id' => $invoice->id,
                'gateway_name' => 'doku',
                'status' => 'pending',
            ],
            [
                'payment_number' => 'PAY-DOKU-' . date('YmdHis') . '-' . strtoupper(Str::random(4)),
                'user_id' => $user->id,
                'gateway_reference' => $tokenId,
                'amount' => $invoice->amount,
                'payment_method' => 'doku_checkout',
                'gateway_response' => [
                    'gateway_name' => 'doku',
                    'invoice_number' => $invoice->invoice_number,
                    'token_id' => $dokuResponse['token_id'] ?? null,
                    'payment_url' => $dokuResponse['payment_url'],
                    'expired_date' => $dokuResponse['expired_date'] ?? null,
                    'raw' => $dokuResponse['raw'] ?? null,
                ],
            ]
        );

        $invoiceDetails = is_array($invoice->payment_details) ? $invoice->payment_details : [];
        $invoice->payment_details = array_merge($invoiceDetails, [
            'gateway_name' => 'doku',
            'payment_url' => $dokuResponse['payment_url'],
            'token_id' => $dokuResponse['token_id'] ?? null,
            'expired_date' => $dokuResponse['expired_date'] ?? null,
        ]);
        $invoice->save();

        return response()->json([
            'success' => true,
            'message' => 'Tautan pembayaran DOKU berhasil dibuat.',
            'data' => [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->invoice_number,
                'amount' => (int) round((float) $invoice->amount),
                'payment_url' => $dokuResponse['payment_url'],
                'expired_date' => $dokuResponse['expired_date'] ?? null,
            ],
        ]);
    }

    private function createMembershipCheckout($user, array $validated): JsonResponse
    {
        $currentLevel = array_key_exists($user->membership_level, self::LEVEL_ORDER)
            ? $user->membership_level
            : 'bronze';

        DB::beginTransaction();
        try {
            if (!empty($validated['transaction_id'])) {
                $transaction = $user->membershipTransactions()
                    ->where('id', (int) $validated['transaction_id'])
                    ->where('transaction_type', 'upgrade')
                    ->lockForUpdate()
                    ->first();

                if (!$transaction) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Transaksi upgrade tidak ditemukan.',
                    ], 404);
                }

                if ($transaction->status === 'completed') {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Transaksi upgrade ini sudah lunas.',
                    ], 422);
                }

                $targetLevel = $transaction->metadata['target_level'] ?? ($validated['target_level'] ?? 'gold');
            } else {
                $targetLevel = (string) $validated['target_level'];

                if ($targetLevel === $currentLevel) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda sudah berada di tingkat membership ini.',
                    ], 400);
                }

                if (self::LEVEL_ORDER[$targetLevel] < self::LEVEL_ORDER[$currentLevel]) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Tidak bisa downgrade ke tingkat membership yang lebih rendah.',
                    ], 400);
                }

                // Reuse any existing pending upgrade request for the same tier so repeated clicks
                // do not create duplicate pending records.
                $transaction = $user->membershipTransactions()
                    ->where('transaction_type', 'upgrade')
                    ->where('status', 'pending')
                    ->where(function ($q) use ($targetLevel) {
                        $q->where('metadata->target_level', $targetLevel)
                          ->orWhere('description', 'like', "%{$targetLevel}%");
                    })
                    ->latest()
                    ->lockForUpdate()
                    ->first();

                if (!$transaction) {
                    $fee = self::UPGRADE_FEES[$targetLevel] ?? 0;
                    $transaction = $user->membershipTransactions()->create([
                        'amount' => $fee,
                        'transaction_type' => 'upgrade',
                        'description' => "Upgrade membership ke {$targetLevel}",
                        'status' => 'pending',
                        'metadata' => [
                            'target_level' => $targetLevel,
                            'current_level' => $currentLevel,
                            'payment_gateway' => 'doku',
                        ],
                    ]);
                }
            }

            $amount = (int) round((float) $transaction->amount);
            if ($amount <= 0) {
                $amount = self::UPGRADE_FEES[$targetLevel] ?? 499000;
            }

            $invoiceNumber = 'UPG-' . $transaction->id . '-' . strtoupper(Str::random(6));
            $tierTitle = ucfirst($targetLevel);

            $dokuResponse = $this->dokuService->createCheckoutPayment(
                [
                    'amount' => $amount,
                    'invoice_number' => $invoiceNumber,
                    'line_items' => [
                        [
                            'id' => 'upgrade_' . $targetLevel,
                            'name' => "Upgrade Membership - {$tierTitle} (1 Tahun)",
                            'price' => $amount,
                            'quantity' => 1,
                        ],
                    ],
                ],
                [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->whatsapp ?? '',
                ]
            );

            if (!$dokuResponse['success']) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => $dokuResponse['message'] ?? 'Gagal membuat sesi pembayaran DOKU.',
                ], $dokuResponse['status'] >= 400 && $dokuResponse['status'] < 600 ? $dokuResponse['status'] : 502);
            }

            $existingMeta = is_array($transaction->metadata) ? $transaction->metadata : [];
            $transaction->update([
                'metadata' => array_merge($existingMeta, [
                    'target_level' => $targetLevel,
                    'current_level' => $currentLevel,
                    'order_id' => $invoiceNumber,
                    'doku_invoice_number' => $invoiceNumber,
                    'doku_token_id' => $dokuResponse['token_id'] ?? null,
                    'doku_expired_date' => $dokuResponse['expired_date'] ?? null,
                    'payment_url' => $dokuResponse['payment_url'],
                    'payment_gateway' => 'doku',
                ]),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Silakan lanjutkan pembayaran melalui halaman DOKU.',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'order_id' => $invoiceNumber,
                    'amount' => $amount,
                    'amount_formatted' => 'Rp ' . number_format($amount, 0, ',', '.'),
                    'target_level' => $targetLevel,
                    'payment_url' => $dokuResponse['payment_url'],
                    'expired_date' => $dokuResponse['expired_date'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to create DOKU membership checkout: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses transaksi pembayaran DOKU.',
            ], 500);
        }
    }

    private function settleSuccessfulOrder(string $invoiceNumber, mixed $channelId, array $payload): void
    {
        $settledInvoicePayment = null;

        DB::transaction(function () use ($invoiceNumber, $channelId, $payload, &$settledInvoicePayment): void {
            // 1. Check standard billing Invoice / Payment first
            $invoice = Invoice::where('invoice_number', $invoiceNumber)
                ->lockForUpdate()
                ->first();

            if (!$invoice) {
                $matchedPayment = Payment::where('payment_number', $invoiceNumber)
                    ->orWhere('gateway_reference', $invoiceNumber)
                    ->lockForUpdate()
                    ->first();

                if ($matchedPayment && $matchedPayment->invoice_id) {
                    $invoice = Invoice::where('id', $matchedPayment->invoice_id)
                        ->lockForUpdate()
                        ->first();
                }
            }

            if ($invoice) {
                if ($invoice->status !== 'paid') {
                    $methodLabel = is_string($channelId) && trim($channelId) !== ''
                        ? strtolower(trim($channelId))
                        : 'doku_checkout';

                    $payment = Payment::where('invoice_id', $invoice->id)
                        ->where('gateway_name', 'doku')
                        ->where('status', 'pending')
                        ->lockForUpdate()
                        ->first();

                    $responseMeta = is_array($payment?->gateway_response) ? $payment->gateway_response : [];
                    $mergedResponse = array_merge($responseMeta, [
                        'gateway_name' => 'doku',
                        'invoice_number' => $invoiceNumber,
                        'channel_id' => $channelId,
                        'transaction_status' => 'SUCCESS',
                        'webhook_payload' => $payload,
                    ]);

                    if ($payment) {
                        $payment->update([
                            'status' => 'settlement',
                            'payment_method' => $methodLabel,
                            'settled_at' => now(),
                            'gateway_response' => $mergedResponse,
                        ]);
                    } else {
                        $payment = Payment::create([
                            'payment_number' => 'PAY-DOKU-' . date('YmdHis') . '-' . strtoupper(Str::random(4)),
                            'invoice_id' => $invoice->id,
                            'user_id' => $invoice->user_id,
                            'gateway_name' => 'doku',
                            'gateway_reference' => (string) (data_get($payload, 'transaction.original_request_id') ?: $invoiceNumber),
                            'amount' => $invoice->amount,
                            'payment_method' => $methodLabel,
                            'status' => 'settlement',
                            'gateway_response' => $mergedResponse,
                            'settled_at' => now(),
                        ]);
                    }

                    $invoice->status = 'paid';
                    $invoice->save();

                    $settledInvoicePayment = $payment;
                }

                return;
            }

            // 2. Otherwise settle MembershipTransaction created directly during Upgrade Checkout
            $transaction = MembershipTransaction::query()
                ->where(function ($q) use ($invoiceNumber) {
                    $q->where('metadata->doku_invoice_number', $invoiceNumber)
                      ->orWhere('metadata->order_id', $invoiceNumber);
                })
                ->lockForUpdate()
                ->first();

            if (!$transaction) {
                Log::warning('DOKU webhook: no matching order or invoice found', [
                    'invoice_number' => $invoiceNumber,
                ]);
                return;
            }

            if ($transaction->status === 'completed') {
                return;
            }

            $meta = is_array($transaction->metadata) ? $transaction->metadata : [];
            $targetLevel = $meta['target_level'] ?? null;

            $transaction->update([
                'status' => 'completed',
                'metadata' => array_merge($meta, [
                    'payment_gateway' => 'doku',
                    'doku_status' => 'SUCCESS',
                    'doku_channel' => $channelId,
                    'doku_paid_at' => now()->toISOString(),
                    'doku_webhook' => $payload,
                ]),
            ]);

            $user = $transaction->user;
            if ($user && in_array($targetLevel, ['gold', 'platinum'], true)) {
                $oldLevel = $user->membership_level ?? 'bronze';

                $this->membershipService->updateMembershipLevel(
                    $user,
                    $targetLevel,
                    $oldLevel,
                    'Upgrade via DOKU Checkout: ' . $invoiceNumber,
                    null
                );

                $user->increment('total_transactions', $transaction->amount);

                $bonusPoints = self::BONUS_POINTS[$targetLevel] ?? 0;
                if ($bonusPoints > 0) {
                    $this->membershipService->addPoints(
                        $user,
                        $bonusPoints,
                        'earned',
                        "Bonus poin upgrade ke {$targetLevel} via DOKU",
                        (string) $transaction->id,
                        'membership_upgrade'
                    );
                }

                Log::info('Membership upgraded via DOKU webhook', [
                    'user_id' => $user->id,
                    'old_level' => $oldLevel,
                    'new_level' => $targetLevel,
                    'invoice_number' => $invoiceNumber,
                ]);
            }

            Invoice::where('membership_transaction_id', $transaction->id)
                ->where('status', '!=', 'paid')
                ->update(['status' => 'paid']);
        });

        if ($settledInvoicePayment instanceof Payment) {
            PaymentSettled::dispatch($settledInvoicePayment);
        }
    }

    private function recordNonSuccessWebhook(string $invoiceNumber, string $status, mixed $channelId, array $payload): void
    {
        $transaction = MembershipTransaction::query()
            ->where(function ($q) use ($invoiceNumber) {
                $q->where('metadata->doku_invoice_number', $invoiceNumber)
                  ->orWhere('metadata->order_id', $invoiceNumber);
            })
            ->first();

        if ($transaction && $transaction->status === 'pending') {
            $meta = is_array($transaction->metadata) ? $transaction->metadata : [];
            $transaction->update([
                'metadata' => array_merge($meta, [
                    'doku_last_status' => $status,
                    'doku_last_channel' => $channelId,
                    'doku_last_webhook_at' => now()->toISOString(),
                ]),
            ]);
        }
    }
}

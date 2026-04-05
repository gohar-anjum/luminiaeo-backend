<?php

namespace App\Services\Admin;

use App\Domain\Billing\Models\CreditTransaction;
use App\Support\Iso8601;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Credit ledger for admins with CSV export.
 */
class AdminCreditTransactionService
{
    /**
     * @param  array{user_id?: int|null, type?: string|null}  $filters
     * @return LengthAwarePaginator<int, CreditTransaction>
     */
    public function paginate(int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        $q = CreditTransaction::query()->orderByDesc('id');

        if (! empty($filters['user_id'])) {
            $q->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }

        return $q->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTransaction(CreditTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'user_id' => $tx->user_id,
            'amount' => (int) $tx->amount,
            'type' => $this->mapDisplayType($tx),
            'reference_id' => $this->formatReferenceId($tx),
            'created_at' => Iso8601::utcZ($tx->created_at),
        ];
    }

    public function mapDisplayType(CreditTransaction $tx): string
    {
        if ($tx->type === CreditTransaction::TYPE_USAGE && $tx->feature_key === 'backlink_feature') {
            return 'backlink_submission';
        }

        return $tx->type;
    }

    public function formatReferenceId(CreditTransaction $tx): string
    {
        if ($tx->reference_type && $tx->reference_id) {
            return $tx->reference_type.'_'.$tx->reference_id;
        }

        return (string) ($tx->reference_id ?? '');
    }

    /**
     * @param  array{user_id?: int|null, type?: string|null}  $filters
     */
    public function exportCsv(array $filters = []): StreamedResponse
    {
        $filename = 'credit_transactions_'.now()->format('Y-m-d_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $q = CreditTransaction::query()->orderBy('id');
        if (! empty($filters['user_id'])) {
            $q->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['type'])) {
            $q->where('type', $filters['type']);
        }

        $mapper = $this;

        return response()->streamDownload(function () use ($q, $mapper): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'user_id', 'amount', 'type', 'reference_id', 'created_at']);

            $q->chunkById(500, function ($chunk) use ($out, $mapper): void {
                foreach ($chunk as $tx) {
                    /** @var CreditTransaction $tx */
                    fputcsv($out, [
                        $tx->id,
                        $tx->user_id,
                        $tx->amount,
                        $mapper->mapDisplayType($tx),
                        $mapper->formatReferenceId($tx),
                        $tx->created_at?->toIso8601String() ?? '',
                    ]);
                }
            });
            fclose($out);
        }, $filename, $headers);
    }
}

<?php

namespace App\DTOs;

class BacklinkDTO
{
    public function __construct(
        public string $domain,
        public string $sourceUrl,
        public ?string $anchor = null,
        public ?string $linkType = null,
        public ?string $sourceDomain = null,
        public ?float $domainRank = null,
        public string $taskId,
        public ?string $ip = null,
        public ?string $asn = null,
        public ?string $hostingProvider = null,
        public ?string $whoisRegistrar = null,
        public ?int $domainAgeDays = null,
        public ?string $contentFingerprint = null,
        public ?float $pbnProbability = null,
        public ?string $riskLevel = null,
        public ?array $pbnReasons = null,
        public ?array $pbnSignals = null,
        public ?array $raw = null,
        public ?string $firstSeen = null,
        public ?string $lastSeen = null,
        public ?bool $isDofollow = null,
        public ?int $linksCount = null,
        public ?string $safeBrowsingStatus = null,
        public ?array $safeBrowsingThreats = null,
        public ?string $safeBrowsingCheckedAt = null,
        public ?int $backlinkSpamScore = null,
    ) {}

    public static function fromArray(array $data, string $domain, string $taskId): self
    {
        $sourceUrl = $data['source_url']
            ?? $data['url_from']
            ?? $data['url_to']
            ?? $data['target']
            ?? '';

        $sourceDomain = $data['source_domain']
            ?? $data['domain_from']
            ?? null;

        $linkType = $data['link_type'] ?? null;
        if (!$linkType && array_key_exists('dofollow', $data)) {
            $linkType = $data['dofollow'] ? 'dofollow' : 'nofollow';
        }

        $domainRank = $data['domain_rank']
            ?? $data['domain_from_rank']
            ?? $data['rank']
            ?? null;

        $raw = $data;
        $spamScore = isset($data['backlink_spam_score']) && is_numeric($data['backlink_spam_score'])
            ? (int)$data['backlink_spam_score']
            : (isset($data['url_to_spam_score']) && is_numeric($data['url_to_spam_score'])
                ? (int)$data['url_to_spam_score']
                : null);

        return new self(
            domain: $domain,
            sourceUrl: $sourceUrl,
            anchor: $data['anchor'] ?? null,
            linkType: $linkType,
            sourceDomain: $sourceDomain,
            domainRank: $domainRank,
            taskId: $taskId,
            ip: isset($data['domain_from_ip']) && !is_array($data['domain_from_ip']) ? (string)$data['domain_from_ip'] : null,
            raw: $raw,
            firstSeen: $data['first_seen'] ?? null,
            lastSeen: $data['last_seen'] ?? null,
            isDofollow: $data['dofollow'] ?? null,
            linksCount: $data['links_count'] ?? null,
            backlinkSpamScore: $spamScore,
        );
    }

    public function applyWhoisSignals(array $signals): void
    {
        if (empty($signals)) {
            return;
        }

        $registrar = $signals['registrar'] ?? null;
        if ($registrar && !is_array($registrar)) {
            $registrar = (string)$registrar;
            if (mb_strlen($registrar) > 255) {
                $registrar = mb_substr($registrar, 0, 255);
            }
            $this->whoisRegistrar = $registrar;
        }

        $domainAge = $signals['domain_age_days'] ?? null;
        $this->domainAgeDays = is_array($domainAge) ? null : ($domainAge ? (int)$domainAge : $this->domainAgeDays);
    }

    public function applyDetection(array $result): void
    {
        $this->pbnProbability = isset($result['pbn_probability']) && is_numeric($result['pbn_probability'])
            ? (float)$result['pbn_probability']
            : $this->pbnProbability;

        $this->riskLevel = isset($result['risk_level']) && is_string($result['risk_level'])
            ? $result['risk_level']
            : $this->riskLevel;

        $this->pbnReasons = isset($result['reasons']) && is_array($result['reasons'])
            ? $result['reasons']
            : $this->pbnReasons;

        $this->pbnSignals = isset($result['signals']) && is_array($result['signals'])
            ? $result['signals']
            : $this->pbnSignals;

        $signals = $result['signals'] ?? [];

        if (isset($signals['asn']) && !is_array($signals['asn'])) {
            $this->asn = is_string($signals['asn']) || is_numeric($signals['asn']) ? (string)$signals['asn'] : $this->asn;
        }
        if (isset($signals['hosting_provider']) && !is_array($signals['hosting_provider'])) {
            $this->hostingProvider = is_string($signals['hosting_provider']) ? $signals['hosting_provider'] : $this->hostingProvider;
        }
        if (isset($signals['content_fingerprint']) && !is_array($signals['content_fingerprint'])) {
            $this->contentFingerprint = is_string($signals['content_fingerprint']) ? $signals['content_fingerprint'] : $this->contentFingerprint;
        }
    }

    public function applySafeBrowsing(array $data): void
    {
        $status = $data['status'] ?? null;
        $this->safeBrowsingStatus = is_array($status) ? 'unknown' : ($status ? (string)$status : ($this->safeBrowsingStatus ?? 'unknown'));

        $threats = $data['threats'] ?? null;
        $this->safeBrowsingThreats = is_array($threats) ? $threats : null;

        $checkedAt = $data['checked_at'] ?? null;
        $this->safeBrowsingCheckedAt = is_array($checkedAt) ? null : ($checkedAt ? (string)$checkedAt : $this->safeBrowsingCheckedAt);
    }

    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'source_url' => $this->sourceUrl,
            'anchor' => $this->anchor,
            'link_type' => $this->linkType,
            'source_domain' => $this->sourceDomain,
            'domain_rank' => $this->domainRank,
            'task_id' => $this->taskId,
            'ip' => $this->ip,
            'asn' => $this->asn,
            'hosting_provider' => $this->hostingProvider,
            'whois_registrar' => $this->whoisRegistrar,
            'domain_age_days' => $this->domainAgeDays,
            'content_fingerprint' => $this->contentFingerprint,
            'pbn_probability' => $this->pbnProbability,
            'risk_level' => $this->riskLevel ?? 'unknown',
            'pbn_reasons' => $this->pbnReasons,
            'pbn_signals' => $this->pbnSignals,
            'safe_browsing_status' => $this->safeBrowsingStatus ?? 'unknown',
            'safe_browsing_threats' => $this->safeBrowsingThreats,
            'safe_browsing_checked_at' => $this->safeBrowsingCheckedAt,
            'backlink_spam_score' => $this->backlinkSpamScore,
        ];
    }

    public function toDatabaseArray(): array
    {
        $parseDate = fn($date) => is_string($date) && !empty($date)
            ? (function() use ($date) { try { return \Carbon\Carbon::parse($date); } catch (\Exception $e) { return null; } })()
            : ($date instanceof \DateTimeInterface ? $date : null);

        $safeString = fn($val, $maxLength = null) => is_array($val) ? null : ($val !== null ? ($maxLength ? mb_substr((string)$val, 0, $maxLength) : (string)$val) : null);

        $safeBrowsingCheckedAt = $parseDate($this->safeBrowsingCheckedAt);

        return [
            'domain' => (string)$this->domain,
            'source_url' => (string)$this->sourceUrl,
            'anchor' => $this->anchor ? (string)$this->anchor : null,
            'link_type' => $this->linkType ? (string)$this->linkType : null,
            'source_domain' => $this->sourceDomain ? (string)$this->sourceDomain : null,
            'domain_rank' => $this->domainRank,
            'task_id' => (string)$this->taskId,
            'updated_at' => now(),
            'ip' => $safeString($this->ip),
            'asn' => $safeString($this->asn),
            'hosting_provider' => $safeString($this->hostingProvider),
            'whois_registrar' => $safeString($this->whoisRegistrar, 255),
            'domain_age_days' => $this->domainAgeDays,
            'content_fingerprint' => $safeString($this->contentFingerprint, 191),
            'pbn_probability' => $this->pbnProbability,
            'risk_level' => $this->riskLevel ? (string)$this->riskLevel : 'unknown',
            'pbn_reasons' => is_array($this->pbnReasons) ? $this->pbnReasons : null,
            'pbn_signals' => is_array($this->pbnSignals) ? $this->pbnSignals : null,
            'safe_browsing_status' => $this->safeBrowsingStatus ? (string)$this->safeBrowsingStatus : 'unknown',
            'safe_browsing_threats' => is_array($this->safeBrowsingThreats) ? $this->safeBrowsingThreats : null,
            'safe_browsing_checked_at' => $safeBrowsingCheckedAt,
            'backlink_spam_score' => $this->backlinkSpamScore,
        ];
    }
}

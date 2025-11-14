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
        );
    }

    public function applyWhoisSignals(array $signals): void
    {
        if (empty($signals)) {
            return;
        }

        $registrar = $signals['registrar'] ?? null;
        $this->whoisRegistrar = is_array($registrar) ? null : ($registrar ? (string)$registrar : $this->whoisRegistrar);
        
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
        
        // Note: PBN detector doesn't return asn, hosting_provider, or content_fingerprint
        // These should be set from other sources (IP lookup, etc.) before detection
        // We only update them if they're explicitly provided and valid
        $signals = isset($result['signals']) && is_array($result['signals']) ? $result['signals'] : [];
        
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
        ];
    }

    public function toDatabaseArray(): array
    {
        $safeBrowsingCheckedAt = $this->safeBrowsingCheckedAt;
        if (is_string($safeBrowsingCheckedAt) && !empty($safeBrowsingCheckedAt)) {
            try {
                $safeBrowsingCheckedAt = \Carbon\Carbon::parse($safeBrowsingCheckedAt);
            } catch (\Exception $e) {
                $safeBrowsingCheckedAt = null;
            }
        } elseif (!($safeBrowsingCheckedAt instanceof \DateTimeInterface)) {
            $safeBrowsingCheckedAt = null;
        }

        // Ensure string fields are never arrays
        $asn = $this->asn;
        if (is_array($asn)) {
            $asn = null;
        } elseif ($asn !== null) {
            $asn = (string)$asn;
        }

        $hostingProvider = $this->hostingProvider;
        if (is_array($hostingProvider)) {
            $hostingProvider = null;
        } elseif ($hostingProvider !== null) {
            $hostingProvider = (string)$hostingProvider;
        }

        $contentFingerprint = $this->contentFingerprint;
        if (is_array($contentFingerprint)) {
            $contentFingerprint = null;
        } elseif ($contentFingerprint !== null) {
            $contentFingerprint = (string)$contentFingerprint;
        }

        $ip = $this->ip;
        if (is_array($ip)) {
            $ip = null;
        } elseif ($ip !== null) {
            $ip = (string)$ip;
        }

        $whoisRegistrar = $this->whoisRegistrar;
        if (is_array($whoisRegistrar)) {
            $whoisRegistrar = null;
        } elseif ($whoisRegistrar !== null) {
            $whoisRegistrar = (string)$whoisRegistrar;
        }

        return [
            'domain' => (string)$this->domain,
            'source_url' => (string)$this->sourceUrl,
            'anchor' => $this->anchor ? (string)$this->anchor : null,
            'link_type' => $this->linkType ? (string)$this->linkType : null,
            'source_domain' => $this->sourceDomain ? (string)$this->sourceDomain : null,
            'domain_rank' => $this->domainRank,
            'task_id' => (string)$this->taskId,
            'updated_at' => now(),
            'ip' => $ip,
            'asn' => $asn,
            'hosting_provider' => $hostingProvider,
            'whois_registrar' => $whoisRegistrar,
            'domain_age_days' => $this->domainAgeDays,
            'content_fingerprint' => $contentFingerprint,
            'pbn_probability' => $this->pbnProbability,
            'risk_level' => $this->riskLevel ? (string)$this->riskLevel : 'unknown',
            'pbn_reasons' => is_array($this->pbnReasons) ? $this->pbnReasons : null,
            'pbn_signals' => is_array($this->pbnSignals) ? $this->pbnSignals : null,
            'safe_browsing_status' => $this->safeBrowsingStatus ? (string)$this->safeBrowsingStatus : 'unknown',
            'safe_browsing_threats' => is_array($this->safeBrowsingThreats) ? $this->safeBrowsingThreats : null,
            'safe_browsing_checked_at' => $safeBrowsingCheckedAt,
        ];
    }
}


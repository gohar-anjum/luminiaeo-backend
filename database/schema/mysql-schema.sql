/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admin_announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_announcements` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_announcements_created_by_foreign` (`created_by`),
  CONSTRAINT `admin_announcements_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_queries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_queries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `feature` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `query_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `query_parameters` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_queries_query_hash_unique` (`query_hash`),
  KEY `api_queries_api_provider_feature_index` (`api_provider`,`feature`),
  KEY `api_queries_api_provider_index` (`api_provider`),
  KEY `api_queries_feature_index` (`feature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_request_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_request_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `api_query_id` bigint unsigned DEFAULT NULL,
  `api_result_id` bigint unsigned DEFAULT NULL,
  `api_provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `feature` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `was_cache_hit` tinyint(1) NOT NULL DEFAULT '0',
  `credit_charged` tinyint(1) NOT NULL DEFAULT '0',
  `request_payload` json DEFAULT NULL,
  `response_status` smallint unsigned DEFAULT NULL,
  `response_time_ms` int unsigned DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `api_request_logs_api_provider_feature_created_at_index` (`api_provider`,`feature`,`created_at`),
  KEY `api_request_logs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `api_request_logs_api_query_id_index` (`api_query_id`),
  KEY `api_request_logs_api_result_id_index` (`api_result_id`),
  KEY `api_request_logs_api_provider_index` (`api_provider`),
  KEY `api_request_logs_feature_index` (`feature`),
  KEY `api_request_logs_created_at_index` (`created_at`),
  CONSTRAINT `api_request_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `api_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `api_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `api_query_id` bigint unsigned NOT NULL,
  `response_payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_meta` json DEFAULT NULL,
  `is_compressed` tinyint(1) NOT NULL DEFAULT '0',
  `byte_size` int unsigned NOT NULL DEFAULT '0',
  `fetched_at` timestamp NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `api_results_api_query_id_expires_at_index` (`api_query_id`,`expires_at`),
  KEY `api_results_expires_at_index` (`expires_at`),
  CONSTRAINT `api_results_api_query_id_foreign` FOREIGN KEY (`api_query_id`) REFERENCES `api_queries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `backlinks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `backlinks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `anchor` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link_type` enum('dofollow','nofollow') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain_rank` double DEFAULT NULL,
  `task_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hosting_provider` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `whois_registrar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `domain_age_days` int unsigned DEFAULT NULL,
  `content_fingerprint` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pbn_probability` decimal(5,4) DEFAULT NULL,
  `risk_level` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `pbn_reasons` json DEFAULT NULL,
  `pbn_signals` json DEFAULT NULL,
  `safe_browsing_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `safe_browsing_threats` json DEFAULT NULL,
  `safe_browsing_checked_at` timestamp NULL DEFAULT NULL,
  `backlink_spam_score` tinyint unsigned DEFAULT NULL,
  `verification_status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_backlinks_unique` (`domain`,`source_url`,`task_id`),
  UNIQUE KEY `backlinks_domain_source_url_task_id_unique` (`domain`,`source_url`,`task_id`),
  KEY `backlinks_task_id_index` (`task_id`),
  KEY `idx_backlinks_domain` (`domain`),
  KEY `idx_backlinks_source_url` (`source_url`),
  KEY `idx_backlinks_source_domain` (`source_domain`),
  KEY `idx_backlinks_domain_rank` (`domain_rank`),
  KEY `idx_backlinks_created_at` (`created_at`),
  KEY `idx_backlinks_domain_source` (`domain`,`source_url`),
  KEY `idx_backlinks_domain_rank_composite` (`domain`,`domain_rank`),
  KEY `idx_backlinks_domain_created` (`domain`,`created_at`),
  KEY `idx_backlinks_task_domain` (`task_id`,`domain`),
  KEY `backlinks_domain_index` (`domain`),
  KEY `backlinks_risk_level_index` (`risk_level`),
  KEY `backlinks_pbn_probability_index` (`pbn_probability`),
  KEY `backlinks_domain_risk_level_index` (`domain`,`risk_level`),
  KEY `backlinks_verification_status_domain_index` (`verification_status`,`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `citation_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `citation_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `credit_reservation_id` bigint unsigned DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `queries` json DEFAULT NULL,
  `results` json DEFAULT NULL,
  `competitors` json DEFAULT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `citation_tasks_url_index` (`url`),
  KEY `citation_tasks_status_index` (`status`),
  KEY `idx_url_status_created` (`url`,`status`,`created_at`),
  KEY `idx_citation_tasks_status` (`status`),
  KEY `idx_citation_tasks_url` (`url`),
  KEY `idx_citation_tasks_created` (`created_at`),
  KEY `citation_tasks_url_status_created_at_index` (`url`,`status`,`created_at`),
  KEY `citation_tasks_status_created_at_index` (`status`,`created_at`),
  KEY `citation_tasks_user_id_index` (`user_id`),
  KEY `citation_tasks_credit_reservation_id_index` (`credit_reservation_id`),
  CONSTRAINT `citation_tasks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cluster_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cluster_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `credit_reservation_id` bigint unsigned DEFAULT NULL,
  `cache_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_code` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `location_code` int unsigned NOT NULL DEFAULT '2840',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `snapshot_id` bigint unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cluster_jobs_snapshot_id_foreign` (`snapshot_id`),
  KEY `cluster_jobs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `cluster_jobs_cache_key_status_index` (`cache_key`,`status`),
  KEY `cluster_jobs_cache_key_index` (`cache_key`),
  KEY `cluster_jobs_status_index` (`status`),
  KEY `cluster_jobs_credit_reservation_id_foreign` (`credit_reservation_id`),
  CONSTRAINT `cluster_jobs_credit_reservation_id_foreign` FOREIGN KEY (`credit_reservation_id`) REFERENCES `credit_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cluster_jobs_snapshot_id_foreign` FOREIGN KEY (`snapshot_id`) REFERENCES `keyword_cluster_snapshots` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cluster_jobs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `content_outlines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `content_outlines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'professional',
  `outline` json NOT NULL,
  `semantic_keywords` json DEFAULT NULL,
  `intent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `content_outlines_user_id_keyword_index` (`user_id`,`keyword`),
  CONSTRAINT `content_outlines_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `credit_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credit_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` bigint NOT NULL,
  `balance_after` bigint unsigned DEFAULT NULL,
  `feature_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `idempotency_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credit_transactions_idempotency_key_unique` (`idempotency_key`),
  KEY `credit_transactions_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `credit_transactions_reference_id_index` (`reference_id`),
  CONSTRAINT `credit_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `faq_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `faq_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `credit_reservation_id` bigint unsigned DEFAULT NULL,
  `task_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `topic` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_keyword` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `alsoasked_search_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serp_questions` json DEFAULT NULL,
  `serp_answers` json DEFAULT NULL,
  `alsoasked_questions` json DEFAULT NULL,
  `paa_answers` json DEFAULT NULL,
  `question_keywords` json DEFAULT NULL,
  `options` json DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faq_id` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `faq_tasks_task_id_unique` (`task_id`),
  KEY `faq_tasks_faq_id_foreign` (`faq_id`),
  KEY `faq_tasks_status_created_at_index` (`status`,`created_at`),
  KEY `faq_tasks_user_id_status_index` (`user_id`,`status`),
  KEY `faq_tasks_alsoasked_search_id_index` (`alsoasked_search_id`),
  KEY `faq_tasks_status_index` (`status`),
  KEY `faq_tasks_credit_reservation_id_index` (`credit_reservation_id`),
  CONSTRAINT `faq_tasks_faq_id_foreign` FOREIGN KEY (`faq_id`) REFERENCES `faqs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `faq_tasks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `faqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `faqs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `topic` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `faqs` json NOT NULL,
  `options` json DEFAULT NULL,
  `source_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_calls_saved` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `faqs_source_hash_unique` (`source_hash`),
  KEY `faqs_url_created_at_index` (`url`,`created_at`),
  KEY `faqs_topic_created_at_index` (`topic`,`created_at`),
  KEY `faqs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `faqs_url_index` (`url`),
  KEY `faqs_topic_index` (`topic`),
  CONSTRAINT `faqs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `features`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `features` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `credit_cost` int unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `features_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `keyword_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keyword_cache` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `location_code` int NOT NULL DEFAULT '2840',
  `search_volume` int DEFAULT NULL,
  `competition` double DEFAULT NULL,
  `cpc` double DEFAULT NULL,
  `difficulty` int DEFAULT NULL,
  `serp_features` json DEFAULT NULL,
  `related_keywords` json DEFAULT NULL,
  `trends` json DEFAULT NULL,
  `cluster_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cluster_data` json DEFAULT NULL,
  `cached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `keyword_cache_unique` (`keyword`,`language_code`,`location_code`),
  KEY `keyword_cache_expires_at_cached_at_index` (`expires_at`,`cached_at`),
  KEY `keyword_cache_keyword_index` (`keyword`),
  KEY `keyword_cache_language_code_index` (`language_code`),
  KEY `keyword_cache_location_code_index` (`location_code`),
  KEY `keyword_cache_cluster_id_index` (`cluster_id`),
  KEY `keyword_cache_expires_at_index` (`expires_at`),
  KEY `keyword_cache_source_index` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `keyword_cluster_snapshots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keyword_cluster_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_code` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `location_code` int unsigned NOT NULL DEFAULT '2840',
  `tree_json` json NOT NULL,
  `expires_at` timestamp NOT NULL,
  `schema_version` smallint unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `keyword_cluster_snapshots_cache_key_unique` (`cache_key`),
  KEY `keyword_cluster_snapshots_expires_at_schema_version_index` (`expires_at`,`schema_version`),
  KEY `keyword_cluster_snapshots_keyword_index` (`keyword`),
  KEY `keyword_cluster_snapshots_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `keyword_clusters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keyword_clusters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `keyword_research_job_id` bigint unsigned NOT NULL,
  `topic_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `suggested_article_titles` json DEFAULT NULL,
  `recommended_faq_questions` json DEFAULT NULL,
  `schema_suggestions` json DEFAULT NULL,
  `ai_visibility_projection` double DEFAULT NULL,
  `keyword_count` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `keyword_clusters_keyword_research_job_id_index` (`keyword_research_job_id`),
  KEY `idx_clusters_job` (`keyword_research_job_id`),
  CONSTRAINT `keyword_clusters_keyword_research_job_id_foreign` FOREIGN KEY (`keyword_research_job_id`) REFERENCES `keyword_research_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `keyword_research_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keyword_research_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `credit_reservation_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `query` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `language_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `geoTargetId` int NOT NULL DEFAULT '2840',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `settings` json DEFAULT NULL,
  `progress` json DEFAULT NULL,
  `result` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `keyword_research_jobs_user_id_status_index` (`user_id`,`status`),
  KEY `keyword_research_jobs_project_id_index` (`project_id`),
  KEY `idx_research_jobs_user` (`user_id`),
  KEY `idx_research_jobs_status` (`status`),
  KEY `idx_research_jobs_project` (`project_id`),
  KEY `idx_research_jobs_user_status` (`user_id`,`status`),
  KEY `idx_research_jobs_created` (`created_at`),
  KEY `keyword_research_jobs_status_index` (`status`),
  KEY `keyword_research_jobs_query_index` (`query`),
  KEY `keyword_research_jobs_credit_reservation_id_index` (`credit_reservation_id`),
  CONSTRAINT `keyword_research_jobs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `keyword_research_jobs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `keywords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `keywords` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `keyword_research_job_id` bigint unsigned DEFAULT NULL,
  `keyword_cluster_id` bigint unsigned DEFAULT NULL,
  `keyword` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_volume` int DEFAULT NULL,
  `competition` double DEFAULT NULL,
  `cpc` double DEFAULT NULL,
  `ai_visibility_score` double DEFAULT NULL,
  `intent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intent_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intent_metadata` json DEFAULT NULL,
  `semantic_data` json DEFAULT NULL,
  `question_variations` json DEFAULT NULL,
  `long_tail_versions` json DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `language_code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en',
  `geoTargetId` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `project_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `keywords_project_id_foreign` (`project_id`),
  KEY `keywords_keyword_research_job_id_index` (`keyword_research_job_id`),
  KEY `keywords_keyword_cluster_id_index` (`keyword_cluster_id`),
  KEY `keywords_source_index` (`source`),
  KEY `keywords_intent_category_index` (`intent_category`),
  KEY `idx_keywords_research_job` (`keyword_research_job_id`),
  KEY `idx_keywords_cluster` (`keyword_cluster_id`),
  KEY `idx_keywords_source` (`source`),
  KEY `idx_keywords_keyword` (`keyword`),
  KEY `idx_keywords_intent` (`intent_category`),
  KEY `idx_keywords_job_source` (`keyword_research_job_id`,`source`),
  KEY `idx_keywords_job_cluster` (`keyword_research_job_id`,`keyword_cluster_id`),
  KEY `idx_keywords_visibility` (`ai_visibility_score`),
  CONSTRAINT `keywords_keyword_cluster_id_foreign` FOREIGN KEY (`keyword_cluster_id`) REFERENCES `keyword_clusters` (`id`) ON DELETE SET NULL,
  CONSTRAINT `keywords_keyword_research_job_id_foreign` FOREIGN KEY (`keyword_research_job_id`) REFERENCES `keyword_research_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `keywords_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `location_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `location_code` int NOT NULL,
  `location_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_code_parent` int DEFAULT NULL,
  `country_iso_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `location_codes_location_code_unique` (`location_code`),
  KEY `location_codes_location_code_index` (`location_code`),
  KEY `location_codes_country_iso_code_index` (`country_iso_code`),
  KEY `location_codes_location_type_index` (`location_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `meta_analyses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meta_analyses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_keyword` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_title` text COLLATE utf8mb4_unicode_ci,
  `original_description` text COLLATE utf8mb4_unicode_ci,
  `suggested_title` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggested_description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `suggestions` json DEFAULT NULL,
  `keywords` json NOT NULL,
  `intent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `word_count` int unsigned NOT NULL,
  `analyzed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_analyses_user_id_url_index` (`user_id`,`url`),
  KEY `meta_analyses_url_index` (`url`),
  CONSTRAINT `meta_analyses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pbn_detections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pbn_detections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `task_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `high_risk_count` int unsigned NOT NULL DEFAULT '0',
  `medium_risk_count` int unsigned NOT NULL DEFAULT '0',
  `low_risk_count` int unsigned NOT NULL DEFAULT '0',
  `latency_ms` int unsigned DEFAULT NULL,
  `analysis_started_at` timestamp NULL DEFAULT NULL,
  `analysis_completed_at` timestamp NULL DEFAULT NULL,
  `status_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `summary` json DEFAULT NULL,
  `response_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pbn_detections_task_id_index` (`task_id`),
  KEY `pbn_detections_domain_index` (`domain`),
  KEY `pbn_detections_task_id_domain_index` (`task_id`,`domain`),
  KEY `pbn_detections_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `projects_user_id_foreign` (`user_id`),
  CONSTRAINT `projects_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `semantic_analyses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `semantic_analyses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `source_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_keyword` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comparison_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comparison_value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `semantic_score` double NOT NULL,
  `keyword_scores` json DEFAULT NULL,
  `analyzed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `semantic_analyses_user_id_foreign` (`user_id`),
  KEY `semantic_analyses_source_url_index` (`source_url`),
  CONSTRAINT `semantic_analyses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `seo_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `seo_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `task_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('backlinks','search_volume','keywords') COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payload` json DEFAULT NULL,
  `result` json DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `retry_count` int NOT NULL DEFAULT '0',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seo_tasks_task_id_unique` (`task_id`),
  KEY `seo_tasks_type_status_index` (`type`,`status`),
  KEY `seo_tasks_domain_status_index` (`domain`,`status`),
  KEY `seo_tasks_status_created_at_index` (`status`,`created_at`),
  KEY `seo_tasks_type_index` (`type`),
  KEY `seo_tasks_domain_index` (`domain`),
  KEY `seo_tasks_status_index` (`status`),
  KEY `seo_tasks_domain_type_status_completed_at_index` (`domain`,`type`,`status`,`completed_at`),
  KEY `seo_tasks_user_id_type_created_at_index` (`user_id`,`type`,`created_at`),
  CONSTRAINT `seo_tasks_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stripe_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stripe_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `stripe_event_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stripe_events_stripe_event_id_unique` (`stripe_event_id`),
  KEY `stripe_events_stripe_event_id_index` (`stripe_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint unsigned NOT NULL,
  `stripe_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_product` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_price` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meter_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `meter_event_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_items_stripe_id_unique` (`stripe_id`),
  KEY `subscription_items_subscription_id_stripe_price_index` (`subscription_id`,`stripe_price`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stripe_price` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscriptions_stripe_id_unique` (`stripe_id`),
  KEY `subscriptions_user_id_stripe_status_index` (`user_id`,`stripe_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_api_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_api_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `api_result_id` bigint unsigned NOT NULL,
  `feature_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `was_cache_hit` tinyint(1) NOT NULL DEFAULT '0',
  `credit_charged` tinyint(1) NOT NULL DEFAULT '0',
  `credit_transaction_id` bigint unsigned DEFAULT NULL,
  `accessed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_result_feature_unique` (`user_id`,`api_result_id`,`feature_key`),
  KEY `user_api_results_api_result_id_foreign` (`api_result_id`),
  KEY `user_api_results_user_id_feature_key_index` (`user_id`,`feature_key`),
  KEY `user_api_results_feature_key_index` (`feature_key`),
  CONSTRAINT `user_api_results_api_result_id_foreign` FOREIGN KEY (`api_result_id`) REFERENCES `api_results` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_api_results_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_keyword_cluster_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_keyword_cluster_access` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `cache_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_keyword_cluster_access_user_id_cache_key_unique` (`user_id`,`cache_key`),
  KEY `user_keyword_cluster_access_cache_key_index` (`cache_key`),
  CONSTRAINT `user_keyword_cluster_access_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credits_balance` bigint unsigned NOT NULL DEFAULT '0',
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `suspended_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `stripe_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pm_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pm_last_four` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_stripe_id_index` (`stripe_id`),
  KEY `users_is_admin_index` (`is_admin`),
  KEY `users_suspended_at_index` (`suspended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2025_11_11_060932_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2025_11_11_145423_create_keywords_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2025_11_11_150536_create_projects_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2025_11_13_060445_create_backlinks_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2025_01_27_000001_create_seo_tasks_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2025_01_27_000002_add_indexes_to_backlinks_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2025_11_14_092449_add_pbn_columns_to_backlinks_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2025_11_14_092517_create_pbn_detections_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2025_11_14_162545_add_backlink_spam_score_to_backlinks_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2025_11_21_000000_create_citation_tasks_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2025_01_28_000001_update_keywords_table_for_research',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2025_12_11_114807_create_keyword_cache_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2025_01_30_000001_add_performance_indexes',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2025_12_14_164426_create_faqs_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2025_12_24_150227_create_faq_tasks_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2025_12_29_000001_add_comprehensive_indexes',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2025_12_29_000002_add_user_id_to_citation_tasks',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2025_12_30_174256_fix_keywords_table_columns',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2025_12_31_082712_create_location_codes_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2025_12_31_182249_add_question_keywords_to_faq_tasks_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_01_01_214400_add_progressive_answers_to_faq_tasks_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_02_20_161602_create_customer_columns',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_02_20_161603_create_subscriptions_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_02_20_161604_create_subscription_items_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_02_20_161605_add_meter_id_to_subscription_items_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_02_20_161606_add_meter_event_name_to_subscription_items_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_02_23_185521_create_features_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_02_24_100000_add_credits_balance_to_users_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_02_24_100001_create_credit_transactions_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_02_24_100002_create_stripe_events_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_02_25_100000_add_status_and_idempotency_to_credit_transactions',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_02_25_100001_add_credit_reservation_id_to_citation_tasks',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_02_25_100002_add_credit_reservation_id_to_faq_tasks',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_02_25_200000_add_credit_reservation_id_to_keyword_research_jobs',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_03_02_100000_create_meta_analyses_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_03_02_100001_create_semantic_analyses_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_03_09_100000_create_api_queries_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_03_09_100001_create_api_results_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_03_09_100002_create_user_api_results_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_03_09_100003_create_api_request_logs_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_03_09_200000_enhance_page_analysis_tables',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_03_09_200001_create_content_outlines_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_03_22_100000_create_keyword_cluster_snapshots_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_03_22_100001_create_cluster_jobs_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_03_22_200000_add_credit_reservation_id_to_cluster_jobs',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_03_22_210000_create_user_keyword_cluster_access_table',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_04_05_100000_add_admin_fields_to_users_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_04_05_100001_add_user_id_to_seo_tasks_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_04_05_100002_add_verification_to_backlinks_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_04_05_100003_create_client_api_request_logs_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_04_05_100004_create_admin_announcements_table',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_04_06_100000_drop_client_api_request_logs_table',16);

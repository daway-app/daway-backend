-- Daway MySQL dump generated 2026-08-10 21:30:55
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE `activity_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `log_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `event` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causer_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `causer_id` bigint unsigned DEFAULT NULL,
  `attribute_changes` json DEFAULT NULL,
  `properties` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subject` (`subject_type`,`subject_id`),
  KEY `causer` (`causer_type`,`causer_id`),
  KEY `activity_log_log_name_index` (`log_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `alternative_medicine`;
CREATE TABLE `alternative_medicine` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `medicine_id` bigint unsigned NOT NULL,
  `alternative_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alternative_medicine_medicine_id_alternative_id_unique` (`medicine_id`,`alternative_id`),
  KEY `alternative_medicine_alternative_id_foreign` (`alternative_id`),
  CONSTRAINT `alternative_medicine_alternative_id_foreign` FOREIGN KEY (`alternative_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `alternative_medicine_medicine_id_foreign` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `availability_notifications`;
CREATE TABLE `availability_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `medicine_id` bigint unsigned NOT NULL,
  `pharmacy_id` bigint unsigned NOT NULL,
  `is_notified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_availability_notification` (`user_id`,`medicine_id`,`pharmacy_id`),
  KEY `availability_notifications_medicine_id_foreign` (`medicine_id`),
  KEY `availability_notifications_pharmacy_id_foreign` (`pharmacy_id`),
  CONSTRAINT `availability_notifications_medicine_id_foreign` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `availability_notifications_pharmacy_id_foreign` FOREIGN KEY (`pharmacy_id`) REFERENCES `pharmacies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `availability_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache`;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache_locks`;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `favorites`;
CREATE TABLE `favorites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `favoritable_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `favoritable_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_favorite` (`user_id`,`favoritable_type`,`favoritable_id`),
  KEY `idx_favoritable` (`favoritable_type`,`favoritable_id`),
  CONSTRAINT `favorites_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `first_aid`;
CREATE TABLE `first_aid` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `instructions_steps` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `medical_profiles`;
CREATE TABLE `medical_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `allergies` text COLLATE utf8mb4_unicode_ci,
  `chronic_diseases` text COLLATE utf8mb4_unicode_ci,
  `blood_type` varchar(5) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `medical_profiles_user_id_unique` (`user_id`),
  CONSTRAINT `medical_profiles_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `medicines`;
CREATE TABLE `medicines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trade_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active_ingredient` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `image` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `stock` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_search` (`trade_name`,`active_ingredient`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `medicines` (`id`,`trade_name`,`active_ingredient`,`description`,`image`,`is_available`,`stock`,`created_at`,`updated_at`) VALUES
(1,'Panadol','Paracetamol','For fever and pain relief.',NULL,1,100,'2026-08-10 15:48:53','2026-08-10 15:48:53'),
(2,'Amoxil','Amoxicillin','Antibiotic for bacterial infections.',NULL,1,50,'2026-08-10 15:48:54','2026-08-10 15:48:54'),
(3,'Glucophage','Metformin','For type 2 diabetes.',NULL,0,0,'2026-08-10 15:48:55','2026-08-10 15:48:55'),
(4,'Ventolin','Salbutamol','For asthma and breathing difficulties.',NULL,1,30,'2026-08-10 15:48:56','2026-08-10 15:48:56'),
(5,'Augmentin','Amoxicillin/Clavulanic acid','Antibiotic for bacterial infections.',NULL,1,25,'2026-08-10 15:48:57','2026-08-10 15:48:57');

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`id`,`migration`,`batch`) VALUES
(1,'0001_01_01_000000_create_users_table',1),
(2,'2026_07_27_000001_create_pharmacies_table',1),
(3,'2026_07_27_000002_create_pharmacy_hours_table',1),
(4,'2026_07_27_000003_create_medicines_table',1),
(5,'2026_07_27_000004_create_pharmacy_medicines_table',1),
(6,'2026_07_27_000006_create_medical_profiles_table',1),
(7,'2026_07_27_000007_create_reminders_table',1),
(8,'2026_07_27_000008_create_ratings_table',1),
(9,'2026_07_27_000009_create_favorites_table',1),
(10,'2026_07_27_000010_create_first_aid_table',1),
(11,'2026_07_27_000011_create_notifications_table',1),
(12,'2026_07_27_154932_create_personal_access_tokens_table',1),
(13,'2026_08_03_180618_add_pharmacy_id_to_users_table',1),
(14,'2026_08_04_063000_add_patient_fields_to_users_table',1),
(15,'2026_08_07_125155_create_activity_log_table',1),
(16,'2026_08_09_134044_create_cache_table',1),
(17,'2026_08_10_000001_add_reminder_date_to_reminders_table',1),
(18,'2026_08_10_000002_add_dashboard_query_indexes',1),
(19,'2026_08_10_112835_create_availability_notifications_table',1),
(20,'2026_08_10_000000_create_alternative_medicine_table',2);

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `medicine_id` bigint unsigned DEFAULT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_medicine_id_foreign` (`medicine_id`),
  KEY `notifications_user_read_index` (`user_id`,`is_read`),
  KEY `notifications_user_created_at_index` (`user_id`,`created_at`),
  CONSTRAINT `notifications_medicine_id_foreign` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `otp_codes`;
CREATE TABLE `otp_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `otp` varchar(6) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `personal_access_tokens`;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `personal_access_tokens` (`id`,`tokenable_type`,`tokenable_id`,`name`,`token`,`abilities`,`last_used_at`,`expires_at`,`created_at`,`updated_at`) VALUES
(1,'App\\Models\\User',14,'auth_token','fa18b995f53d8e83a14e6fe72a0e7aad2c2e416f5687e9d9deaf6a2d2bad85fb','[\"*\"]',NULL,NULL,'2026-08-10 16:37:46','2026-08-10 16:37:46'),
(2,'App\\Models\\User',15,'auth_token','79f8b656022017f29f33fc7fb6403908b72f7cbe08a11378e98d897052ab02a7','[\"*\"]',NULL,NULL,'2026-08-10 16:39:00','2026-08-10 16:39:00'),
(3,'App\\Models\\User',14,'auth_token','17c97f90ac1b69e0a6335526e573117e7544c9110ccdc803f8ba9b4d87947ab1','[\"*\"]',NULL,NULL,'2026-08-10 16:39:54','2026-08-10 16:39:54'),
(4,'App\\Models\\User',12,'auth_token','e8348ad69e1e04b8d7fd68fc95112b2552eecfadf544ab780b98a404c2b287fe','[\"*\"]',NULL,NULL,'2026-08-10 16:40:13','2026-08-10 16:40:13'),
(5,'App\\Models\\User',15,'auth_token','20a39f8744bb3e5f9749ec355ac8ebbd4107f2937c4b135145db1c449f420c95','[\"*\"]',NULL,NULL,'2026-08-10 16:42:16','2026-08-10 16:42:16');

DROP TABLE IF EXISTS `pharmacies`;
CREATE TABLE `pharmacies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `pharmacy_custom_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pharmacy_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `phone_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avg_rating` decimal(3,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pharmacies_user_id_unique` (`user_id`),
  UNIQUE KEY `pharmacies_pharmacy_custom_id_unique` (`pharmacy_custom_id`),
  KEY `pharmacies_pharmacy_name_index` (`pharmacy_name`),
  KEY `pharmacies_latitude_index` (`latitude`),
  KEY `pharmacies_longitude_index` (`longitude`),
  KEY `pharmacies_is_active_index` (`is_active`),
  CONSTRAINT `pharmacies_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pharmacies` (`id`,`user_id`,`pharmacy_custom_id`,`pharmacy_name`,`address`,`latitude`,`longitude`,`phone_number`,`logo`,`avg_rating`,`is_active`,`created_at`,`updated_at`) VALUES
(1,12,'PH-1234','صيدلية الأمل','غزة، شارع الوحدة','31.50160000','34.46680000','+970591234567',NULL,'0.00',1,'2026-08-10 15:48:59','2026-08-10 15:48:59'),
(2,13,'PH-5678','صيدلية الشفاء','نابلس، شارع حطين','32.22380000','35.26270000','+970598765432',NULL,'0.00',1,'2026-08-10 15:49:00','2026-08-10 15:49:00');

DROP TABLE IF EXISTS `pharmacy_hours`;
CREATE TABLE `pharmacy_hours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pharmacy_id` bigint unsigned NOT NULL,
  `day` enum('sat','sun','mon','tue','wed','thu','fri') COLLATE utf8mb4_unicode_ci NOT NULL,
  `opening_time` time NOT NULL,
  `closing_time` time NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pharmacy_day` (`pharmacy_id`,`day`),
  CONSTRAINT `pharmacy_hours_pharmacy_id_foreign` FOREIGN KEY (`pharmacy_id`) REFERENCES `pharmacies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `pharmacy_medicines`;
CREATE TABLE `pharmacy_medicines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pharmacy_id` bigint unsigned NOT NULL,
  `medicine_id` bigint unsigned NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `quantity` int NOT NULL DEFAULT '0',
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pharmacy_medicine` (`pharmacy_id`,`medicine_id`),
  KEY `pharmacy_medicines_medicine_id_foreign` (`medicine_id`),
  KEY `pharmacy_medicines_is_available_index` (`is_available`),
  CONSTRAINT `pharmacy_medicines_medicine_id_foreign` FOREIGN KEY (`medicine_id`) REFERENCES `medicines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pharmacy_medicines_pharmacy_id_foreign` FOREIGN KEY (`pharmacy_id`) REFERENCES `pharmacies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `ratings`;
CREATE TABLE `ratings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `pharmacy_id` bigint unsigned NOT NULL,
  `stars_rating` tinyint unsigned NOT NULL,
  `comment` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ratings_user_id_foreign` (`user_id`),
  KEY `ratings_pharmacy_created_at_index` (`pharmacy_id`,`created_at`),
  CONSTRAINT `ratings_pharmacy_id_foreign` FOREIGN KEY (`pharmacy_id`) REFERENCES `pharmacies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ratings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_stars_rating_range` CHECK ((`stars_rating` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `reminders`;
CREATE TABLE `reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `medicine_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `dosage` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reminder_date` date DEFAULT NULL,
  `reminder_time` time NOT NULL,
  `frequency` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity_remaining` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reminders_user_id_foreign` (`user_id`),
  CONSTRAINT `reminders_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`id`,`key`,`value`,`created_at`,`updated_at`) VALUES
(1,'site_name','Daway',NULL,'2026-08-10 20:49:55'),
(2,'support_email','support@daway.com',NULL,'2026-08-10 20:49:55'),
(3,'support_phone','+970 599 000 000',NULL,'2026-08-10 20:49:56'),
(4,'default_language','en',NULL,'2026-08-10 20:49:56'),
(5,'site_description','Your reliable pharmaceutical platform.',NULL,'2026-08-10 20:49:56'),
(6,'max_search_radius','15',NULL,'2026-08-10 20:49:57'),
(7,'search_limit','50',NULL,'2026-08-10 20:49:57'),
(8,'session_timeout','120',NULL,'2026-08-10 20:49:57');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pharmacy_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `birth_date` date DEFAULT NULL,
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emergency_contact` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('patient','pharmacy','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_pharmacy_id_unique` (`pharmacy_id`),
  KEY `users_role_created_at_index` (`role`,`created_at`),
  KEY `users_role_is_active_index` (`role`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`,`pharmacy_id`,`name`,`email`,`email_verified_at`,`phone`,`address`,`birth_date`,`avatar`,`emergency_contact`,`password`,`role`,`is_active`,`phone_verified_at`,`remember_token`,`created_at`,`updated_at`) VALUES
(1,NULL,'Admin','admin@daway.com','2026-08-10 15:48:52','+970599999999',NULL,NULL,NULL,NULL,'$2y$12$47FOf9EoRkY01sv.R1QsL.2OxIclLQEL7iBKmrvY9j0nfja..W4nq','admin',1,'2026-08-10 15:48:52',NULL,'2026-08-10 15:48:44','2026-08-10 15:48:52'),
(2,NULL,'Urban Kunde','lhudson@example.org','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','oBEFwZ7Kcd','2026-08-10 15:48:46','2026-08-10 15:48:46'),
(3,NULL,'Prof. Nicholaus Corkery','hermiston.kole@example.net','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','lzBXqlkkjp','2026-08-10 15:48:46','2026-08-10 15:48:46'),
(4,NULL,'Osborne Rau','imorar@example.com','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','lthlg72Nf6','2026-08-10 15:48:46','2026-08-10 15:48:46'),
(5,NULL,'Jalen Schulist','kira.hammes@example.org','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','1AmrL0K5j2','2026-08-10 15:48:47','2026-08-10 15:48:47'),
(6,NULL,'Jolie Moore MD','chanel33@example.net','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','BkTAouinCg','2026-08-10 15:48:47','2026-08-10 15:48:47'),
(7,NULL,'Dr. Okey Kihn','roxane.heaney@example.net','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','LI9tzTRYZi','2026-08-10 15:48:49','2026-08-10 15:48:49'),
(8,NULL,'Julian Murray','emard.aubree@example.org','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','0Pz6dzYAeI','2026-08-10 15:48:50','2026-08-10 15:48:50'),
(9,NULL,'Dr. Javier Russel PhD','vweissnat@example.org','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','nHqH8W8G63','2026-08-10 15:48:51','2026-08-10 15:48:51'),
(10,NULL,'Brionna Klein','dan07@example.net','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','rQnRydF5WY','2026-08-10 15:48:51','2026-08-10 15:48:51'),
(11,NULL,'Trenton Barrows IV','eryn.tillman@example.com','2026-08-10 15:48:45',NULL,NULL,NULL,NULL,NULL,'$2y$12$9Vx1QhlnoQhVB0AaaY/kXO.ErTHHvHQCtl5nJFD781VcFs8ZeaMi6','patient',1,'2026-08-10 15:48:45','Yd8kNTIt0V','2026-08-10 15:48:51','2026-08-10 15:48:51'),
(12,NULL,'Pharmacy User','pharmacy@daway.com','2026-08-10 15:48:58','+970591234567',NULL,NULL,NULL,NULL,'$2y$12$OBvzlDr/2zzeyU4/VC/ZIuB7kx0UbWqQWLkHH1B7OgjwyVT/zcTfm','pharmacy',1,'2026-08-10 15:48:58',NULL,'2026-08-10 15:48:58','2026-08-10 15:48:58'),
(13,NULL,'Pharmacy User 2','pharmacy2@daway.com','2026-08-10 15:48:59','+970598765432',NULL,NULL,NULL,NULL,'$2y$12$aHm1N3Rld9UfibdhXh6n4OBVdEUGGWbMFyfluXQILeJbsfC.KLPq.','pharmacy',1,'2026-08-10 15:48:59',NULL,'2026-08-10 15:48:59','2026-08-10 15:48:59'),
(14,NULL,'New User',NULL,NULL,'0597601911',NULL,NULL,NULL,NULL,'$2y$12$gSy7wkWh6nHE7FOSHGcvQ.vwqSLbSan/iio9U3gVB7z8pZ/MHjRQW','patient',1,NULL,NULL,'2026-08-10 16:37:45','2026-08-10 16:37:45'),
(15,NULL,'New User',NULL,NULL,'0599123456',NULL,NULL,NULL,NULL,'$2y$12$bT.ChrZ8l0F..IEvIN28hejfM4ZhIFDOYNwDeWr.A2YiYwoITTYDC','patient',1,NULL,NULL,'2026-08-10 16:38:59','2026-08-10 16:38:59');

SET FOREIGN_KEY_CHECKS = 1;
-- dump complete

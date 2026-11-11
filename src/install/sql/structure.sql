-- MySQL dump 10.13  Distrib 8.0, for Linux (x86_64)
--
-- Host: localhost    Database: clientfence
-- ------------------------------------------------------
-- Server version	8.0.x

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_admin_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_admin_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_id_idx` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `activity_client_email`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_client_email` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `sender` varchar(255) DEFAULT NULL,
  `recipients` text,
  `subject` varchar(255) DEFAULT NULL,
  `content_html` text,
  `content_text` text,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_content` longblob DEFAULT NULL,
  `attachment_mime` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `activity_client_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_client_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `activity_system`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_system` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `priority` tinyint(4) DEFAULT NULL,
  `admin_id` bigint(20) DEFAULT NULL,
  `client_id` bigint(20) DEFAULT NULL,
  `message` text,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_id_idx` (`admin_id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `system_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `pass` varchar(255) DEFAULT NULL,
  `salt` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'active' COMMENT 'active, inactive',
  `api_token` varchar(128) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `system_name` (`system_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_group`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_group` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `system_name` varchar(100) DEFAULT NULL,
  `parent_id` bigint(20) DEFAULT NULL,
  `permissions` json,
  `protected` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_name` (`system_name`),
  KEY `admin_group_parent_id_idx` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_group_member`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_group_member` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) NOT NULL,
  `admin_group_id` bigint(20) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_group_member_unique` (`admin_id`, `admin_group_id`),
  KEY `admin_group_member_admin_id_idx` (`admin_id`),
  KEY `admin_group_member_group_id_idx` (`admin_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `admin_password_reset`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_password_reset` (
    `id` bigint(20) NOT NULL AUTO_INCREMENT,
    `admin_id` bigint(20) DEFAULT NULL,
    `hash` varchar(100) DEFAULT NULL,
    `ip` varchar(45) DEFAULT NULL,
    `created_at` datetime DEFAULT NULL,
    `updated_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `admin_id_idx` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cart`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` varchar(32) DEFAULT NULL,
  `currency_id` bigint(20) DEFAULT NULL,
  `promo_id` bigint(20) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `session_id_idx` (`session_id`),
  KEY `currency_id_idx` (`currency_id`),
  KEY `promo_id_idx` (`promo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cart_product`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_product` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `cart_id` bigint(20) DEFAULT NULL,
  `product_id` bigint(20) DEFAULT NULL,
  `config` mediumtext,
  PRIMARY KEY (`id`),
  KEY `cart_id_idx` (`cart_id`),
  KEY `product_id_idx` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `aid` varchar(255) DEFAULT NULL COMMENT 'Alternative id for foreign systems',
  `client_group_id` bigint(20) DEFAULT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'client' COMMENT 'client',
  `auth_type` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `billing_email` varchar(255) DEFAULT NULL,
  `pass` varchar(255) DEFAULT NULL,
  `salt` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'active' COMMENT 'active, suspended, canceled',
  `email_approved` tinyint(1) DEFAULT NULL,
  `tax_exempt` tinyint(1) DEFAULT '0',
  `type` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `gender` ENUM('male', 'female', 'nonbinary', 'other') DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `phone_cc` varchar(10) DEFAULT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `company_vat` varchar(100) DEFAULT NULL,
  `company_number` varchar(255) DEFAULT NULL,
  `address_1` varchar(100) DEFAULT NULL,
  `address_2` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postcode` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `notes` mediumtext,
  `currency` varchar(10) DEFAULT NULL,
  `lang` varchar(10) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `api_token` varchar(128) DEFAULT NULL,
  `referred_by` varchar(255) DEFAULT NULL,
  `custom_1` mediumtext,
  `custom_2` mediumtext,
  `custom_3` mediumtext,
  `custom_4` mediumtext,
  `custom_5` mediumtext,
  `custom_6` mediumtext,
  `custom_7` mediumtext,
  `custom_8` mediumtext,
  `custom_9` mediumtext,
  `custom_10` mediumtext,
  `custom_11` mediumtext,
  `custom_12` mediumtext,
  `custom_13` mediumtext,
  `custom_14` mediumtext,
  `custom_15` mediumtext,
  `custom_16` mediumtext,
  `custom_17` mediumtext,
  `custom_18` mediumtext,
  `custom_19` mediumtext,
  `custom_20` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `alternative_id_idx` (`aid`),
  KEY `client_group_id_idx` (`client_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_balance`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_balance` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `rel_id` varchar(20) DEFAULT NULL,
  `invoice_item_id` bigint(20) DEFAULT NULL,
  `amount` decimal(18,2) DEFAULT '0.00',
  `description` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_item_credit` (`invoice_item_id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_group`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_group` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_order`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_order` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `product_id` bigint(20) DEFAULT NULL,
  `form_id` bigint(20) DEFAULT NULL,
  `promo_id` bigint(20) DEFAULT NULL,
  `promo_recurring` tinyint(1) DEFAULT NULL,
  `promo_used` bigint(20) DEFAULT NULL,
  `group_id` varchar(255) DEFAULT NULL,
  `group_master` tinyint(1) DEFAULT '0',
  `invoice_option` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `currency` varchar(20) DEFAULT NULL,
  `unpaid_invoice_id` bigint(20) DEFAULT NULL,
  `service_id` bigint(20) DEFAULT NULL,
  `service_type` varchar(100) DEFAULT NULL,
  `period` varchar(20) DEFAULT NULL,
  `quantity` bigint(20) DEFAULT '1',
  `unit` varchar(100) DEFAULT NULL,
  `price` double(18,2) DEFAULT NULL,
  `discount` double(18,2) DEFAULT NULL COMMENT 'first invoice discount',
  `status` varchar(50) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL COMMENT 'suspend/cancel reason',
  `notes` mediumtext,
  `config` mediumtext,
  `referred_by` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `suspended_at` datetime DEFAULT NULL,
  `unsuspended_at` datetime DEFAULT NULL,
  `canceled_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`),
  KEY `product_id_idx` (`product_id`),
  KEY `form_id_idx` (`form_id`),
  KEY `promo_id_idx` (`promo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_order_meta`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_order_meta` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_order_id` bigint(20) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `value` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_order_id_idx` (`client_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_order_status`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_order_status` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_order_id` bigint(20) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `notes` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_order_id_idx` (`client_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_password_reset`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_password_reset` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `hash` varchar(100) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `currency`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `currency` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(3) DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT '0',
  `conversion_rate` decimal(13,6) DEFAULT '1.000000',
  `is_rate_manual` tinyint(1) DEFAULT '0',
  `format_pattern` varchar(100) DEFAULT NULL,
  `fraction_digits` smallint DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_template`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_template` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `action_code` varchar(255) DEFAULT NULL,
  `category` varchar(30) DEFAULT NULL COMMENT 'general, domain, invoice, hosting, support, download, custom, license',
  `enabled` tinyint(1) DEFAULT '1',
  `is_custom` tinyint(1) DEFAULT '0',
  `is_overridden` tinyint(1) DEFAULT '0' COMMENT 'Whether subject/content have been customized from file defaults',
  `subject` varchar(255) DEFAULT NULL,
  `content` mediumtext,
  `description` mediumtext,
  `vars` mediumtext,
  `last_error` mediumtext DEFAULT NULL,
  `error_checked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `action_code` (`action_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_template_group`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_template_group` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `email_template_id` bigint(20) NOT NULL,
  `admin_group_id` bigint(20) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_template_group_unique` (`email_template_id`, `admin_group_id`),
  KEY `email_template_group_template_id_idx` (`email_template_id`),
  KEY `email_template_group_group_id_idx` (`admin_group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `extension`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `extension` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `type` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `status` varchar(100) DEFAULT NULL,
  `version` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_name` (`type`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `extension_meta`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `extension_meta` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `extension` varchar(255) DEFAULT NULL,
  `rel_type` varchar(255) DEFAULT NULL,
  `rel_id` varchar(255) DEFAULT NULL,
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `form`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `form` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `style` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `form_field`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `form_field` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `form_id` bigint(20) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `label` varchar(255) DEFAULT NULL,
  `hide_label` tinyint(1) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `default_value` varchar(255) DEFAULT NULL,
  `required` tinyint(1) DEFAULT NULL,
  `hidden` tinyint(1) DEFAULT NULL,
  `readonly` tinyint(1) DEFAULT NULL,
  `is_unique` tinyint(1) DEFAULT NULL,
  `prefix` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `options` mediumtext,
  `show_initial` varchar(255) DEFAULT NULL,
  `show_middle` varchar(255) DEFAULT NULL,
  `show_prefix` varchar(255) DEFAULT NULL,
  `show_suffix` varchar(255) DEFAULT NULL,
  `text_size` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `form_id_idx` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `serie` varchar(50) DEFAULT NULL,
  `nr` varchar(255) DEFAULT NULL,
  `hash` varchar(255) DEFAULT NULL COMMENT 'To access via public link',
  `currency` varchar(25) DEFAULT NULL,
  `currency_rate` decimal(13,6) DEFAULT NULL,
  `credit` double(18,2) DEFAULT NULL,
  `base_income` double(18,2) DEFAULT NULL COMMENT 'Income in default currency',
  `base_refund` double(18,2) DEFAULT NULL COMMENT 'Refund in default currency',
  `refund` double(18,2) DEFAULT NULL,
  `notes` mediumtext,
  `text_1` mediumtext,
  `text_2` mediumtext,
  `status` varchar(50) DEFAULT 'unpaid' COMMENT 'paid, unpaid',
  `seller_company` varchar(255) DEFAULT NULL,
  `seller_company_vat` varchar(255) DEFAULT NULL,
  `seller_company_number` varchar(255) DEFAULT NULL,
  `seller_address` varchar(255) DEFAULT NULL,
  `seller_phone` varchar(255) DEFAULT NULL,
  `seller_email` varchar(255) DEFAULT NULL,
  `buyer_first_name` varchar(255) DEFAULT NULL,
  `buyer_last_name` varchar(255) DEFAULT NULL,
  `buyer_company` varchar(255) DEFAULT NULL,
  `buyer_company_vat` varchar(255) DEFAULT NULL,
  `buyer_company_number` varchar(255) DEFAULT NULL,
  `buyer_address` varchar(255) DEFAULT NULL,
  `buyer_city` varchar(255) DEFAULT NULL,
  `buyer_state` varchar(255) DEFAULT NULL,
  `buyer_country` varchar(255) DEFAULT NULL,
  `buyer_zip` varchar(255) DEFAULT NULL,
  `buyer_phone` varchar(255) DEFAULT NULL,
  `buyer_phone_cc` varchar(255) DEFAULT NULL,
  `buyer_email` varchar(255) DEFAULT NULL,
  `gateway_id` int(11) DEFAULT NULL,
  `approved` tinyint(1) DEFAULT '0',
  `taxname` varchar(255) DEFAULT NULL,
  `taxrate` varchar(35) DEFAULT NULL,
  `tax_adjust` double(18,2) NOT NULL DEFAULT '0.00',
  `due_at` datetime DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `hash_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`),
  KEY `client_id_idx` (`client_id`),
  KEY `invoice_status_approved_due_at_idx` (`status`, `approved`, `due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_credit`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_credit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) NOT NULL,
  `client_id` bigint(20) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_credit_invoice_id_idx` (`invoice_id`),
  KEY `invoice_credit_client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_item`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_item` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) DEFAULT NULL,
  `type` varchar(100) DEFAULT NULL,
  `rel_id` mediumtext,
  `task` varchar(100) DEFAULT NULL,
  `status` varchar(100) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `period` varchar(10) DEFAULT NULL,
  `quantity` bigint(20) DEFAULT NULL,
  `unit` varchar(100) DEFAULT NULL,
  `price` double(18,2) DEFAULT NULL,
  `charged` tinyint(1) DEFAULT '0',
  `taxed` tinyint(1) DEFAULT '0',
  `attempts` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_id_idx` (`invoice_id`),
  KEY `invoice_item_pending_renewal_idx` (`rel_id`(20), `type`, `task`, `status`, `invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_kb_article`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_kb_article` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `kb_article_category_id` bigint(20) DEFAULT NULL,
  `views` int(11) DEFAULT '0',
  `title` varchar(100) DEFAULT NULL,
  `content` mediumtext,
  `slug` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'active' COMMENT 'active, draft',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `kb_article_category_id_idx` (`kb_article_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_kb_article_category`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_kb_article_category` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) DEFAULT NULL,
  `description` mediumtext,
  `slug` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_queue`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) NOT NULL,
  `sender` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `content` mediumtext NOT NULL,
  `to_name` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `priority` int(11) DEFAULT NULL,
  `tries` int(11) NOT NULL,
  `status` varchar(20) NOT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `attachment_content` longblob DEFAULT NULL,
  `attachment_mime` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_massmailer`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mod_massmailer` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `from_email` varchar(255) DEFAULT NULL,
  `from_name` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `content` mediumtext,
  `filter` mediumtext,
  `status` varchar(255) DEFAULT NULL,
  `scheduled_at` datetime DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `mod_massmailer_recipient`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mod_massmailer_recipient` (
  `message_id` bigint(20) NOT NULL,
  `client_id` bigint(20) NOT NULL,
  `status` varchar(20) NOT NULL,
  `queue_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`message_id`,`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pay_gateway`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_gateway` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `gateway` varchar(255) DEFAULT NULL,
  `accepted_currencies` mediumtext,
  `enabled` tinyint(1) DEFAULT '1',
  `allow_single` tinyint(1) DEFAULT '1',
  `allow_recurrent` tinyint(1) DEFAULT '1',
  `test_mode` tinyint(1) DEFAULT '0',
  `config` mediumtext,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pay_gateway_customer`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_gateway_customer` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `pay_gateway_id` bigint(20) NOT NULL,
  `client_id` bigint(20) NOT NULL,
  `test_mode` tinyint(1) NOT NULL DEFAULT 0,
  `external_customer_id` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `saved_cards` tinyint(1) NOT NULL DEFAULT 0,
  `default_payment_method` varchar(255) DEFAULT NULL,
  `default_card` varchar(100) DEFAULT NULL,
  `auto_pay_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pay_gateway_customer_gateway_client_mode` (`pay_gateway_id`,`client_id`,`test_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

CREATE TABLE `invoice_card_charge` (
  `invoice_id` bigint(20) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_attempt_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `pay_gateway_product`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_gateway_product` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `pay_gateway_id` bigint(20) NOT NULL,
  `cache_key` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `external_product_id` varchar(255) NOT NULL,
  `external_price_id` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pay_gateway_product_gateway_cache_key` (`pay_gateway_id`,`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `post`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `post` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` mediumtext,
  `content` mediumtext,
  `slug` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'draft' COMMENT 'active, draft',
  `image` varchar(255) DEFAULT NULL,
  `section` varchar(255) DEFAULT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `publish_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `admin_id_idx` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `product_category_id` bigint(20) DEFAULT NULL,
  `product_payment_id` bigint(20) DEFAULT NULL,
  `form_id` bigint(20) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` mediumtext,
  `unit` varchar(50) DEFAULT 'product',
  `active` tinyint(1) DEFAULT '1',
  `status` varchar(50) DEFAULT 'enabled' COMMENT 'enabled, disabled',
  `hidden` tinyint(1) DEFAULT '0',
  `is_addon` tinyint(1) DEFAULT '0',
  `setup` varchar(50) DEFAULT 'after_payment',
  `addons` mediumtext,
  `icon_url` varchar(255) DEFAULT NULL,
  `allow_quantity_select` tinyint(1) DEFAULT '0',
  `stock_control` tinyint(1) DEFAULT '0',
  `quantity_in_stock` int(11) DEFAULT '0',
  `plugin` varchar(255) DEFAULT NULL,
  `plugin_config` mediumtext,
  `upgrades` mediumtext,
  `priority` bigint(20) DEFAULT NULL,
  `config` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `product_type_idx` (`type`),
  KEY `product_category_id_idx` (`product_category_id`),
  KEY `product_payment_id_idx` (`product_payment_id`),
  KEY `form_id_idx` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_category`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_category` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) DEFAULT NULL,
  `description` mediumtext,
  `icon_url` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_payment`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_payment` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `type` varchar(30) DEFAULT NULL COMMENT 'free, once, recurrent',
  `once_price` decimal(18,2) DEFAULT '0.00',
  `once_setup_price` decimal(18,2) DEFAULT '0.00',
  `w_price` decimal(18,2) DEFAULT '0.00',
  `m_price` decimal(18,2) DEFAULT '0.00',
  `q_price` decimal(18,2) DEFAULT '0.00',
  `b_price` decimal(18,2) DEFAULT '0.00',
  `a_price` decimal(18,2) DEFAULT '0.00',
  `bia_price` decimal(18,2) DEFAULT '0.00',
  `tria_price` decimal(18,2) DEFAULT '0.00',
  `w_setup_price` decimal(18,2) DEFAULT '0.00',
  `m_setup_price` decimal(18,2) DEFAULT '0.00',
  `q_setup_price` decimal(18,2) DEFAULT '0.00',
  `b_setup_price` decimal(18,2) DEFAULT '0.00',
  `a_setup_price` decimal(18,2) DEFAULT '0.00',
  `bia_setup_price` decimal(18,2) DEFAULT '0.00',
  `tria_setup_price` decimal(18,2) DEFAULT '0.00',
  `w_enabled` tinyint(1) DEFAULT '0',
  `m_enabled` tinyint(1) DEFAULT '0',
  `q_enabled` tinyint(1) DEFAULT '0',
  `b_enabled` tinyint(1) DEFAULT '0',
  `a_enabled` tinyint(1) DEFAULT '0',
  `bia_enabled` tinyint(1) DEFAULT '0',
  `tria_enabled` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `promo`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `promo` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(100) DEFAULT NULL,
  `description` mediumtext,
  `type` varchar(30) NOT NULL DEFAULT 'percentage' COMMENT 'absolute, percentage, trial',
  `value` decimal(18,2) DEFAULT NULL,
  `maxuses` int(11) DEFAULT '0',
  `used` int(11) DEFAULT '0',
  `freesetup` tinyint(1) DEFAULT '0',
  `once_per_client` tinyint(1) DEFAULT '0',
  `recurring` tinyint(1) DEFAULT '0',
  `active` tinyint(1) DEFAULT '0',
  `products` mediumtext,
  `periods` mediumtext,
  `client_groups` mediumtext,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `start_index_idx` (`start_at`),
  KEY `end_index_idx` (`end_at`),
  KEY `active_index_idx` (`active`),
  KEY `code_index_idx` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `promo_redemption`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `promo_redemption` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `promo_id` bigint(20) DEFAULT NULL,
  `client_id` bigint(20) DEFAULT NULL,
  `client_order_id` bigint(20) DEFAULT NULL,
  `invoice_id` bigint(20) DEFAULT NULL,
  `phase` varchar(30) NOT NULL DEFAULT 'checkout',
  `status` varchar(30) NOT NULL DEFAULT 'reserved',
  `discount_amount` decimal(18,2) DEFAULT NULL,
  `currency` varchar(20) DEFAULT NULL,
  `committed_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `release_reason` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `promo_id_idx` (`promo_id`),
  KEY `client_id_idx` (`client_id`),
  KEY `client_order_id_idx` (`client_order_id`),
  KEY `invoice_id_idx` (`invoice_id`),
  KEY `phase_idx` (`phase`),
  KEY `status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_custom`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_custom` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `plugin` varchar(255) DEFAULT NULL,
  `plugin_config` mediumtext,
  `f1` mediumtext,
  `f2` mediumtext,
  `f3` mediumtext,
  `f4` mediumtext,
  `f5` mediumtext,
  `f6` mediumtext,
  `f7` mediumtext,
  `f8` mediumtext,
  `f9` mediumtext,
  `f10` mediumtext,
  `config` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_domain`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_domain` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `tld_registrar_id` bigint(20) DEFAULT NULL,
  `sld` varchar(255) DEFAULT NULL,
  `tld` varchar(100) DEFAULT NULL,
  `ns1` varchar(255) DEFAULT NULL,
  `ns2` varchar(255) DEFAULT NULL,
  `ns3` varchar(255) DEFAULT NULL,
  `ns4` varchar(255) DEFAULT NULL,
  `period` int(11) DEFAULT NULL,
  `privacy` int(11) DEFAULT NULL,
  `locked` tinyint(1) DEFAULT '1',
  `transfer_code` varchar(255) DEFAULT NULL,
  `action` varchar(30) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_company` varchar(255) DEFAULT NULL,
  `contact_first_name` varchar(255) DEFAULT NULL,
  `contact_last_name` varchar(255) DEFAULT NULL,
  `contact_address1` varchar(255) DEFAULT NULL,
  `contact_address2` varchar(255) DEFAULT NULL,
  `contact_city` varchar(255) DEFAULT NULL,
  `contact_state` varchar(255) DEFAULT NULL,
  `contact_postcode` varchar(255) DEFAULT NULL,
  `contact_country` varchar(255) DEFAULT NULL,
  `contact_phone_cc` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(255) DEFAULT NULL,
  `details` mediumtext,
  `synced_at` datetime DEFAULT NULL,
  `registered_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`),
  KEY `tld_registrar_id_idx` (`tld_registrar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_downloadable`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_downloadable` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `filename` varchar(100) DEFAULT NULL,
  `stored_filename` varchar(100) DEFAULT NULL,
  `downloads` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_hosting`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_hosting` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `service_hosting_server_id` bigint(20) DEFAULT NULL,
  `service_hosting_hp_id` bigint(20) DEFAULT NULL,
  -- ClientFence core patch: the panel subscription identifies a service after its primary domain changes.
  `panel_org_id` varchar(64) DEFAULT NULL,
  `panel_subscription_id` bigint(20) DEFAULT NULL,
  `sld` varchar(255) DEFAULT NULL,
  `tld` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `pass` varchar(255) DEFAULT NULL,
  `reseller` tinyint(1) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`),
  KEY `service_hosting_server_id_idx` (`service_hosting_server_id`),
  KEY `service_hosting_hp_id_idx` (`service_hosting_hp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_hosting_hp`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_hosting_hp` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `quota` varchar(50) DEFAULT NULL,
  `bandwidth` varchar(50) DEFAULT NULL,
  `max_ftp` varchar(50) DEFAULT NULL,
  `max_sql` varchar(50) DEFAULT NULL,
  `max_pop` varchar(50) DEFAULT NULL,
  `max_sub` varchar(50) DEFAULT NULL,
  `max_park` varchar(50) DEFAULT NULL,
  `max_addon` varchar(50) DEFAULT NULL,
  `config` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_hosting_server`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_hosting_server` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `hostname` varchar(100) DEFAULT NULL,
  `assigned_ips` mediumtext,
  `status_url` varchar(255) DEFAULT NULL,
  `active` tinyint(1) DEFAULT NULL,
  `max_accounts` bigint(20) DEFAULT NULL,
  `ns1` varchar(100) DEFAULT NULL,
  `ns2` varchar(100) DEFAULT NULL,
  `ns3` varchar(100) DEFAULT NULL,
  `ns4` varchar(100) DEFAULT NULL,
  `manager` varchar(100) DEFAULT NULL,
  `username` mediumtext,
  `password` mediumtext,
  `accesshash` mediumtext,
  `password_length` tinyint(4) DEFAULT NULL,
  `port` varchar(20) DEFAULT NULL,
  `config` mediumtext,
  `secure` tinyint(1) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `service_license`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_license` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `license_key` varchar(255) DEFAULT NULL,
  `validate_ip` tinyint(1) DEFAULT '1',
  `validate_host` tinyint(1) DEFAULT '1',
  `validate_path` tinyint(1) DEFAULT '0',
  `validate_version` tinyint(1) DEFAULT '0',
  `ips` mediumtext,
  `hosts` mediumtext,
  `paths` mediumtext,
  `versions` mediumtext,
  `config` mediumtext,
  `plugin` varchar(255) DEFAULT NULL,
  `checked_at` datetime DEFAULT NULL,
  `pinged_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `license_key` (`license_key`),
  KEY `client_id_idx` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `session`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `session` (
  `id` varchar(32) NOT NULL DEFAULT '',
  `modified_at` int(11) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  `content` mediumtext,
  `fingerprint` mediumtext,
  UNIQUE KEY `unique_id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `setting`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `param` varchar(255) DEFAULT NULL,
  `value` mediumtext,
  `public` tinyint(1) DEFAULT '0',
  `category` varchar(255) DEFAULT NULL,
  `hash` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `param` (`param`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `subscription`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) DEFAULT NULL,
  `pay_gateway_id` bigint(20) DEFAULT NULL,
  `sid` varchar(255) DEFAULT NULL,
  `rel_type` varchar(100) DEFAULT NULL,
  `rel_id` bigint(20) DEFAULT NULL,
  `period` varchar(255) DEFAULT NULL,
  `amount` double(18,2) DEFAULT NULL,
  `currency` varchar(50) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id_idx` (`client_id`),
  KEY `pay_gateway_id_idx` (`pay_gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_helpdesk`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_helpdesk` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `close_after` smallint(6) DEFAULT '24',
  `can_reopen` tinyint(1) DEFAULT '0',
  `signature` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_pr`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_pr` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `support_pr_category_id` bigint(20) DEFAULT NULL,
  `title` varchar(100) DEFAULT NULL,
  `content` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_pr_category_id_idx` (`support_pr_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_pr_category`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_pr_category` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_ticket`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_ticket` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `support_helpdesk_id` bigint(20) DEFAULT NULL,
  `client_id` bigint(20) DEFAULT NULL,
  `access_hash` varchar(255) DEFAULT NULL,
  `author_name` varchar(255) DEFAULT NULL,
  `author_email` varchar(255) DEFAULT NULL,
  `priority` int(11) DEFAULT '100',
  `subject` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT 'open' COMMENT 'open, closed, on_hold',
  `rel_type` varchar(100) DEFAULT NULL,
  `rel_id` bigint(20) DEFAULT NULL,
  `rel_task` varchar(100) DEFAULT NULL,
  `rel_new_value` mediumtext,
  `rel_status` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_helpdesk_id_idx` (`support_helpdesk_id`),
  KEY `client_id_idx` (`client_id`),
  KEY `access_hash_idx` (`access_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_ticket_message`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_ticket_message` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `support_ticket_id` bigint(20) DEFAULT NULL,
  `client_id` bigint(20) DEFAULT NULL,
  `admin_id` bigint(20) DEFAULT NULL,
  `content` mediumtext,
  `attachment` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_ticket_id_idx` (`support_ticket_id`),
  KEY `client_id_idx` (`client_id`),
  KEY `admin_id_idx` (`admin_id`),
  FULLTEXT KEY `content_idx` (`content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_ticket_message_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_ticket_message_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `support_ticket_message_id` bigint(20) NOT NULL,
  `admin_id` bigint(20) DEFAULT NULL,
  `content` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_ticket_message_id_idx` (`support_ticket_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `support_ticket_note`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `support_ticket_note` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `support_ticket_id` bigint(20) DEFAULT NULL,
  `admin_id` bigint(20) DEFAULT NULL,
  `note` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_ticket_id_idx` (`support_ticket_id`),
  KEY `admin_id_idx` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `level` bigint(20) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `taxrate` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tld`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tld` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tld_registrar_id` bigint(20) DEFAULT NULL,
  `tld` varchar(64) DEFAULT NULL,
  `price_registration` decimal(18,2) DEFAULT '0.00',
  `price_renew` decimal(18,2) DEFAULT '0.00',
  `price_transfer` decimal(18,2) DEFAULT '0.00',
  `allow_register` tinyint(1) DEFAULT NULL,
  `allow_transfer` tinyint(1) DEFAULT NULL,
  `require_transfer_code` tinyint(1) DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1',
  `min_years` tinyint(4) DEFAULT NULL,
  `periods` varchar(255) DEFAULT NULL,
  `price_locked` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tld` (`tld`),
  KEY `tld_registrar_id_idx` (`tld_registrar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tld_registrar`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tld_registrar` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `registrar` varchar(255) DEFAULT NULL,
  `test_mode` tinyint(4) DEFAULT '0',
  `config` mediumtext,
  `price_sync` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transaction`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) DEFAULT NULL,
  `gateway_id` int(11) DEFAULT NULL,
  `txn_id` varchar(255) DEFAULT NULL,
  `txn_status` varchar(255) DEFAULT NULL,
  `s_id` varchar(255) DEFAULT NULL,
  `s_period` varchar(255) DEFAULT NULL,
  `amount` varchar(255) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT 'received',
  `ip` varchar(45) DEFAULT NULL,
  `error` mediumtext,
  `error_code` int(11) DEFAULT NULL,
  `validate_ipn` tinyint(1) DEFAULT '1',
  `ipn` mediumtext,
  `ipn_hash` varchar(64) DEFAULT NULL,
  `output` mediumtext,
  `note` mediumtext,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_id_idx` (`invoice_id`),
  KEY `transaction_ipn_hash_idx` (`gateway_id`, `ipn_hash`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
--
-- Table structure for table `mod_clientfence_helpdesk` (ClientFence)
--

CREATE TABLE `mod_clientfence_helpdesk` (`helpdesk_id` bigint(20) NOT NULL, `greeting` text NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`helpdesk_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `mod_clientfence_state` (ClientFence)
--

CREATE TABLE `mod_clientfence_state` (`name` varchar(64) NOT NULL, `value` text NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_user` (ClientFence)
--

CREATE TABLE `client_user` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `email` varchar(255) NOT NULL, `first_name` varchar(100) NOT NULL DEFAULT '', `last_name` varchar(100) NOT NULL DEFAULT '', `pass` varchar(255) NOT NULL, `lang` varchar(10) DEFAULT NULL, `status` varchar(20) NOT NULL DEFAULT 'active', `session_version` int(10) unsigned NOT NULL DEFAULT 1, `totp_secret` varchar(255) DEFAULT NULL, `totp_backup` varchar(255) DEFAULT NULL, `totp_enabled_at` datetime DEFAULT NULL, `last_login_at` datetime DEFAULT NULL, `last_ip` varchar(45) DEFAULT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `email` (`email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_user_account` (ClientFence)
--

CREATE TABLE `client_user_account` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint(20) unsigned NOT NULL, `client_id` bigint(20) NOT NULL, `owner` tinyint(1) NOT NULL DEFAULT 0, `permissions` varchar(500) NOT NULL DEFAULT '', `last_login_at` datetime DEFAULT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `user_client` (`user_id`, `client_id`), KEY `client_id` (`client_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_user_password_reset` (ClientFence)
--

CREATE TABLE `client_user_password_reset` (`token_hash` char(64) NOT NULL, `user_id` bigint(20) unsigned NOT NULL, `created_at` datetime NOT NULL, `expires_at` datetime NOT NULL, PRIMARY KEY (`token_hash`), KEY `user_id` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_user_invite` (ClientFence)
--

CREATE TABLE `client_user_invite` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `client_id` bigint(20) NOT NULL, `email` varchar(255) NOT NULL, `permissions` varchar(500) NOT NULL DEFAULT '', `token_hash` char(64) NOT NULL, `created_at` datetime NOT NULL, `expires_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `token_hash` (`token_hash`), UNIQUE KEY `client_email` (`client_id`, `email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_author` (ClientFence)
--

CREATE TABLE `support_ticket_author` (`message_id` bigint(20) NOT NULL, `ticket_id` bigint(20) NOT NULL, `client_id` bigint(20) NOT NULL, `user_id` bigint(20) unsigned NOT NULL, `name` varchar(201) NOT NULL DEFAULT '', `created_at` datetime NOT NULL, PRIMARY KEY (`message_id`), KEY `ticket_id` (`ticket_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_user_invite_log` (ClientFence)
--

CREATE TABLE `client_user_invite_log` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `client_id` bigint(20) NOT NULL, `sent_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `client_sent` (`client_id`, `sent_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_twofactor` (ClientFence)
--

CREATE TABLE `client_twofactor` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `kind` varchar(10) NOT NULL, `subject_id` bigint(20) unsigned NOT NULL, `secret` text NOT NULL, `backup_hash` varchar(255) DEFAULT NULL, `last_step` bigint(20) NOT NULL DEFAULT 0, `enabled_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `login` (`kind`,`subject_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_file` (ClientFence)
--

CREATE TABLE `support_ticket_file` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `token` char(32) NOT NULL, `ticket_id` bigint(20) unsigned DEFAULT NULL, `message_id` bigint(20) unsigned DEFAULT NULL, `client_id` bigint(20) unsigned NOT NULL, `uploader_kind` varchar(10) NOT NULL, `uploader_id` bigint(20) unsigned NOT NULL, `session_hash` char(64) NOT NULL, `name` varchar(191) NOT NULL, `mime` varchar(100) NOT NULL, `size` int(10) unsigned NOT NULL, `created_at` datetime NOT NULL, `attached_at` datetime DEFAULT NULL, `removed_at` datetime DEFAULT NULL, `removed_by` bigint(20) unsigned DEFAULT NULL, `removed_reason` varchar(20) DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `token` (`token`), KEY `message` (`message_id`), KEY `ticket` (`ticket_id`), KEY `client` (`client_id`), KEY `waiting` (`session_hash`, `message_id`), KEY `uploader` (`uploader_kind`, `uploader_id`, `created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_google` (ClientFence)
--

CREATE TABLE `client_google` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `kind` varchar(10) NOT NULL, `subject_id` bigint(20) unsigned NOT NULL, `google_sub` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `email` varchar(191) NOT NULL DEFAULT '', `created_at` datetime NOT NULL, `last_used_at` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `google` (`google_sub`), UNIQUE KEY `login` (`kind`,`subject_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_google_signup` (ClientFence)
--

CREATE TABLE `client_google_signup` (`client_id` bigint(20) unsigned NOT NULL, `terms_url` varchar(300) NOT NULL, `privacy_url` varchar(300) NOT NULL, `ip` varchar(45) NOT NULL DEFAULT '', `accepted_at` datetime NOT NULL, PRIMARY KEY (`client_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_google_password` (ClientFence)
--

CREATE TABLE `client_google_password` (`client_id` bigint(20) unsigned NOT NULL, `pass_check` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`client_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_order_action_failure` (ClientFence)
--

CREATE TABLE `client_order_action_failure` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `client_order_id` bigint(20) NOT NULL, `action` varchar(20) NOT NULL, `message` text NOT NULL, `attempts` int(10) unsigned NOT NULL DEFAULT 1, `status` varchar(20) NOT NULL DEFAULT 'open', `first_failed_at` datetime NOT NULL, `last_failed_at` datetime NOT NULL, `resolved_at` datetime DEFAULT NULL, `resolved_by` bigint(20) DEFAULT NULL, PRIMARY KEY (`id`), KEY `status` (`status`, `last_failed_at`), KEY `order_action` (`client_order_id`, `action`, `status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_order_cancel_request` (ClientFence)
--

CREATE TABLE `client_order_cancel_request` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `client_order_id` bigint(20) NOT NULL, `client_id` bigint(20) NOT NULL, `type` varchar(20) NOT NULL, `reason` text NOT NULL, `status` varchar(20) NOT NULL DEFAULT 'pending', `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `client_order_cancel_request_order_idx` (`client_order_id`, `status`), KEY `client_order_cancel_request_status_idx` (`status`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_helpdesk_mail` (ClientFence)
--

CREATE TABLE `support_helpdesk_mail` (`helpdesk_id` bigint(20) NOT NULL, `enabled` tinyint(1) NOT NULL DEFAULT 0, `host` varchar(255) NOT NULL DEFAULT '', `port` int(10) unsigned NOT NULL DEFAULT 993, `encryption` varchar(10) NOT NULL DEFAULT 'ssl', `verify_tls` tinyint(1) NOT NULL DEFAULT 1, `username` varchar(255) NOT NULL DEFAULT '', `password` text, `folder` varchar(255) NOT NULL DEFAULT 'INBOX', `clients_only` tinyint(1) NOT NULL DEFAULT 0, `replies_only` tinyint(1) NOT NULL DEFAULT 0, `max_per_run` int(10) unsigned NOT NULL DEFAULT 25, `last_run_at` datetime DEFAULT NULL, `last_ok` tinyint(1) DEFAULT NULL, `last_result` varchar(500) DEFAULT NULL, `processing_uid` bigint(20) unsigned DEFAULT NULL, `processing_tries` tinyint(3) unsigned NOT NULL DEFAULT 0, `updated_at` datetime NOT NULL, PRIMARY KEY (`helpdesk_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_mail_import` (ClientFence)
--

CREATE TABLE `support_mail_import` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `helpdesk_id` bigint(20) DEFAULT NULL, `created_at` datetime NOT NULL, `from_email` varchar(255) NOT NULL DEFAULT '', `from_name` varchar(255) NOT NULL DEFAULT '', `to_email` varchar(255) NOT NULL DEFAULT '', `subject` varchar(255) NOT NULL DEFAULT '', `message_id` varchar(255) NOT NULL DEFAULT '', `status` varchar(30) NOT NULL, `note` varchar(500) NOT NULL DEFAULT '', `ticket_id` bigint(20) DEFAULT NULL, `raw` longblob, `imported_by` bigint(20) DEFAULT NULL, `imported_at` datetime DEFAULT NULL, PRIMARY KEY (`id`), KEY `sender` (`from_email`(191), `created_at`), KEY `message` (`message_id`(191)), KEY `created` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `email_queue_reply_to` (ClientFence)
--

CREATE TABLE `email_queue_reply_to` (`queue_id` bigint(20) NOT NULL, `reply_to` varchar(255) NOT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`queue_id`), KEY `created` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_merge` (ClientFence)
--

CREATE TABLE `support_ticket_merge` (`ticket_id` bigint(20) NOT NULL, `merged_into` bigint(20) NOT NULL, `access_hash` varchar(255) DEFAULT NULL, `subject` varchar(255) NOT NULL DEFAULT '', `merged_by` bigint(20) DEFAULT NULL, `merged_at` datetime NOT NULL, PRIMARY KEY (`ticket_id`), KEY `merged_into` (`merged_into`), KEY `access_hash` (`access_hash`(191))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_order_upgrade` (ClientFence)
--

CREATE TABLE `client_order_upgrade` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `order_id` bigint(20) NOT NULL, `client_id` bigint(20) NOT NULL, `kind` varchar(20) NOT NULL, `from_product_id` bigint(20) NOT NULL, `from_title` varchar(255) NOT NULL DEFAULT '', `from_period` varchar(10) NOT NULL, `from_price` decimal(18,2) NOT NULL, `from_expires_at` datetime NOT NULL, `to_product_id` bigint(20) NOT NULL, `to_title` varchar(255) NOT NULL DEFAULT '', `to_period` varchar(10) NOT NULL, `to_price` decimal(18,2) NOT NULL, `to_expires_at` datetime NOT NULL, `days_left` int(11) NOT NULL, `days_total` int(11) NOT NULL, `credit` decimal(18,2) NOT NULL, `charge` decimal(18,2) NOT NULL, `amount` decimal(18,2) NOT NULL, `currency` varchar(10) NOT NULL DEFAULT '', `invoice_id` bigint(20) DEFAULT NULL, `status` varchar(20) NOT NULL DEFAULT 'pending', `error` text, `created_at` datetime NOT NULL, `claimed_at` datetime DEFAULT NULL, `applied_at` datetime DEFAULT NULL, PRIMARY KEY (`id`), KEY `order_status` (`order_id`, `status`), KEY `status` (`status`), KEY `invoice` (`invoice_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_order_upgrade_rule` (ClientFence)
--

CREATE TABLE `client_order_upgrade_rule` (`scope` varchar(10) NOT NULL, `ref_id` bigint(20) NOT NULL, `upgrade` tinyint(1) DEFAULT NULL, `downgrade` tinyint(1) DEFAULT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`scope`, `ref_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_pin` (ClientFence)
--

CREATE TABLE `support_ticket_pin` (`ticket_id` bigint(20) NOT NULL, `pinned_by` bigint(20) DEFAULT NULL, `pinned_at` datetime NOT NULL, PRIMARY KEY (`ticket_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_assignment` (ClientFence)
--

CREATE TABLE `support_ticket_assignment` (`ticket_id` bigint(20) NOT NULL, `admin_id` bigint(20) NOT NULL, `assigned_by` bigint(20) DEFAULT NULL, `assigned_at` datetime NOT NULL, PRIMARY KEY (`ticket_id`), KEY `admin` (`admin_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_message_rating` (ClientFence)
--

CREATE TABLE `support_ticket_message_rating` (`message_id` bigint(20) NOT NULL, `ticket_id` bigint(20) NOT NULL, `admin_id` bigint(20) NOT NULL, `rating` tinyint(3) unsigned NOT NULL, `rated_by_kind` varchar(10) NOT NULL, `rated_by_id` bigint(20) NOT NULL DEFAULT 0, `ip` varchar(45) NOT NULL DEFAULT '', `rated_at` datetime NOT NULL, PRIMARY KEY (`message_id`), KEY `ticket` (`ticket_id`), KEY `admin_rated` (`admin_id`, `rated_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_message_unverified` (ClientFence)
--

CREATE TABLE `support_ticket_message_unverified` (`message_id` bigint(20) NOT NULL, `ticket_id` bigint(20) NOT NULL, `sender` varchar(255) NOT NULL DEFAULT '', `reason` varchar(255) NOT NULL DEFAULT '', `created_at` datetime NOT NULL, PRIMARY KEY (`message_id`), KEY `ticket` (`ticket_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `client_marketing_optout` (ClientFence)
--

CREATE TABLE `client_marketing_optout` (`client_id` bigint(20) NOT NULL, `source` varchar(20) NOT NULL DEFAULT '', `opted_out_at` datetime NOT NULL, PRIMARY KEY (`client_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `email_queue_unsubscribe` (ClientFence)
--

CREATE TABLE `email_queue_unsubscribe` (`queue_id` bigint(20) NOT NULL, `url` varchar(500) NOT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`queue_id`), KEY `created` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_feedback` (ClientFence)
--

CREATE TABLE `support_ticket_feedback` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `ticket_id` bigint(20) NOT NULL, `admin_id` bigint(20) DEFAULT NULL, `rating` tinyint(3) unsigned DEFAULT NULL, `comments` text NOT NULL, `ip` varchar(45) NOT NULL DEFAULT '', `created_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `ticket` (`ticket_id`), KEY `admin_created` (`admin_id`, `created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_ticket_feedback_request` (ClientFence)
--

CREATE TABLE `support_ticket_feedback_request` (`ticket_id` bigint(20) NOT NULL, `token` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `sent_at` datetime NOT NULL, `answered_at` datetime DEFAULT NULL, PRIMARY KEY (`ticket_id`), UNIQUE KEY `token` (`token`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_helpdesk_feedback` (ClientFence)
--

CREATE TABLE `support_helpdesk_feedback` (`helpdesk_id` bigint(20) NOT NULL, `feedback_request` tinyint(1) NOT NULL DEFAULT 1, `updated_at` datetime NOT NULL, PRIMARY KEY (`helpdesk_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_helpdesk_hidden` (ClientFence)
--

CREATE TABLE `support_helpdesk_hidden` (`helpdesk_id` bigint(20) NOT NULL, `hidden_at` datetime NOT NULL, PRIMARY KEY (`helpdesk_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_kb_category_hidden` (ClientFence)
--

CREATE TABLE `support_kb_category_hidden` (`category_id` bigint(20) NOT NULL, `hidden_at` datetime NOT NULL, PRIMARY KEY (`category_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_escalation_rule` (ClientFence)
--

CREATE TABLE `support_escalation_rule` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `name` varchar(100) NOT NULL, `active` tinyint(1) NOT NULL DEFAULT 1, `helpdesks` text NOT NULL, `statuses` text NOT NULL, `priorities` text NOT NULL, `minutes` int(10) unsigned NOT NULL, `new_helpdesk_id` bigint(20) DEFAULT NULL, `new_status` varchar(30) NOT NULL DEFAULT '', `new_priority` varchar(10) NOT NULL DEFAULT '', `assign_to` bigint(20) DEFAULT NULL, `notify` text NOT NULL, `reply` text NOT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_escalation_applied` (ClientFence)
--

CREATE TABLE `support_escalation_applied` (`rule_id` bigint(20) unsigned NOT NULL, `ticket_id` bigint(20) NOT NULL, `reply_at` datetime NOT NULL, `applied_at` datetime NOT NULL, PRIMARY KEY (`rule_id`, `ticket_id`), KEY `ticket` (`ticket_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--
-- Table structure for table `invoice_quote` (ClientFence)
--

CREATE TABLE `invoice_quote` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `hash` char(40) NOT NULL, `client_id` bigint(20) DEFAULT NULL, `first_name` varchar(100) NOT NULL DEFAULT '', `last_name` varchar(100) NOT NULL DEFAULT '', `company` varchar(100) NOT NULL DEFAULT '', `email` varchar(255) NOT NULL DEFAULT '', `country` varchar(2) NOT NULL DEFAULT '', `currency` char(3) NOT NULL, `subject` varchar(255) NOT NULL, `stage` varchar(20) NOT NULL DEFAULT 'draft', `valid_until` date DEFAULT NULL, `proposal` text NOT NULL, `customer_notes` text NOT NULL, `admin_notes` text NOT NULL, `taxname` varchar(100) NOT NULL DEFAULT '', `taxrate` decimal(5,2) NOT NULL DEFAULT 0.00, `invoice_id` bigint(20) DEFAULT NULL, `created_by` bigint(20) DEFAULT NULL, `sent_at` datetime DEFAULT NULL, `accepted_at` datetime DEFAULT NULL, `declined_at` datetime DEFAULT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `hash` (`hash`), KEY `client` (`client_id`), KEY `stage` (`stage`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `invoice_quote_item` (ClientFence)
--

CREATE TABLE `invoice_quote_item` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `quote_id` bigint(20) unsigned NOT NULL, `position` int(10) unsigned NOT NULL DEFAULT 0, `description` text NOT NULL, `quantity` int(10) unsigned NOT NULL DEFAULT 1, `price` decimal(18,2) NOT NULL DEFAULT 0.00, `discount` decimal(5,2) NOT NULL DEFAULT 0.00, `taxed` tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `quote` (`quote_id`, `position`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `support_network_issue` (ClientFence)
--

CREATE TABLE `support_network_issue` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `description` text NOT NULL, `type` varchar(10) NOT NULL DEFAULT 'server', `affecting` varchar(100) NOT NULL DEFAULT '', `server_id` bigint(20) DEFAULT NULL, `priority` varchar(10) NOT NULL DEFAULT 'medium', `status` varchar(20) NOT NULL DEFAULT 'reported', `start_at` datetime NOT NULL, `end_at` datetime DEFAULT NULL, `emailed_at` datetime DEFAULT NULL, `emailed_count` int(10) unsigned NOT NULL DEFAULT 0, `created_by` bigint(20) DEFAULT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `status` (`status`), KEY `server` (`server_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_option_group` (ClientFence)
--

CREATE TABLE `product_option_group` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `title` varchar(255) NOT NULL, `description` text NOT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_option_group_product` (ClientFence)
--

CREATE TABLE `product_option_group_product` (`group_id` bigint(20) unsigned NOT NULL, `product_id` bigint(20) NOT NULL, PRIMARY KEY (`group_id`, `product_id`), KEY `product` (`product_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_option` (ClientFence)
--

CREATE TABLE `product_option` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `group_id` bigint(20) unsigned NOT NULL, `title` varchar(255) NOT NULL, `code` varchar(100) NOT NULL DEFAULT '', `type` varchar(10) NOT NULL, `qty_min` int(10) unsigned NOT NULL DEFAULT 0, `qty_max` int(10) unsigned NOT NULL DEFAULT 0, `unit` varchar(50) NOT NULL DEFAULT '', `hidden` tinyint(1) NOT NULL DEFAULT 0, `position` int(10) unsigned NOT NULL DEFAULT 0, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `group` (`group_id`, `position`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_option_value` (ClientFence)
--

CREATE TABLE `product_option_value` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `option_id` bigint(20) unsigned NOT NULL, `title` varchar(255) NOT NULL, `code` varchar(100) NOT NULL DEFAULT '', `hidden` tinyint(1) NOT NULL DEFAULT 0, `position` int(10) unsigned NOT NULL DEFAULT 0, `once_price` decimal(18,2) NOT NULL DEFAULT 0.00, `once_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `w_price` decimal(18,2) NOT NULL DEFAULT 0.00, `w_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `m_price` decimal(18,2) NOT NULL DEFAULT 0.00, `m_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `q_price` decimal(18,2) NOT NULL DEFAULT 0.00, `q_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `b_price` decimal(18,2) NOT NULL DEFAULT 0.00, `b_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `a_price` decimal(18,2) NOT NULL DEFAULT 0.00, `a_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `bia_price` decimal(18,2) NOT NULL DEFAULT 0.00, `bia_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `tria_price` decimal(18,2) NOT NULL DEFAULT 0.00, `tria_setup_price` decimal(18,2) NOT NULL DEFAULT 0.00, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `option` (`option_id`, `position`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `promo_option` (ClientFence)
--

CREATE TABLE `promo_option` (`promo_id` bigint(20) NOT NULL, `include_options` tinyint(1) NOT NULL DEFAULT 0, `updated_at` datetime NOT NULL, PRIMARY KEY (`promo_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `client_order_upgrade_option` (ClientFence)
--

CREATE TABLE `client_order_upgrade_option` (`upgrade_id` bigint(20) unsigned NOT NULL, `from_options` text NOT NULL, `to_options` text NOT NULL, `to_option_lines` text NOT NULL, PRIMARY KEY (`upgrade_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- --------------------------------------------------------

--
-- Table structure for table `client_contact` (ClientFence)
--

CREATE TABLE `client_contact` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `client_id` bigint(20) NOT NULL, `first_name` varchar(100) NOT NULL DEFAULT '', `last_name` varchar(100) NOT NULL DEFAULT '', `company` varchar(100) NOT NULL DEFAULT '', `email` varchar(255) NOT NULL, `phone_cc` varchar(10) NOT NULL DEFAULT '', `phone` varchar(100) NOT NULL DEFAULT '', `address_1` varchar(100) NOT NULL DEFAULT '', `address_2` varchar(100) NOT NULL DEFAULT '', `city` varchar(100) NOT NULL DEFAULT '', `state` varchar(100) NOT NULL DEFAULT '', `postcode` varchar(100) NOT NULL DEFAULT '', `country` varchar(100) NOT NULL DEFAULT '', `tax_id` varchar(100) NOT NULL DEFAULT '', `emails` varchar(100) NOT NULL DEFAULT '', `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `client_id` (`client_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- --------------------------------------------------------

--
-- Table structure for table `product_currency_price` (ClientFence)
--

CREATE TABLE `product_currency_price` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `kind` varchar(20) NOT NULL, `ref_id` bigint(20) NOT NULL, `currency` varchar(3) NOT NULL, `period` varchar(10) NOT NULL, `price` decimal(18,2) DEFAULT NULL, `setup_price` decimal(18,2) DEFAULT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `item_currency_period` (`kind`, `ref_id`, `currency`, `period`), KEY `currency` (`currency`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_template_translation` (ClientFence)
--

CREATE TABLE `email_template_translation` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `email_template_id` bigint(20) NOT NULL, `lang` varchar(10) NOT NULL, `subject` varchar(255) NOT NULL, `content` mediumtext NOT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `template_lang` (`email_template_id`, `lang`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_translation` (ClientFence)
--

CREATE TABLE `product_translation` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `kind` varchar(20) NOT NULL, `ref_id` bigint(20) NOT NULL, `lang` varchar(10) NOT NULL, `title` varchar(255) DEFAULT NULL, `description` mediumtext DEFAULT NULL, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `item_lang` (`kind`, `ref_id`, `lang`), KEY `lang` (`lang`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `service_license_log` (ClientFence)
--

CREATE TABLE `service_license_log` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `service_license_id` bigint(20) NOT NULL, `host` varchar(255) NOT NULL DEFAULT '', `ip` varchar(255) NOT NULL DEFAULT '', `path` varchar(255) NOT NULL DEFAULT '', `version` varchar(100) NOT NULL DEFAULT '', `result` varchar(255) NOT NULL DEFAULT '', `created_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `license_date` (`service_license_id`, `created_at`), KEY `created_at` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `service_license_ban` (ClientFence)
--

CREATE TABLE `service_license_ban` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `value` varchar(255) NOT NULL, `notes` text NOT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `value` (`value`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `mod_clientfence_import_map` (ClientFence)
--

CREATE TABLE `mod_clientfence_import_map` (`source` varchar(20) NOT NULL, `entity` varchar(30) NOT NULL, `source_id` varchar(64) NOT NULL, `target_id` bigint(20) NOT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`source`, `entity`, `source_id`), KEY `target` (`entity`, `target_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- --------------------------------------------------------

--
-- Table structure for table `mod_clientfence_import_warning` (ClientFence)
--

CREATE TABLE `mod_clientfence_import_warning` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `source` varchar(20) NOT NULL, `step` varchar(30) NOT NULL, `message` text NOT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `source` (`source`, `id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- --------------------------------------------------------

--
-- Table structure for table `mod_clientfence_notification` (ClientFence)
--

CREATE TABLE `mod_clientfence_notification` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `audience` varchar(10) NOT NULL, `recipient_id` bigint(20) NOT NULL, `kind` varchar(40) NOT NULL, `params` text NOT NULL, `url` varchar(255) NOT NULL, `dedupe` varchar(120) DEFAULT NULL, `created_at` datetime NOT NULL, `read_at` datetime DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `dedupe` (`audience`, `recipient_id`, `dedupe`), KEY `recipient` (`audience`, `recipient_id`, `read_at`, `id`), KEY `created` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ClientFence core patch: private client notes and documents.
CREATE TABLE `client_note` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `client_id` bigint(20) NOT NULL, `admin_id` bigint(20) NOT NULL, `note` text NOT NULL, `pinned` tinyint(1) NOT NULL DEFAULT 0, `created_at` datetime NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `client_notes` (`client_id`, `pinned`, `created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `client_file` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `client_id` bigint(20) NOT NULL, `admin_id` bigint(20) NOT NULL, `name` varchar(191) NOT NULL, `stored_name` char(32) NOT NULL, `size` int(10) unsigned NOT NULL, `mime` varchar(100) NOT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `stored_name` (`stored_name`), KEY `client_files` (`client_id`, `created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ClientFence core patch: encrypted ticket secrets and their retained audit events.
CREATE TABLE `support_ticket_secret` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `ticket_id` bigint(20) NOT NULL, `kind` varchar(10) NOT NULL, `label` varchar(100) NOT NULL, `data` text NOT NULL, `added_by_client_id` bigint(20) DEFAULT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `ticket` (`ticket_id`), KEY `created` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `support_ticket_secret_event` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `ticket_id` bigint(20) NOT NULL, `secret_label` varchar(100) NOT NULL, `action` varchar(10) NOT NULL, `admin_id` bigint(20) DEFAULT NULL, `client_id` bigint(20) DEFAULT NULL, `created_at` datetime NOT NULL, PRIMARY KEY (`id`), KEY `ticket` (`ticket_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ClientFence core patch: the client's Support PIN (Client/SupportPin.php).
CREATE TABLE `client_support_pin` (`client_id` bigint(20) NOT NULL, `pin` char(8) NOT NULL, PRIMARY KEY (`client_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `tld_registrar_cost` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `tld_registrar_id` bigint(20) NOT NULL, `tld` varchar(64) NOT NULL, `currency` char(3) NOT NULL, `cost_register` decimal(18,4) DEFAULT NULL, `cost_renew` decimal(18,4) DEFAULT NULL, `cost_transfer` decimal(18,4) DEFAULT NULL, `applied_register` decimal(18,4) DEFAULT NULL, `applied_renew` decimal(18,4) DEFAULT NULL, `applied_transfer` decimal(18,4) DEFAULT NULL, `applied_rate` decimal(18,8) DEFAULT NULL, `fetched_at` datetime NOT NULL, PRIMARY KEY (`id`), UNIQUE KEY `tld_registrar_cost_tld` (`tld_registrar_id`, `tld`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2014-09-19 15:00:47

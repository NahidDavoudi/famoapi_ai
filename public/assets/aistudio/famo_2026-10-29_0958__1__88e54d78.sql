-- MySQL dump 10.13  Distrib 8.4.11, for Linux (x86_64)
--
-- Host: localhost    Database: famo
-- ------------------------------------------------------
-- Server version	8.4.11

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL COMMENT 'FK به students.id',
  `appointment_date` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'تاریخ جلسه (مثلاً ۱۴۰۴-۰۱-۲۵)',
  `start_time` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ساعت شروع',
  `end_time` time DEFAULT NULL COMMENT 'ساعت پایان (اختیاری، می‌تواند NULL باشد یا همان ساعت شروع ذخیره شود)',
  `status` enum('pending','accepted','rejected','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'وضعیت نوبت',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'نوع: exam, consultation, private_class (برای گسترش آینده)',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'توضیح اختیاری (مثلاً عنوان آزمون یا نام مشاور)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (5,19,'30 اردیبهشت','10',NULL,'pending',NULL,NULL,'2026-04-26 09:48:06','2026-04-26 09:48:06'),(6,19,'30 اردیبهشت','10',NULL,'rejected',NULL,NULL,'2026-04-26 09:55:21','2026-04-26 10:32:20'),(7,25,'30 اردیبهشت','10',NULL,'pending',NULL,NULL,'2026-04-26 10:01:24','2026-04-26 10:01:24'),(8,19,'30 اردیبهشت','10',NULL,'pending',NULL,NULL,'2026-04-26 10:04:20','2026-04-26 10:04:20'),(9,28,'30 اردیبهشت','10',NULL,'pending',NULL,NULL,'2026-04-26 10:07:30','2026-04-26 10:07:30'),(10,25,'30 اردیبهشت','10',NULL,'pending',NULL,NULL,'2026-04-26 10:08:18','2026-04-26 10:08:18'),(11,19,'31 اردیبهشت','9 صبح',NULL,'pending',NULL,NULL,'2026-04-26 12:01:48','2026-04-26 12:01:48'),(12,26,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(13,24,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(14,23,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(15,43,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(16,38,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(17,64,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(18,65,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(19,32,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(20,22,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:57','2026-04-26 13:26:57'),(21,21,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:58','2026-04-26 13:26:58'),(22,20,'11 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-26 13:26:58','2026-04-26 13:26:58'),(23,66,'30 اردیبهشت','10:30',NULL,'pending',NULL,NULL,'2026-04-27 16:13:17','2026-04-27 16:13:17'),(24,27,'11 اردیبهشت','9 صبح',NULL,'pending',NULL,NULL,'2026-04-27 16:20:15','2026-04-27 16:20:15'),(25,78,'31 اردیبهشت','8:20',NULL,'pending',NULL,NULL,'2026-04-27 16:32:15','2026-04-27 16:32:15'),(26,20,'31 اردیبهشت','10:30',NULL,'pending',NULL,NULL,'2026-04-27 21:00:28','2026-04-27 21:00:28'),(27,30,'30 اردیبهشت','10:30',NULL,'pending',NULL,NULL,'2026-04-27 21:00:48','2026-04-27 21:00:48'),(28,34,'31 اردیبهشت','9 صبح',NULL,'pending',NULL,NULL,'2026-04-27 21:01:03','2026-04-27 21:01:03');
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_archive`
--

DROP TABLE IF EXISTS `attendance_archive`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_archive` (
  `id` int NOT NULL AUTO_INCREMENT,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `jalali_date` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `label` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `archived_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_students` int DEFAULT '0',
  `total_present` int DEFAULT '0',
  `total_absent` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_archive`
--

LOCK TABLES `attendance_archive` WRITE;
/*!40000 ALTER TABLE `attendance_archive` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_archive` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_classes`
--

DROP TABLE IF EXISTS `attendance_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_classes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `time` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_classes`
--

LOCK TABLES `attendance_classes` WRITE;
/*!40000 ALTER TABLE `attendance_classes` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_records`
--

DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `class_id` int NOT NULL,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `jalali_date` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` enum('present','absent','empty') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'empty',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_records`
--

LOCK TABLES `attendance_records` WRITE;
/*!40000 ALTER TABLE `attendance_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_session_students`
--

DROP TABLE IF EXISTS `attendance_session_students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_session_students` (
  `session_id` int NOT NULL,
  `student_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_session_students`
--

LOCK TABLES `attendance_session_students` WRITE;
/*!40000 ALTER TABLE `attendance_session_students` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_session_students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_sessions`
--

DROP TABLE IF EXISTS `attendance_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_date` date NOT NULL,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jalali_date` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_sessions`
--

LOCK TABLES `attendance_sessions` WRITE;
/*!40000 ALTER TABLE `attendance_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_throttle`
--

DROP TABLE IF EXISTS `auth_throttle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_throttle` (
  `throttle_key` varchar(191) COLLATE utf8mb4_persian_ci NOT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `window_started_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`throttle_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_throttle`
--

LOCK TABLES `auth_throttle` WRITE;
/*!40000 ALTER TABLE `auth_throttle` DISABLE KEYS */;
INSERT INTO `auth_throttle` VALUES ('login:ip:1c7fb55ea24adf4f1a81eba898d92f5bd17242deb2c4aab8c3724c87712fb134',1,'2026-10-26 17:05:02',NULL,'2026-10-26 20:35:02'),('login:ip:23eb87672e79c8e628475ec979d1ff028318832a38813c3c3e193916c5e045a8',1,'2026-10-26 17:30:27',NULL,'2026-10-26 21:00:27'),('login:ip:347b32e10fe7cb2b9db8421d399a56999357f69708474d082ff04b97e505afe1',1,'2026-10-26 17:03:52',NULL,'2026-10-26 20:33:52'),('login:ip:4d1ca65b0049abf84248c058397d2678a1728ff7d27c3a6c35b6fe76f3d15baa',3,'2026-10-24 17:21:01',NULL,'2026-10-24 21:01:21'),('login:ip:4e58801fd5e42c625ece2f1ea2f0162b46dbb8cf7fa37b08bb70a3d2f1a827a2',1,'2026-10-18 10:33:29',NULL,'2026-10-18 14:03:29'),('login:ip:80e795f39a104d08bdc4ed6d7f02e1ad924b3465a8ac868d6dbdd975e1ae1ec8',1,'2026-10-22 11:37:25',NULL,'2026-10-22 15:07:25'),('login:ip:9749ae6874246b953d1116552ebf3426dd9d5ad1e1c185c826896808c3244cbb',1,'2026-10-22 11:52:21',NULL,'2026-10-22 15:22:21'),('login:ip:99f2634480b243575a23b45059e8f3d27559dcd12bb199555972b4412e9534ca',4,'2026-10-22 13:45:13',NULL,'2026-10-22 17:16:11'),('login:ip:9eaba43744742825ff6a9b78ed88c2b175832b8908f8ab904ea3a3f73be687fd',1,'2026-10-26 16:57:47',NULL,'2026-10-26 20:27:47'),('login:ip:bac890157a15c5a7004b1f6ef111397cd1478c4277e2d019cca80ce7c388ca69',2,'2026-10-14 19:55:23',NULL,'2026-10-14 23:25:29'),('login:ip:bba55b3fe9a571dd257ab2bf0c7e498f8d8599004dd1fed6ee12416437f96a4f',1,'2026-10-28 10:57:45',NULL,'2026-10-28 14:27:45'),('login:ip:c241a9cb26712e5948eebf282f57779caa668ed910483e9bc9ccf4c8181629ef',3,'2026-10-26 16:57:51',NULL,'2026-10-26 20:30:14'),('login:ip:e3ea1872f5a1451f576499d0fb24e35921b6c3d1833a2fad5f154e2556dabb70',1,'2026-10-22 12:41:46',NULL,'2026-10-22 16:11:46'),('login:ip:f307bbdafcbede2b0e94f8faaf603a5006f4650624b454c561cf393b04675d1f',1,'2026-10-26 16:57:43',NULL,'2026-10-26 20:27:43'),('login:ip:fce0ace017197ae557e85878cbdcf43900d68f33ed04383de8854e28f315e70b',4,'2026-10-25 13:47:53',NULL,'2026-10-25 17:18:20'),('login:user_ip:13b689b119febcd37d74fa3255248a112dc631659d550f792909e00fc034f329',1,'2026-10-24 17:31:00',NULL,'2026-10-24 21:01:00'),('login:user_ip:2f169d3ba029c821d029791cb1bd55f13cb7bb1852ddeeced7de1ab571905aee',1,'2026-10-22 11:37:25',NULL,'2026-10-22 15:07:25'),('login:user_ip:409dc0240ee7243e8ce46e43538c20af3de35dfdcff90cdb2a62489791340282',1,'2026-10-24 17:21:01',NULL,'2026-10-24 20:51:01'),('login:user_ip:418cd5fa124e6697d5467cfbedb622abaa4dc848e6c81a3bf163ec50322cf0a8',1,'2026-10-26 16:57:43',NULL,'2026-10-26 20:27:43'),('login:user_ip:4d7f661de51e47f82e34878628d45f4868ec752d4449248b8cfaa32d4c00ce2a',2,'2026-10-14 19:55:23',NULL,'2026-10-14 23:25:29'),('login:user_ip:6ebf8c8f9918feb431af2c7b0debaf30f7550ab3537fdcffdde508c0c65a4589',1,'2026-10-22 11:52:21',NULL,'2026-10-22 15:22:21'),('login:user_ip:74260ea634972818595ea1ca092079cb6febea1819e43f006339a43dae5f7050',1,'2026-10-28 10:57:45',NULL,'2026-10-28 14:27:45'),('login:user_ip:74cc997aee5ea2b12075e92bdf2bc498c60b246f4a89d3468f9b06f5c340de58',4,'2026-10-25 13:47:52',NULL,'2026-10-25 17:18:20'),('login:user_ip:8a4c1fe79074df5f516b7959f0ae88117ba06f9e614f3fc366c904d30cb58eaf',1,'2026-10-26 17:03:52',NULL,'2026-10-26 20:33:52'),('login:user_ip:8ab210ca02aabc932ea3ccb9347ef80509b1555d878ea734e3958fc693039d92',3,'2026-10-26 16:57:51',NULL,'2026-10-26 20:30:14'),('login:user_ip:9958f3624994b792684f682284211841a7346f7433c2488676bd42722b1f0d3a',1,'2026-10-22 12:41:46',NULL,'2026-10-22 16:11:46'),('login:user_ip:aa6d5d080de01fdce55b18260c2d6aa78e9663eff71da27c723fa287f630e89d',1,'2026-10-26 17:05:02',NULL,'2026-10-26 20:35:02'),('login:user_ip:b9a930bf21319a3502172dfbf6794b5d8a0c478b4fcbda9823640352ff3e7b79',1,'2026-10-22 11:13:50',NULL,'2026-10-22 14:43:50'),('login:user_ip:c9539b1821d155f657e5c61a837f078f6e56cef29ba4396991882b5ded2a2219',1,'2026-10-26 16:57:47',NULL,'2026-10-26 20:27:47'),('login:user_ip:cd1580192f4df7ebdfe93e825703cd96d90a4c6c0d0b353cc728d83226da2a05',1,'2026-10-24 17:31:21',NULL,'2026-10-24 21:01:21'),('login:user_ip:d3edce66f92383ffd41a94b85ccf35a49f721aec1de9b9bc6ba40a4a7116312b',1,'2026-10-18 10:45:23',NULL,'2026-10-18 14:15:23'),('login:user:10a69e9a9f54fbf10e05b6a03432933cbac6103fc2d2a7c789fda2ec2b91ea0e',1,'2026-10-24 17:31:21',NULL,'2026-10-24 21:01:21'),('login:user:30401a31b96eb1f81ce15f4a36b2c1176532b3e044a85423504fb5b1d581edf9',1,'2026-10-24 17:31:00',NULL,'2026-10-24 21:01:00'),('login:user:3d212c031e6cfe27d17c06d4f0943135518d680eee56170d25dbebd2be57cc19',1,'2026-10-22 11:37:25',NULL,'2026-10-22 15:07:25'),('login:user:3ee8309f6adc63c18f507904b7437b8ef61c34c25ecbba3d6e366a0963a7354e',1,'2026-10-18 10:45:23',NULL,'2026-10-18 14:15:23'),('login:user:580fade1f962f1ba325baccc220f4f120622d74fa3b3e5e70606ac51b2332d9c',5,'2026-10-26 16:57:43',NULL,'2026-10-26 20:30:14'),('login:user:86a1470430a0c1430ce6e83dfb67566e98fbe47373f03b6bcfcfe3101c578eba',1,'2026-10-28 10:57:46',NULL,'2026-10-28 14:27:46'),('login:user:a32da10309beeffc8368e5320357c5b89498aff49af362aa64565a5d326940c6',1,'2026-10-22 12:41:46',NULL,'2026-10-22 16:11:46'),('login:user:aabd50e94286d37664a8581a584b9eb166e226ddaf891170ccd74dc2f1fde33d',2,'2026-10-14 19:55:23',NULL,'2026-10-14 23:25:30'),('login:user:bcdc5773214eb32e6850a91a76b6ec6233f955b3e1efc3af0efc8903e1a9fe4b',1,'2026-10-22 11:52:21',NULL,'2026-10-22 15:22:21'),('login:user:d66f6bc20059243831a550faa2f153bd22e618e7610dcacc87ee18096517a8c2',1,'2026-10-24 17:21:01',NULL,'2026-10-24 20:51:01'),('login:user:e7e521bde6603faaed83ceaad9d5543867666ada9ed737fc8d3b94ccc80cc509',4,'2026-10-25 13:47:53',NULL,'2026-10-25 17:18:20'),('sms:ip:07fda6d2fe5885058dc3b043e45c9c92fb07902fda99c10acc9bfa2297e2d7e5',1,'2026-10-22 08:36:04',NULL,'2026-10-22 12:06:04'),('sms:ip:23eb87672e79c8e628475ec979d1ff028318832a38813c3c3e193916c5e045a8',1,'2026-10-26 17:31:07',NULL,'2026-10-26 21:01:07'),('sms:ip:29907f4b03c552481f0464a9920d2b89af4409276e521968d3ef973842d879f6',1,'2026-10-23 17:28:52',NULL,'2026-10-23 20:58:52'),('sms:ip:2cce2b537d427d840d68140e0bf6ebb99123e4781a1aea993f0fd94a42776f83',1,'2026-10-28 22:13:58',NULL,'2026-10-29 01:43:58'),('sms:ip:3f2171b11c5285a42ac7a874692c86b79cd3c51d1cdbb150ca708f672de244b8',1,'2026-10-23 16:16:03',NULL,'2026-10-23 19:46:03'),('sms:ip:517ff9bcbd029ee9832b11a4e5dd25d9f0c69e581e84a7fcca0491c584599155',1,'2026-10-28 22:31:42',NULL,'2026-10-29 02:01:42'),('sms:ip:68dae9e5772d6892c9a7d61ac99e0c6509b922e2cf94a0192c764da03693782d',1,'2026-10-15 05:35:40',NULL,'2026-10-15 09:05:40'),('sms:ip:720ef235df3edd855e053507ac1d7af91f6e9abe58cfb36b9eadf045aa672914',1,'2026-10-15 06:13:22',NULL,'2026-10-15 09:43:22'),('sms:ip:7bd6212831ed70cc6aa303fcd4e646c4f843ef9a30e726faa77db11582677d42',1,'2026-10-22 12:40:05',NULL,'2026-10-22 16:10:05'),('sms:ip:99f2634480b243575a23b45059e8f3d27559dcd12bb199555972b4412e9534ca',1,'2026-10-27 14:10:03',NULL,'2026-10-27 17:40:03'),('sms:ip:c241a9cb26712e5948eebf282f57779caa668ed910483e9bc9ccf4c8181629ef',1,'2026-10-23 18:38:47',NULL,'2026-10-23 22:08:47'),('sms:ip:dc1de197637a60f2e5c7310a6d3af1f22af7b37c2e05aa3b6a9e553df8824b15',1,'2026-10-16 06:20:20',NULL,'2026-10-16 09:50:20'),('sms:ip:fc71c44dbdc210beae9ab3533ee3758f9c8947db8834d655c72941a7806fab49',1,'2026-10-14 19:42:56',NULL,'2026-10-14 23:12:56'),('sms:phone:57ddc1c295733ee5045864936e83fce1caf204dd1c52bc76852dca658833ed8c',2,'2026-10-28 22:13:58',NULL,'2026-10-29 02:01:42'),('sms:phone:727900e29df3ea113d924028ce0e4ffbf1f10657f164d75ecff94806d43edf7f',1,'2026-10-15 05:35:40',NULL,'2026-10-15 09:05:40'),('sms:phone:9664b6168d79f31a466e79357515a73694804d872c6905df86acf75bc7e01fe1',1,'2026-10-26 17:31:07',NULL,'2026-10-26 21:01:07'),('tg:link:ip:7b64411aad68c90fcc4374e0e006438cf2345bbc3ea9cd5b4fc56d4f4eb5736c',2,'2026-10-02 15:19:07',NULL,'2026-10-02 18:49:29'),('tg:register:ip:7b64411aad68c90fcc4374e0e006438cf2345bbc3ea9cd5b4fc56d4f4eb5736c',1,'2026-10-02 15:20:04',NULL,'2026-10-02 18:50:04'),('tg:register:tg:44f06ceec1f8438ee67aa9030a57d096da238e59b89ba500bbb57c16d3093bd7',1,'2026-10-02 15:20:04',NULL,'2026-10-02 18:50:04'),('tg:verify:ip:7b64411aad68c90fcc4374e0e006438cf2345bbc3ea9cd5b4fc56d4f4eb5736c',2,'2026-10-02 15:18:50',NULL,'2026-10-02 18:49:12');
/*!40000 ALTER TABLE `auth_throttle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blog_category`
--

DROP TABLE IF EXISTS `blog_category`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blog_category` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `color` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_category_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blog_category`
--

LOCK TABLES `blog_category` WRITE;
/*!40000 ALTER TABLE `blog_category` DISABLE KEYS */;
INSERT INTO `blog_category` VALUES (29,'روش مطالعه','study-methods',NULL,NULL,NULL,2,1),(30,'برنامه‌ریزی و مدیریت زمان','planning',NULL,NULL,NULL,3,1),(31,'آرامش و تمرکز','calm-and-focus',NULL,NULL,NULL,4,1),(32,'ریاضی','math',NULL,NULL,NULL,5,1),(33,'فیزیک','physics',NULL,NULL,NULL,6,1),(34,'شیمی','chemistry',NULL,NULL,NULL,7,1),(35,'زیست‌شناسی','biology',NULL,NULL,NULL,8,1),(36,'ادبیات و زبان فارسی','persian-literature',NULL,NULL,NULL,9,1),(37,'زبان انگلیسی','english',NULL,NULL,NULL,10,1),(38,'عربی و دین و زندگی','arabic-religion',NULL,NULL,NULL,11,1),(39,'انتخاب رشته و مسیر تحصیلی','major-selection',NULL,NULL,NULL,12,1),(40,'امتحانات نهایی و مدرسه','school-exams',NULL,NULL,NULL,13,1),(41,'اخبار و اطلاعیه‌های موسسه','news',NULL,NULL,NULL,14,1);
/*!40000 ALTER TABLE `blog_category` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blog_posts`
--

DROP TABLE IF EXISTS `blog_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blog_posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `category_id` int DEFAULT NULL,
  `excerpt` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `content` mediumtext COLLATE utf8mb4_general_ci,
  `cover_image` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `meta_description` varchar(300) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `published_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `views` int NOT NULL DEFAULT '0',
  `is_published` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_blog_published` (`is_published`,`published_at`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `blog_posts_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `blog_category` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blog_posts`
--

LOCK TABLES `blog_posts` WRITE;
/*!40000 ALTER TABLE `blog_posts` DISABLE KEYS */;
INSERT INTO `blog_posts` VALUES (1,'برنامه‌ریزی مطالعه برای کنکور: از کجا شروع کنیم؟','konkur-study-plan',30,'راهنمای قدم‌به‌قدم ساخت برنامه مطالعه واقع‌بینانه برای کنکور؛ از تعیین هدف و بررسی وضعیت فعلی تا برنامه هفتگی و ارزیابی منظم.','<h1 dir=\"rtl\">برنامه‌ریزی مطالعه برای کنکور: از کجا شروع کنیم؟</h1><p dir=\"rtl\">خیلی از داوطلب‌ها مشکلشون کم‌خوندن نیست؛ مشکلشون بی‌برنامه خوندنه. ساعت‌ها پشت میز می‌شینن، ولی آخر هفته نمی‌دونن چه چیزی جلو رفته و چه چیزی عقب مونده. برنامه‌ریزی خوب یعنی هر ساعت مطالعه یه هدف مشخص داشته باشه.</p><p dir=\"rtl\">در این مقاله یه روش ساده و قابل اجرا برای ساخت برنامه مطالعه کنکور رو مرور می‌کنیم.</p><h2 dir=\"rtl\">۱. اول هدف رو دقیق مشخص کن</h2><p dir=\"rtl\">«می‌خوام قبول بشم» هدف نیست، آرزوئه. هدف باید قابل اندازه‌گیری باشه:</p><ul dir=\"rtl\">\r\n<li>رشته و دانشگاه یا دسته‌ی دانشگاه‌های مدنظرت چیه؟</li>\r\n<li>رتبه یا درصد لازم برای اون چیه؟ (از نتایج سال‌های قبل و مشاورت بپرس)</li>\r\n<li>چند ماه تا کنکور مونده و روزانه چند ساعت مفید وقت داری؟</li>\r\n</ul><p dir=\"rtl\">وقتی این سه تا مشخص باشه، می‌تونی بفهمی برنامه‌ت واقع‌بینانه‌ست یا نه.</p><h2 dir=\"rtl\">۲. وضعیت فعلی‌ت رو بسنج</h2><p dir=\"rtl\">قبل از برنامه‌ریزی، باید بدونی الان کجایی. برای این کار:</p><ol dir=\"rtl\">\r\n<li>یه آزمون جامع یا چند آزمون درس‌به‌درس بده.</li>\r\n<li>درصدها رو برای هر درس یادداشت کن.</li>\r\n<li>مباحثی که زیاد غلط زدی یا نزدی رو علامت بزن.</li>\r\n</ol><p dir=\"rtl\">این آزمون برای رتبه گرفتن نیست؛ برای نقشه‌ی راهه. نتیجه‌ش بهت می‌گه وقتت رو کجا بذاری.</p><h2 dir=\"rtl\">۳. درس‌ها رو اولویت‌بندی کن</h2><p dir=\"rtl\">همه‌ی درس‌ها و مباحث ارزش یکسان ندارن. دو معیار رو کنار هم ببین:</p><ul dir=\"rtl\">\r\n<li><strong>ضریب و تعداد سؤال</strong> هر درس در کنکور</li>\r\n<li><strong>فاصله‌ی تو تا درصد هدف</strong> در اون درس</li>\r\n</ul><p dir=\"rtl\">درسی که ضریب بالا داره و درصدت توش پایینه، اولویت اوله. درسی که درصدت توش خوبه، فقط نیاز به مرور منظم داره، نه ساعت‌های طولانی.</p><h2 dir=\"rtl\">۴. برنامه‌ی هفتگی بساز، نه روزانه‌ی خیلی دقیق</h2><p dir=\"rtl\">برنامه‌ی ساعت‌به‌ساعت معمولاً اولین روز خراب می‌شه و آدم دلسرد می‌شه. به جاش:</p><ul dir=\"rtl\">\r\n<li>برای هر هفته چند <strong>هدف مشخص</strong> بذار (مثلاً «فصل‌های ۱ تا ۳ شیمی + ۱۰۰ تست»).</li>\r\n<li>هر روز، بلوک‌های مطالعه رو بر اساس انرژیت بچین؛ درس سخت‌تر رو توی ساعت‌هایی بذار که تمرکزت بالاتره.</li>\r\n<li>هر هفته یه نیم‌روز <strong>خالی</strong> برای جبران عقب‌افتادگی‌ها نگه دار.</li>\r\n</ul><h2 dir=\"rtl\">۵. مطالعه، تست، مرور؛ هر سه لازمه</h2><p dir=\"rtl\">یه چرخه‌ی ساده برای هر مبحث:</p><ol dir=\"rtl\">\r\n<li><strong>مطالعه‌ی مفهومی</strong> (درسنامه، کلاس، فیلم)</li>\r\n<li><strong>تست‌زنی موضوعی</strong> بلافاصله بعد از مطالعه</li>\r\n<li><strong>مرور غلط‌ها</strong> و نوشتن دلیل هر اشتباه</li>\r\n<li><strong>مرور دوباره</strong> بعد از چند روز و بعد از چند هفته</li>\r\n</ol><p dir=\"rtl\">اگه فقط درسنامه بخونی و تست نزنی، مغزت فکر می‌کنه بلده. تست نشون می‌ده واقعاً بلدی یا نه.</p><h2 dir=\"rtl\">۶. هر هفته برنامه رو ارزیابی کن</h2><p dir=\"rtl\">آخر هر هفته ده دقیقه وقت بذار و جواب بده:</p><ul dir=\"rtl\">\r\n<li>کدوم اهداف هفته انجام شد و کدوم نه؟ چرا؟</li>\r\n<li>کدوم درس بیشتر از انتظار زمان برد؟</li>\r\n<li>هفته‌ی بعد چی رو باید تغییر بدم؟</li>\r\n</ul><p dir=\"rtl\">برنامه یه سند ثابت نیست. تغییر دادنش نشونه‌ی شکست نیست، نشونه‌ی دقت‌ه.</p><h2 dir=\"rtl\">اشتباهات رایج</h2><ul dir=\"rtl\">\r\n<li>برنامه‌ای که ۱۲ ساعت مطالعه‌ی روزانه فرض می‌کنه، ولی کسی نمی‌تونه ادامه بده</li>\r\n<li>نادیده گرفتن استراحت، ورزش و خواب</li>\r\n<li>مقایسه‌ی برنامه‌ی خودت با برنامه‌ی دیگران</li>\r\n<li>عوض کردن مداوم منابع به جای تمام کردن یه منبع</li>\r\n</ul><h2 dir=\"rtl\">جمع‌بندی</h2><p>\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n\r\n</p><p dir=\"rtl\">برنامه‌ی خوب یعنی هدف مشخص، شناخت وضعیت فعلی، اولویت‌بندی درس‌ها، چرخه‌ی مطالعه-تست-مرور و ارزیابی هفتگی. از یه برنامه‌ی ساده شروع کن و هر هفته بهترش کن. اگه نیاز داری برنامه‌ات با شرایط خودت تنظیم بشه، می‌تونی از مشاورهای تحصیلی موسسه کمک بگیری.</p>','blog/1/6ae10c88947cb0.83972164.webp','اصول برنامه‌ریزی درسی برای کنکور؛ آموزش روش مطالعه‌ی فعال، مرور با فاصله و مدیریت زمان برای داوطلبان کنکور فامو.','2026-09-09 12:48:28',25,1),(4,'چطور درس بخونیم که یادمون بمونه؟ سه روش علمی برای مرور و یادگیری','effective-learning-review-methods',29,'با سه روش ساده و مؤثر یادگیری یعنی یادآوری فعال، مرور فاصله‌دار و آموزش به دیگران، مطالعه‌ات رو ماندگارتر کن؛ مناسب دانش‌آموزان و داوطلبان کنکور.','<h1 dir=\"rtl\">چطور درس بخونیم که یادمون بمونه؟ سه روش علمی برای مرور و یادگیری</h1>\r\n<p dir=\"rtl\">احتمالاً برات پیش اومده که یه فصل رو کامل خوندی، حس کردی بلدی، ولی چند روز بعد تقریباً همه‌چیز رو فراموش کردی. این مشکل کم‌هوشی یا بی‌حوصلگی نیست؛ معمولاً به <strong>روش مطالعه</strong> برمی‌گرده.</p>\r\n<p dir=\"rtl\">تحقیقات یادگیری نشون می‌دن بعضی روش‌ها که حس راحتی می‌دن (مثل چندبار خوندن یه متن یا هایلایت کردن) تأثیر کمی روی یادگیری بلندمدت دارن. در مقابل، روش‌هایی هستن که کمی سخت‌ترن ولی خیلی ماندگارترن. سه تاش رو اینجا مرور می‌کنیم.</p>\r\n<h2 dir=\"rtl\">۱. یادآوری فعال (Active Recall)</h2>\r\n<p dir=\"rtl\">یعنی به جای اینکه دوباره متن رو بخونی، <strong>سعی کنی اون رو از حافظه بیرون بکشی</strong>.</p>\r\n<p dir=\"rtl\">چطور انجامش بدیم:</p>\r\n<ul dir=\"rtl\">\r\n<li>بعد از خوندن یه بخش، کتاب رو ببند و هرچی یادته روی کاغذ بنویس.</li>\r\n<li>از خودت سؤال بپرس و جواب بده، مثلاً «چرا این واکنش انجام می‌شه؟»</li>\r\n<li>از تست و سؤال تشریحی به عنوان ابزار یادگیری استفاده کن، نه فقط برای سنجش.</li>\r\n</ul>\r\n<p dir=\"rtl\">سخت بودن این کار طبیعیه. همون تلاش برای یادآوری، حافظه رو قوی‌تر می‌کنه.</p>\r\n<h2 dir=\"rtl\">۲. مرور فاصله‌دار (Spaced Repetition)</h2>\r\n<p dir=\"rtl\">مغز ما چیزهایی رو که فقط یک‌بار دیده فراموش می‌کنه. اگه مرور رو در <strong>فاصله‌های زمانی افزایشی</strong> انجام بدی، مطلب پایدارتر می‌شه.</p>\r\n<p dir=\"rtl\">یه الگوی ساده:</p>\r\n<div dir=\"rtl\"><table><thead><tr><th scope=\"col\">مرور</th><th scope=\"col\">زمان</th></tr></thead><tbody><tr><td>مرور اول</td><td>همون روز یا فردا</td></tr><tr><td>مرور دوم</td><td>چند روز بعد</td></tr><tr><td>مرور سوم</td><td>حدود یک هفته بعد</td></tr><tr><td>مرور چهارم</td><td>چند هفته بعد</td></tr></tbody></table></div>\r\n<p dir=\"rtl\">لازم نیست دقیقاً همین فاصله‌ها رو رعایت کنی. مهم اینه که مرور رو به یه عادت منظم تبدیل کنی. می‌تونی از کارت‌های یادآوری (فلش‌کارت) یا اپ‌هایی مثل Anki برای مدیریتش استفاده کنی، یا حتی یه جدول ساده‌ی کاغذی بسازی.</p>\r\n<h2 dir=\"rtl\">۳. توضیح دادن به دیگران (روش فاینمن)</h2>\r\n<p dir=\"rtl\">اگه بتونی یه مبحث رو به زبان ساده برای کسی توضیح بدی، یعنی واقعاً فهمیدیش.</p>\r\n<p dir=\"rtl\">مراحل:</p>\r\n<ol dir=\"rtl\">\r\n<li>مبحث رو انتخاب کن.</li>\r\n<li>طوری توضیحش بده که انگار به یه دانش‌آموز کلاس پایین‌تر یا دوستی که اون درس رو نخونده توضیح می‌دی.</li>\r\n<li>هرجا گیر کردی یا نتونستی ساده بگی، همونجا نقطه‌ضعفته. برگرد و دوباره بخونش.</li>\r\n<li>توضیحت رو ساده‌تر کن و با مثال همراهش کن.</li>\r\n</ol>\r\n<p dir=\"rtl\">اگه کسی رو نداری، جلوی آینه یا با ضبط صدای خودت هم جواب می‌ده.</p>\r\n<h2 dir=\"rtl\">یه چرخه‌ی ترکیبی برای هر مبحث</h2>\r\n<ol dir=\"rtl\">\r\n<li>مطالعه‌ی مبحث با تمرکز</li>\r\n<li>بستن کتاب و یادآوری فعال</li>\r\n<li>حل چند تست یا سؤال همون مبحث</li>\r\n<li>مرور فاصله‌دار در روزهای بعد</li>\r\n<li>توضیح دادن مبحث به یه نفر یا به خودت</li>\r\n</ol>\r\n<h2 dir=\"rtl\">چیزهایی که کمتر کمک می‌کنن</h2>\r\n<ul dir=\"rtl\">\r\n<li>دوباره‌خوانی پشت سر هم بدون خودآزمایی</li>\r\n<li>هایلایت کردن زیاد (حس پیشرفت می‌ده ولی کمتر یاد می‌ده)</li>\r\n<li>مطالعه‌ی طولانی بدون استراحت و تمرکز پایین</li>\r\n<li>نگاه کردن به راه‌حل بدون اینکه خودت اول تلاش کنی</li>\r\n</ul>\r\n<h2 dir=\"rtl\">جمع‌بندی</h2>\r\n<p dir=\"rtl\">برای یادگیری ماندگار، به جای «بیشتر خوندن» روی «بهتر خوندن» تمرکز کن: خودت رو بیازما، مرور رو بین روزها پخش کن و مطالب رو توضیح بده. اگه این سه روش رو توی برنامه‌ی هفتگی‌ت بگنجونی، بعد از چند هفته تفاوت رو توی نتیجه‌ی آزمون‌هات می‌بینی.</p>','blog/4/6ae10b426d6640.84323002.webp','روش مطالعه,مرور,یادگیری مؤثر,کنکور,دانش‌آموز','2026-10-22 17:16:33',4,1),(5,'مدیریت استرس و خواب در ماه‌های آخر قبل از کنکور و امتحانات','exam-stress-and-sleep',31,'راهکارهای عملی و ساده برای کنترل استرس امتحان، حفظ خواب منظم و تمرکز در روزهای فشرده‌ی کنکور و امتحانات نهایی.','<h1 dir=\"rtl\">مدیریت استرس و خواب در ماه‌های آخر قبل از کنکور و امتحانات</h1>\r\n<p dir=\"rtl\">کمی استرس قبل از امتحان طبیعیه و حتی می‌تونه بهت انگیزه بده. ولی وقتی استرس زیاد می‌شه، تمرکز کم می‌شه، خواب به هم می‌ریزه و همون چیزایی که خوندی هم سخت‌تر به یاد میاد.</p>\r\n<p dir=\"rtl\">این مقاله چند راهکار ساده و عملی برای حفظ آرامش و کیفیت مطالعه ارائه می‌ده. اگه استرس یا بی‌خوابی‌ت شدید و طولانی‌مدته و روی زندگی روزمره‌ت اثر گذاشته، حتماً با مشاور یا پزشک صحبت کن.</p>\r\n<h2 dir=\"rtl\">۱. خواب، بخشی از برنامه‌ی درسیه</h2>\r\n<p dir=\"rtl\">خیلی‌ها برای بیشتر خوندن، خواب رو کم می‌کنن. ولی مغز در خواب اطلاعات روز رو مرتب و تثبیت می‌کنه. کم‌خوابی معمولاً یعنی تمرکز کمتر و فراموشی بیشتر.</p>\r\n<p dir=\"rtl\">نکات کاربردی:</p>\r\n<ul dir=\"rtl\">\r\n<li>ساعت خواب و بیداری رو تا حد امکان ثابت نگه دار، حتی روزهای تعطیل.</li>\r\n<li>یکی دو ساعت آخر شب رو به مرور سبک یا استراحت اختصاص بده، نه حل مسائل سخت.</li>\r\n<li>نیم ساعت قبل خواب از گوشی و صفحه‌های روشن فاصله بگیر.</li>\r\n<li>کافئین (چای پررنگ، نوشیدنی انرژی‌زا) رو در ساعات پایانی روز کم کن.</li>\r\n<li>شب امتحان، دیر خوابیدن برای یه مرور اضافه معمولاً به نفعت نیست.</li>\r\n</ul>\r\n<h2 dir=\"rtl\">۲. تنفس آهسته برای لحظه‌های استرس</h2>\r\n<p dir=\"rtl\">وقتی سر جلسه‌ی آزمون یا حین مطالعه دلشوره می‌گیری، یه تمرین ساده کمک می‌کنه:</p>\r\n<ol dir=\"rtl\">\r\n<li>چهار ثانیه نفس بکش.</li>\r\n<li>چهار ثانیه نگه دار.</li>\r\n<li>شش ثانیه آروم بیرون بده.</li>\r\n<li>این کار رو چند دقیقه تکرار کن.</li>\r\n</ol>\r\n<p dir=\"rtl\">بازدم طولانی‌تر به بدن سیگنال آرامش می‌ده. این تمرین رو از قبل، توی روزهای عادی هم تمرین کن تا موقع نیاز راحت‌تر بشه.</p>\r\n<h2 dir=\"rtl\">۳. فکرهای استرس‌زا رو روی کاغذ بیار</h2>\r\n<p dir=\"rtl\">ذهن وقتی نگرانی‌ها رو فقط توی سرش نگه می‌داره، اون‌ها رو بزرگ‌تر می‌کنه. ده دقیقه بنویس:</p>\r\n<ul dir=\"rtl\">\r\n<li>دقیقاً از چی می‌ترسم؟</li>\r\n<li>کدوم بخشش دست خودمه و کدوم نه؟</li>\r\n<li>برای بخش‌های قابل کنترل، امروز کدوم قدم کوچک رو برمی‌دارم؟</li>\r\n</ul>\r\n<p dir=\"rtl\">تمرکز روی کارهای قابل‌کنترل (مثل حل تست امروز) بهتر از غرق شدن در چیزهایی‌ه که کنترلی روشون نداری (مثل رتبه‌ی دیگران یا سختی سؤال‌ها).</p>\r\n<h2 dir=\"rtl\">۴. استراحت و حرکت، هدررفت زمان نیست</h2>\r\n<ul dir=\"rtl\">\r\n<li>بعد از هر ۴۵ تا ۶۰ دقیقه مطالعه، ۵ تا ۱۰ دقیقه استراحت واقعی داشته باش (بلند شو، آب بخور، کمی راه برو).</li>\r\n<li>هر روز کمی حرکت بدنی داشته باش؛ پیاده‌روی ۲۰ تا ۳۰ دقیقه‌ای هم کافیه.</li>\r\n<li>آب کافی بنوش و وعده‌های غذایی منظم داشته باش.</li>\r\n</ul>\r\n<h2 dir=\"rtl\">۵. مقایسه با دیگران رو کم کن</h2>\r\n<p dir=\"rtl\">شبکه‌های اجتماعی و گروه‌های کنکوری پر از آدم‌هایی‌ن که «۱۴ ساعت می‌خونن». این حرف‌ها معمولاً دقیق نیست و مقایسه فقط اضطراب می‌ده. معیار پیشرفتت باید نتایج خودت در طول زمان باشه، نه ساعت مطالعه‌ی بقیه.</p>\r\n<h2 dir=\"rtl\">۶. با اطرافیانت صحبت کن</h2>\r\n<p dir=\"rtl\">از خانواده یا دوستان نزدیک بخواه فشار اضافی نیارن و به جای سؤال مداوم درباره‌ی نتیجه، حمایت کنن. اگه حس می‌کنی تنهایی نمی‌کشی، با مشاور موسسه یا مدرسه صحبت کن. کمک گرفتن نشونه‌ی ضعف نیست.</p>\r\n<h2 dir=\"rtl\">چک‌لیست کوتاه برای هفته‌ی امتحان</h2>\r\n<ul dir=\"rtl\">\r\n<li><input disabled=\"\" type=\"checkbox\"> خواب منظم (بدون کم کردن ساعت خواب برای مطالعه)</li>\r\n<li><input disabled=\"\" type=\"checkbox\"> مرور خلاصه‌نویسی‌ها و غلط‌های قبلی، نه شروع مبحث جدید</li>\r\n<li><input disabled=\"\" type=\"checkbox\"> آماده کردن وسایل و مسیر جلسه از شب قبل</li>\r\n<li><input disabled=\"\" type=\"checkbox\"> صبحانه‌ی سبک و کافی روز امتحان</li>\r\n<li><input disabled=\"\" type=\"checkbox\"> چند نفس عمیق قبل از شروع، و شروع از سؤال‌هایی که مطمئنی</li>\r\n</ul>\r\n<h2 dir=\"rtl\">جمع‌بندی</h2>\r\n<p dir=\"rtl\">آرامش و خواب مکمل مطالعه‌ان، نه رقیبش. با خواب منظم، استراحت‌های کوتاه، تمرین تنفس و تمرکز روی کارهای قابل‌کنترل، می‌تونی از چیزی که خوندی بهتر استفاده کنی و با ذهن آماده‌تری وارد جلسه‌ی آزمون بشی.</p>','blog/5/6ae10ad2d58521.18911658.webp','استرس امتحان,خواب,تمرکز,کنکور,امتحان نهایی','2026-10-22 17:28:48',10,1);
/*!40000 ALTER TABLE `blog_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bot_outbox`
--

DROP TABLE IF EXISTS `bot_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bot_outbox` (
  `id` int NOT NULL AUTO_INCREMENT,
  `kind` enum('student_report','supporter_reply','broadcast') COLLATE utf8mb4_persian_ci NOT NULL,
  `recipient_role` enum('student','supporter') COLLATE utf8mb4_persian_ci NOT NULL,
  `recipient_account_id` int NOT NULL,
  `telegram_user_id` bigint DEFAULT NULL,
  `chat_id` bigint DEFAULT NULL,
  `status` enum('pending','processing','sent','failed','skipped') COLLATE utf8mb4_persian_ci NOT NULL DEFAULT 'pending',
  `payload_json` mediumtext COLLATE utf8mb4_persian_ci NOT NULL,
  `ref_type` varchar(40) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `ref_id` int DEFAULT NULL,
  `dedup_key` varchar(160) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `max_attempts` int NOT NULL DEFAULT '3',
  `available_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_by` varchar(64) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `telegram_message_id` varchar(64) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `error` varchar(500) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_outbox_dedup` (`dedup_key`),
  KEY `idx_outbox_claim` (`status`,`available_at`,`id`),
  KEY `idx_outbox_locked` (`status`,`locked_at`),
  KEY `idx_outbox_recipient` (`recipient_role`,`recipient_account_id`),
  KEY `idx_outbox_ref` (`ref_type`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bot_outbox`
--

LOCK TABLES `bot_outbox` WRITE;
/*!40000 ALTER TABLE `bot_outbox` DISABLE KEYS */;
/*!40000 ALTER TABLE `bot_outbox` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bot_sessions`
--

DROP TABLE IF EXISTS `bot_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bot_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chat_id` bigint NOT NULL,
  `user_id` int DEFAULT NULL,
  `step` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_json` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bot_sessions`
--

LOCK TABLES `bot_sessions` WRITE;
/*!40000 ALTER TABLE `bot_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `bot_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bot_ui_state`
--

DROP TABLE IF EXISTS `bot_ui_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bot_ui_state` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chat_id` bigint NOT NULL,
  `panel_message_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bot_ui_state`
--

LOCK TABLES `bot_ui_state` WRITE;
/*!40000 ALTER TABLE `bot_ui_state` DISABLE KEYS */;
INSERT INTO `bot_ui_state` VALUES (1,7958508995,954,'2026-01-06 20:40:20','2026-01-08 12:12:42'),(2,7943517873,646,'2026-01-06 20:55:50','2026-01-06 21:41:13');
/*!40000 ALTER TABLE `bot_ui_state` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `courses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نام دوره',
  `icon` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'کلاس FontAwesome برای آیکون',
  `gradient_color_from` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رنگ شروع gradient (hex)',
  `gradient_color_to` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رنگ پایان gradient (hex)',
  `background_image_url` text COLLATE utf8mb4_unicode_ci COMMENT 'آدرس تصویر background',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'توضیحات دوره',
  `price` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'قیمت دوره (مثلاً: 500,000 تومان)',
  `display_order` int DEFAULT '0' COMMENT 'ترتیب نمایش',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courses`
--

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (1,'علوم تجربی','fas fa-flask','#445d84','#7da3c1','courses/1/6ae103d0667387.19337494.webp','پوشش منظم زیست، شیمی، فیزیک و ریاضی با تمرکز بر یادگیری عمیق و تمرین تستی. مسیر مطمئن برای رسیدن به رشته‌های پزشکی و علوم پایه.','4900000',1,'2025-12-19 14:24:00','2026-10-28 01:02:25'),(2,'ریاضی فیزیک','fas fa-square-root-alt','#580441','#740129','courses/2/6ae10d56aa6d17.64764855.webp','آموزش گام‌به‌گام مباحث از پایه، همراه با تمرین و حل مسئله منظم. هدف ما این است که ریاضی و فیزیک به نقطه قوت شما تبدیل شود.','4900000',2,'2025-12-19 14:24:00','2026-10-28 01:02:15'),(3,'علوم انسانی','fas fa-book-open','#6d8b9e','#8aa5b8','courses/3/6ae115b5c8aff6.45864919.webp','آموزش ساختارمند دروس اختصاصی و عمومی با تکیه بر درک و تحلیل. تمرین و آزمون‌های مرور‌محور، مسیر آمادگی را روشن و بدون سردرگمی می‌کنند.','4900000',3,'2025-12-19 14:24:00','2026-10-28 01:02:35'),(4,'راهنمایی و آمادگی تیزهوشان','fas fa-star','#445d84','#6d8b9e','courses/4/6ae1166c7f51b7.50912790.webp','تقویت دروس پایه و مهارت‌های تفکر منطقی و حل مسئله برای آزمون استعدادهای درخشان. با تمرین مستمر، هم آماده می‌شوید و هم اعتمادبه‌نفس رقابت را پیدا می‌کنید.','4900000',4,'2025-12-19 14:24:00','2026-10-28 01:02:46');
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `daily_attendance`
--

DROP TABLE IF EXISTS `daily_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_attendance` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `attendance_date` date NOT NULL,
  `student_id` int DEFAULT NULL,
  `guest_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `field` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('present','absent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `arrived_at` datetime DEFAULT NULL,
  `exam_started_at` datetime DEFAULT NULL,
  `exam_ended_at` datetime DEFAULT NULL,
  `departed_at` datetime DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  `removed_by` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `client_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_att_student_day` (`attendance_date`,`student_id`),
  UNIQUE KEY `uq_att_client_uuid` (`client_uuid`),
  KEY `idx_att_day_field` (`attendance_date`,`field`),
  KEY `fk_att_student` (`student_id`),
  KEY `fk_att_removed_by` (`removed_by`),
  KEY `fk_att_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `daily_attendance`
--

LOCK TABLES `daily_attendance` WRITE;
/*!40000 ALTER TABLE `daily_attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `daily_attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `event_topics`
--

DROP TABLE IF EXISTS `event_topics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `event_topics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_id` int NOT NULL,
  `topic_id` varchar(20) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `topic_label` varchar(200) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `topic_path` text COLLATE utf8mb4_persian_ci,
  `topic_path_short` varchar(255) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `position` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_event_topics_event` (`event_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `event_topics`
--

LOCK TABLES `event_topics` WRITE;
/*!40000 ALTER TABLE `event_topics` DISABLE KEYS */;
INSERT INTO `event_topics` VALUES (9,33,'10194','Conversation','زبان انگلیسی > Lesson 3: Art & Culture > Conversation','زبان انگلیسی | یازدهم | Lesson 3 | Conversation',0),(10,33,'10195','New Words & Expressions','زبان انگلیسی > Lesson 3: Art & Culture > New Words & Expressions','زبان انگلیسی | یازدهم | Lesson 3 | New Words & Expressions',1),(11,33,'10196','Reading','زبان انگلیسی > Lesson 3: Art & Culture > Reading','زبان انگلیسی | یازدهم | Lesson 3 | Reading',2),(12,34,'10778','مقاومت الکتریکی و قانون اهم','فیزیک > فصل 2: جریان الکتریکی و مدارهای جریان مستقیم > مقاومت الکتریکی و قانون اهم','فیزیک | یازدهم | فصل 2 | مقاومت الکتریکی و قانون اهم',0),(13,34,'10779','عوامل مؤثر بر مقاومت الکتریکی','فیزیک > فصل 2: جریان الکتریکی و مدارهای جریان مستقیم > عوامل مؤثر بر مقاومت الکتریکی','فیزیک | یازدهم | فصل 2 | عوامل مؤثر بر مقاومت الکتریکی',1),(14,34,'10780','نیروی محرکۀ الکتریکی و مدارها','فیزیک > فصل 2: جریان الکتریکی و مدارهای جریان مستقیم > نیروی محرکۀ الکتریکی و مدارها','فیزیک | یازدهم | فصل 2 | نیروی محرکۀ الکتریکی و مدارها',2),(15,35,'10025','زبان انگلیسی','زبان انگلیسی','زبان انگلیسی | یازدهم',0),(16,36,'10778','مقاومت الکتریکی و قانون اهم','فیزیک > فصل 2: جریان الکتریکی و مدارهای جریان مستقیم > مقاومت الکتریکی و قانون اهم','فیزیک | یازدهم | فصل 2 | مقاومت الکتریکی و قانون اهم',0),(17,36,'10779','عوامل مؤثر بر مقاومت الکتریکی','فیزیک > فصل 2: جریان الکتریکی و مدارهای جریان مستقیم > عوامل مؤثر بر مقاومت الکتریکی','فیزیک | یازدهم | فصل 2 | عوامل مؤثر بر مقاومت الکتریکی',1),(18,36,'10780','نیروی محرکۀ الکتریکی و مدارها','فیزیک > فصل 2: جریان الکتریکی و مدارهای جریان مستقیم > نیروی محرکۀ الکتریکی و مدارها','فیزیک | یازدهم | فصل 2 | نیروی محرکۀ الکتریکی و مدارها',2),(19,37,'10194','Conversation','زبان انگلیسی > Lesson 3: Art & Culture > Conversation','زبان انگلیسی | یازدهم | Lesson 3 | Conversation',0),(20,37,'10195','New Words & Expressions','زبان انگلیسی > Lesson 3: Art & Culture > New Words & Expressions','زبان انگلیسی | یازدهم | Lesson 3 | New Words & Expressions',1),(21,37,'10196','Reading','زبان انگلیسی > Lesson 3: Art & Culture > Reading','زبان انگلیسی | یازدهم | Lesson 3 | Reading',2);
/*!40000 ALTER TABLE `event_topics` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_id` int NOT NULL COMMENT 'FK به weekly_plans',
  `student_id` int NOT NULL COMMENT 'FK به students (برای کوئری سریع)',
  `day_index` tinyint NOT NULL COMMENT '0=شنبه ... 6=جمعه',
  `day_name` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'شنبه، یکشنبه، ...',
  `time_index` tinyint NOT NULL COMMENT 'ایندکس ستون زمان',
  `time_label` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'مثلاً ۱۵-۱۶',
  `topic_id` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'آیدی نود در درخت مباحث',
  `topic_path` text COLLATE utf8mb4_unicode_ci COMMENT 'مسیر کامل مبحث',
  `topic_path_short` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'مسیر کوتاه نمایشی',
  `topic_label` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'برچسب مبحث انتخابی',
  `subject_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'نام درس',
  `selected_grade` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'پایه انتخابی برای این مبحث',
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'نوع فعالیت',
  `notes` text COLLATE utf8mb4_unicode_ci COMMENT 'یادداشت اضافه',
  `color` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'green' COMMENT 'رنگ chip',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `events`
--

LOCK TABLES `events` WRITE;
/*!40000 ALTER TABLE `events` DISABLE KEYS */;
/*!40000 ALTER TABLE `events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exam_results`
--

DROP TABLE IF EXISTS `exam_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exam_results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `file_id` int DEFAULT NULL COMMENT 'لینک به فایل آزمون',
  `exam_date` date NOT NULL,
  `subject` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نام درس',
  `chapter` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'نام مبحث',
  `total_q` int NOT NULL COMMENT 'تعداد کل سوالات',
  `correct` int NOT NULL DEFAULT '0',
  `wrong` int NOT NULL DEFAULT '0',
  `skipped` int NOT NULL DEFAULT '0',
  `percentage` decimal(5,2) GENERATED ALWAYS AS (round(((`correct` / nullif(`total_q`,0)) * 100),2)) STORED,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=603 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_results`
--

LOCK TABLES `exam_results` WRITE;
/*!40000 ALTER TABLE `exam_results` DISABLE KEYS */;
INSERT INTO `exam_results` (`id`, `student_id`, `file_id`, `exam_date`, `subject`, `chapter`, `total_q`, `correct`, `wrong`, `skipped`, `created_at`) VALUES (30,20,NULL,'2026-03-13','فیزیک دوازدهم ','فصل 4 و5، آشنایی با فیزیک اتمی و هسته ای',20,1,1,18,'2026-03-13 09:01:29'),(31,20,NULL,'2026-03-13','شیمی دوازدهم ','فصل 4',20,3,4,13,'2026-03-13 09:01:29'),(32,20,NULL,'2026-03-13','حسابان دوازدهم ','فصل 5 کاربرد مشتق',15,0,0,15,'2026-03-13 09:01:29'),(33,20,NULL,'2026-03-13','گسسته','فصل 3 ترکیبات و شمارش',10,0,0,10,'2026-03-13 09:01:29'),(34,20,NULL,'2026-03-13','هندسه','فصل 3 بردار ها',10,0,0,10,'2026-03-13 09:01:29'),(35,19,NULL,'2026-03-13','شیمی دهم ','فصل 3، آب اهنگ زندگی',15,3,3,9,'2026-03-13 09:59:21'),(36,19,NULL,'2026-03-13','هندسه دوازدهم ','فصل 3 بردار ها',10,0,2,8,'2026-03-13 09:59:21'),(37,19,NULL,'2026-03-13','حسابان یازدهم ','جبر و معادله و هندسه تحلیلی',10,0,1,9,'2026-03-13 09:59:21'),(38,19,NULL,'2026-03-13','گسسته ','فصل 3 ترکیبات و شمارش',10,5,2,3,'2026-03-13 09:59:21'),(39,19,NULL,'2026-03-13','فیزیک دوازدهم ','فصل 3 نوسان و موج ',15,3,2,10,'2026-03-13 09:59:21'),(40,22,NULL,'2026-03-13','گسسته ','فصل3 گراف',10,3,0,7,'2026-03-13 10:09:01'),(41,22,NULL,'2026-03-13','هندسه ','فصل 2 مقاطع مخروطی',10,1,0,9,'2026-03-13 10:09:01'),(42,22,NULL,'2026-03-13','حسابان ','فصل 3 حد های نامتناهی و حد دربینهایت',10,0,0,10,'2026-03-13 10:09:01'),(43,22,NULL,'2026-03-13','شیمی دوازدهم ','فصل 3، آب و فصل 3 دهم ',15,8,0,7,'2026-03-13 10:09:01'),(44,21,NULL,'2026-03-13','شیمی','فصل 3',15,3,0,12,'2026-03-13 10:15:32'),(45,21,NULL,'2026-03-13','فیزیک ','نوسان و موج ',15,0,0,15,'2026-03-13 10:15:32'),(46,21,NULL,'2026-03-13','حسابان ','مشتق و هوپیتال ',10,0,0,10,'2026-03-13 10:15:32'),(47,21,NULL,'2026-03-13','گسسته ','فصل 2 گراف ',10,1,0,9,'2026-03-13 10:15:32'),(48,25,NULL,'2026-03-13','زیست','',20,11,1,8,'2026-03-13 10:27:48'),(49,25,NULL,'2026-03-13','شیمی','',15,4,0,11,'2026-03-13 10:27:48'),(50,25,NULL,'2026-03-13','ریاضی','',15,1,0,14,'2026-03-13 10:27:48'),(51,25,NULL,'2026-03-13','فیزیک','',15,2,1,12,'2026-03-13 10:27:48'),(52,23,NULL,'2026-03-13','فیزیک ','فصل 3 نوسان و موج ',15,3,2,10,'2026-03-13 10:39:59'),(53,23,NULL,'2026-03-13','شیمی دوازدهم ','فصل 3',15,2,4,9,'2026-03-13 10:39:59'),(54,23,NULL,'2026-03-13','حسابان ','فصل 4 مشتق',15,0,0,15,'2026-03-13 10:39:59'),(55,23,NULL,'2026-03-13','گسسته ','گراف ',15,0,1,14,'2026-03-13 10:39:59'),(56,23,NULL,'2026-03-13','هندسه ','مقاطع مخروطی ',10,0,0,10,'2026-03-13 10:39:59'),(57,24,NULL,'2026-03-13','فیزیک ','نوسان و موج ',15,6,5,4,'2026-03-13 10:44:58'),(58,24,NULL,'2026-03-13','شیمی 3','فصل 2 اکسایش و کاهش',15,7,2,6,'2026-03-13 10:44:58'),(59,24,NULL,'2026-03-13','حسابان ','حد نامتناهی و حد در بینهایت ',10,0,0,10,'2026-03-13 10:44:58'),(60,24,NULL,'2026-03-13','گسسته ','گراف ',10,4,5,1,'2026-03-13 10:44:58'),(61,24,NULL,'2026-03-13','هندسه 3','ماتریس و کاربردها',10,4,3,3,'2026-03-13 10:44:58'),(62,26,NULL,'2026-03-13','گسسته ','فصل 3',10,1,0,9,'2026-03-13 13:42:06'),(63,26,NULL,'2026-03-13','هندسه ','فصل 3',10,4,1,5,'2026-03-13 13:42:06'),(64,26,NULL,'2026-03-13','حسابان ','کاربردمشتق ',15,0,2,13,'2026-03-13 13:42:06'),(65,26,NULL,'2026-03-13','شیمی','فصل 3 و 4',20,13,2,5,'2026-03-13 13:42:06'),(66,26,NULL,'2026-03-13','فیزیک ','فصل 5',20,8,5,7,'2026-03-13 13:42:06'),(67,22,NULL,'2026-03-19','شیمی دوازدهم ','فصل 3',15,5,1,9,'2026-03-19 09:16:41'),(68,22,NULL,'2026-03-19','حسابان ','مشتق ',10,4,0,6,'2026-03-19 09:16:41'),(69,22,NULL,'2026-03-19','گسسته ','فصل 3',10,0,0,10,'2026-03-19 09:16:41'),(70,22,NULL,'2026-03-19','هندسه','دایره ',10,0,0,10,'2026-03-19 09:16:41'),(71,24,NULL,'2026-03-19','فیزیک ','فصل 3 بسامد',15,4,7,4,'2026-03-19 09:28:50'),(72,24,NULL,'2026-03-19','شیمی','فصل 2 سلول های گالوانی',15,8,4,3,'2026-03-19 09:28:50'),(73,24,NULL,'2026-03-19','حسابان ','مشتق',10,0,0,10,'2026-03-19 09:28:50'),(74,24,NULL,'2026-03-19','گسسته ','گراف',10,0,0,10,'2026-03-19 09:28:50'),(75,20,NULL,'2026-03-19','فیزیک ','فصل 5',15,10,1,4,'2026-03-19 09:31:19'),(76,20,NULL,'2026-03-19','شیمی ','',15,3,4,8,'2026-03-19 09:31:19'),(77,20,NULL,'2026-03-19','حسابان ','',10,0,0,10,'2026-03-19 09:31:19'),(78,20,NULL,'2026-03-19','گسسته ','',10,0,0,10,'2026-03-19 09:31:19'),(79,23,NULL,'2026-03-19','فیزیک',' فصل 3',15,8,0,7,'2026-03-19 09:40:40'),(80,23,NULL,'2026-03-19','شیمی','فصل 3',15,1,3,11,'2026-03-19 09:40:40'),(81,23,NULL,'2026-03-19','حسابان ','مشتق',10,0,0,10,'2026-03-19 09:40:40'),(82,23,NULL,'2026-03-19','گسسته','فصل 2',10,0,3,7,'2026-03-19 09:40:40'),(83,23,NULL,'2026-03-19','هندسه ','فصل 3',10,2,1,7,'2026-03-19 09:40:40'),(84,19,NULL,'2026-03-19','شیمی','دهم فصل 2',15,8,3,4,'2026-03-19 09:44:00'),(85,19,NULL,'2026-03-19','هندسه','اولین سری سوال',10,4,3,3,'2026-03-19 09:44:00'),(86,19,NULL,'2026-03-19','فیزیک','فصل 4',15,5,1,9,'2026-03-19 09:44:00'),(87,19,NULL,'2026-03-19','آمار و احتمال ','یازدهم فصل 2',10,1,4,5,'2026-03-19 09:44:00'),(88,19,NULL,'2026-03-19','حسابان ','معادلات گویا و گنگ و هندسه تحلیلی',10,3,4,3,'2026-03-19 09:44:00'),(89,25,NULL,'2026-03-19','زیست ','',30,16,0,14,'2026-03-19 11:02:31'),(90,25,NULL,'2026-03-19','فیزیک ','',15,6,1,8,'2026-03-19 11:02:31'),(91,25,NULL,'2026-03-19','شیمی','',15,10,0,5,'2026-03-19 11:02:31'),(92,25,NULL,'2026-03-19','ریاضی','',15,7,0,8,'2026-03-19 11:02:31'),(93,49,NULL,'2026-03-19','شیمی ','',15,3,2,10,'2026-03-19 12:35:41'),(94,49,NULL,'2026-03-19','زیست شناسی','',25,10,5,10,'2026-03-19 12:35:41'),(95,49,NULL,'2026-03-19','فیزیک','',15,2,1,12,'2026-03-19 12:35:41'),(96,49,NULL,'2026-03-19','ریاضی','',10,1,0,9,'2026-03-19 12:35:41'),(97,71,NULL,'2026-03-19','زیست شناسی','',30,20,8,2,'2026-03-19 13:07:09'),(98,71,NULL,'2026-03-19','فیزیک','',20,19,0,1,'2026-03-19 13:07:09'),(99,71,NULL,'2026-03-19','شیمی','',25,21,2,2,'2026-03-19 13:07:09'),(100,71,NULL,'2026-03-19','ریاضی','',20,14,1,5,'2026-03-19 13:07:09'),(101,57,NULL,'2026-03-19','زیست ','',30,8,8,14,'2026-03-19 14:08:40'),(102,57,NULL,'2026-03-19','شیمی','',20,8,6,6,'2026-03-19 14:08:40'),(103,57,NULL,'2026-03-19','ریاضی','',15,2,1,12,'2026-03-19 14:08:40'),(104,57,NULL,'2026-03-19','فیزیک','',20,4,1,15,'2026-03-19 14:08:40'),(105,76,NULL,'2026-03-19','زیست شناسی','',20,9,4,7,'2026-03-19 14:15:53'),(106,76,NULL,'2026-03-19','شیمی','',15,5,4,6,'2026-03-19 14:15:53'),(107,76,NULL,'2026-03-19','ریاضی','',15,0,0,15,'2026-03-19 14:15:53'),(108,76,NULL,'2026-03-19','فیزیک','',15,2,5,8,'2026-03-19 14:15:53'),(109,72,NULL,'2026-03-19','زیست','',25,19,3,3,'2026-03-19 14:21:00'),(110,72,NULL,'2026-03-19','ریاضی','',15,4,0,11,'2026-03-19 14:21:00'),(111,50,NULL,'2026-03-19','زیست شناسی','',20,10,5,5,'2026-03-19 15:09:58'),(112,50,NULL,'2026-03-19','شیمی','',15,8,4,3,'2026-03-19 15:09:58'),(113,50,NULL,'2026-03-19','ریاضی','',10,4,3,3,'2026-03-19 15:09:58'),(114,50,NULL,'2026-03-19','فیزیک','',15,3,0,12,'2026-03-19 15:09:58'),(115,36,NULL,'2026-03-19','                       زیست                       ','',15,4,4,7,'2026-03-19 15:16:46'),(116,36,NULL,'2026-03-19','شیمی','',10,0,0,10,'2026-03-19 15:16:46'),(117,36,NULL,'2026-03-19','ریاضی','',15,1,4,10,'2026-03-19 15:16:46'),(118,36,NULL,'2026-03-19','فیزیک','',15,4,3,8,'2026-03-19 15:16:46'),(119,53,NULL,'2026-03-19','زیست ','',20,10,6,4,'2026-03-19 15:34:22'),(120,53,NULL,'2026-03-19','فیزیک','',15,2,2,11,'2026-03-19 15:34:22'),(121,53,NULL,'2026-03-19','شیمی','',15,7,5,3,'2026-03-19 15:34:22'),(122,53,NULL,'2026-03-19','ریاضی','',15,0,0,15,'2026-03-19 15:34:22'),(123,69,NULL,'2026-03-19','زیست شناسی','',20,7,4,9,'2026-03-19 15:56:24'),(124,69,NULL,'2026-03-19','شیمی','',15,5,0,10,'2026-03-19 15:56:24'),(125,69,NULL,'2026-03-19','ریاضی','',15,1,3,11,'2026-03-19 15:56:24'),(126,69,NULL,'2026-03-19','فیزیک','',15,6,3,6,'2026-03-19 15:56:24'),(127,55,NULL,'2026-03-19','زیست شناسی','',20,7,5,8,'2026-03-19 16:04:54'),(128,55,NULL,'2026-03-19','شیمی','',15,2,5,8,'2026-03-19 16:04:54'),(129,55,NULL,'2026-03-19','فیزیک','',13,1,1,11,'2026-03-19 16:04:54'),(130,55,NULL,'2026-03-19','ریاضی','',10,0,0,10,'2026-03-19 16:04:54'),(131,45,NULL,'2026-03-19','زیست شناسی','',30,13,11,6,'2026-03-19 16:20:36'),(132,45,NULL,'2026-03-19','شیمی','',15,4,5,6,'2026-03-19 16:20:36'),(133,45,NULL,'2026-03-19','فیزیک','',15,3,2,10,'2026-03-19 16:20:36'),(134,45,NULL,'2026-03-19','ریاضی ','',15,3,0,12,'2026-03-19 16:20:36'),(135,27,NULL,'2026-03-19','زیست شناسی','',30,14,5,11,'2026-03-19 18:37:16'),(136,27,NULL,'2026-03-19','شیمی','',25,11,5,9,'2026-03-19 18:37:16'),(137,27,NULL,'2026-03-19','ریاضی','',20,10,0,10,'2026-03-19 18:37:16'),(138,27,NULL,'2026-03-19','فیزیک','',20,9,1,10,'2026-03-19 18:37:16'),(139,19,NULL,'2026-03-27','شیمی','دهم فصل 1',15,6,1,8,'2026-03-27 09:33:37'),(140,19,NULL,'2026-03-27','هندسه ','یازدهم فصل 3',10,0,0,10,'2026-03-27 09:33:37'),(141,19,NULL,'2026-03-27','آمار و احتمال ','فصل 3 یازدهم ',10,1,1,8,'2026-03-27 09:33:37'),(142,19,NULL,'2026-03-27','گسسته ','فصل 3 ',10,0,0,10,'2026-03-27 09:33:37'),(143,19,NULL,'2026-03-27','حسابان ','مشتق',10,0,0,10,'2026-03-27 09:33:37'),(144,24,NULL,'2026-03-27','فیزیک ','فصل 3 دوازدهم ',15,2,6,7,'2026-03-27 09:55:59'),(145,24,NULL,'2026-03-27','شیمی ','فصل 4 دوازدهم ',15,4,10,1,'2026-03-27 09:55:59'),(146,24,NULL,'2026-03-27','حسابان ','تابع ',10,0,0,10,'2026-03-27 09:55:59'),(147,24,NULL,'2026-03-27','گسسته ','فصل سوم ',10,6,4,0,'2026-03-27 09:55:59'),(148,23,NULL,'2026-03-27','حسابان ','فصل 3',10,1,1,8,'2026-03-27 10:07:41'),(149,23,NULL,'2026-03-27','شیمی ','دوازدهم فصل 3',15,1,3,11,'2026-03-27 10:07:41'),(150,23,NULL,'2026-03-27','گسسته ','گراف ',10,1,0,9,'2026-03-27 10:07:41'),(151,23,NULL,'2026-03-27','فیزیک ','نوسان و موج ',15,3,0,12,'2026-03-27 10:07:41'),(152,49,NULL,'2026-03-27','زیست ','فصل 1 تا 4',25,0,6,19,'2026-03-27 11:56:59'),(153,49,NULL,'2026-03-27','فیزیک ','الکتریسیته ',15,0,0,15,'2026-03-27 11:56:59'),(154,49,NULL,'2026-03-27','شیمی ','فصل 2 آنتالپی ',15,5,2,8,'2026-03-27 11:56:59'),(155,77,NULL,'2026-03-27','عربی','',15,2,0,13,'2026-03-27 11:58:59'),(156,77,NULL,'2026-03-27','تاریخ ','',10,3,0,7,'2026-03-27 11:58:59'),(157,77,NULL,'2026-03-27','جغرافیا','',10,5,0,5,'2026-03-27 11:58:59'),(158,77,NULL,'2026-03-27','جامعه شناسی','',10,2,2,6,'2026-03-27 11:58:59'),(159,77,NULL,'2026-03-27','ریاضی','',10,4,2,4,'2026-03-27 11:58:59'),(160,52,NULL,'2026-03-27','زیست','',25,10,8,7,'2026-03-27 15:38:00'),(161,52,NULL,'2026-03-27','شیمی','',20,4,0,16,'2026-03-27 15:38:00'),(162,52,NULL,'2026-03-27','فیزیک','',20,3,3,14,'2026-03-27 15:38:00'),(163,36,NULL,'2026-03-27','شیمی','',15,5,4,6,'2026-03-27 16:35:24'),(164,36,NULL,'2026-03-27','زیست','',20,8,4,8,'2026-03-27 16:35:24'),(165,36,NULL,'2026-03-27','فیزیک','',15,3,1,11,'2026-03-27 16:35:24'),(166,36,NULL,'2026-03-27','ریاضی','',15,1,0,14,'2026-03-27 16:35:24'),(167,76,NULL,'2026-03-27','زیست','',15,4,7,4,'2026-03-27 16:52:53'),(168,76,NULL,'2026-03-27','شیمی','',15,3,8,4,'2026-03-27 16:52:53'),(169,76,NULL,'2026-03-27','ریاضی','',15,3,2,10,'2026-03-27 16:52:53'),(170,76,NULL,'2026-03-27','فیزیک','',15,5,6,4,'2026-03-27 16:52:53'),(171,32,NULL,'2026-04-03','شیمی','دوازدهم فصل 4',15,2,1,12,'2026-04-03 09:44:07'),(172,32,NULL,'2026-04-03','فیزیک ','فصل 4 دوازدهم ',13,1,3,9,'2026-04-03 09:44:07'),(173,32,NULL,'2026-04-03','گسسته','فصل 3 ',10,5,0,5,'2026-04-03 09:44:07'),(174,32,NULL,'2026-04-03','هندسه','فصل 3',10,0,0,10,'2026-04-03 09:44:07'),(175,32,NULL,'2026-04-10','هندسه ','فصل 3 دوازدهم بردار ها',10,2,0,8,'2026-04-10 09:44:02'),(176,32,NULL,'2026-04-10','حسابان ','کاربرد مشتق ',10,0,0,10,'2026-04-10 09:44:02'),(177,32,NULL,'2026-04-10','فیزیک ','فصل 4 ',15,4,3,8,'2026-04-10 09:44:02'),(178,32,NULL,'2026-04-10','شیمی','فصل 4',15,3,0,12,'2026-04-10 09:44:02'),(179,20,NULL,'2026-04-10','فیزیک ','فصل 5',15,8,2,5,'2026-04-10 10:36:19'),(180,20,NULL,'2026-04-10','شیمی','فصل 4',15,6,2,7,'2026-04-10 10:36:19'),(181,20,NULL,'2026-04-10','گسسته ','فصل آخر ',10,3,0,7,'2026-04-10 10:36:19'),(182,20,NULL,'2026-04-10','هندسه ','فصل 3 بردارض',10,0,0,10,'2026-04-10 10:36:19'),(183,19,NULL,'2026-04-10','شیمی ','',15,2,2,11,'2026-04-10 11:18:04'),(184,19,NULL,'2026-04-10','هندسه','',10,1,1,8,'2026-04-10 11:18:04'),(185,19,NULL,'2026-04-10','حسابان','',10,1,1,8,'2026-04-10 11:18:04'),(186,19,NULL,'2026-04-10','آمار و احتمال','',10,2,2,6,'2026-04-10 11:18:04'),(187,83,NULL,'2026-04-10','زیست ','',15,5,4,6,'2026-04-10 12:06:00'),(188,83,NULL,'2026-04-10','شیمی','',10,8,0,2,'2026-04-10 12:06:00'),(189,83,NULL,'2026-04-10','ریاضی ','',10,9,0,1,'2026-04-10 12:06:00'),(190,83,NULL,'2026-04-10','فیزیک','',10,4,2,4,'2026-04-10 12:06:00'),(191,24,NULL,'2026-04-10','گسسته ','',10,2,8,0,'2026-04-10 12:07:42'),(192,24,NULL,'2026-04-10','هندسه ','',10,5,1,4,'2026-04-10 12:07:42'),(193,24,NULL,'2026-04-10','شیمی','',15,2,7,6,'2026-04-10 12:07:42'),(194,24,NULL,'2026-04-10','فیزیک','',13,7,4,2,'2026-04-10 12:07:42'),(195,23,NULL,'2026-04-10','هندسه','',10,4,1,5,'2026-04-10 12:09:47'),(196,23,NULL,'2026-04-10','ریاضی','',10,1,2,7,'2026-04-10 12:09:47'),(197,23,NULL,'2026-04-10','شیمی','',10,0,0,10,'2026-04-10 12:09:47'),(198,23,NULL,'2026-04-10','فیزیک ','',10,0,5,5,'2026-04-10 12:09:47'),(199,49,NULL,'2026-04-10','زیست','',15,2,2,11,'2026-04-10 12:20:12'),(200,49,NULL,'2026-04-10','شیمی','',15,1,1,13,'2026-04-10 12:20:12'),(201,49,NULL,'2026-04-10','فیزیک ','',15,3,3,9,'2026-04-10 12:20:12'),(202,49,NULL,'2026-04-10','ریاضی','',15,1,1,13,'2026-04-10 12:20:12'),(203,72,NULL,'2026-04-10','زیست','',15,12,2,1,'2026-04-10 13:19:07'),(204,72,NULL,'2026-04-10','ریاضی','',10,5,1,4,'2026-04-10 13:19:07'),(205,72,NULL,'2026-04-10','شیمی','',10,0,0,10,'2026-04-10 13:19:07'),(206,72,NULL,'2026-04-10','فیزیک ','',10,7,2,1,'2026-04-10 13:19:07'),(207,55,NULL,'2026-04-10','زیست','',15,7,4,4,'2026-04-10 15:12:50'),(208,55,NULL,'2026-04-10','شیمی','',15,4,5,6,'2026-04-10 15:12:50'),(209,55,NULL,'2026-04-10','ریاضی ','',10,0,0,10,'2026-04-10 15:12:50'),(210,55,NULL,'2026-04-10','فیزیک','',15,2,2,11,'2026-04-10 15:12:50'),(211,19,NULL,'2026-04-17','هندسه ','مقاطع مخروطی',10,4,2,4,'2026-04-17 09:32:30'),(212,19,NULL,'2026-04-17','گسسته ','نظریه اعداد',10,0,5,5,'2026-04-17 09:32:30'),(213,19,NULL,'2026-04-17','فیزیک ض','سرعت و شتاب حرکت شناسی',10,5,2,3,'2026-04-17 09:32:30'),(214,19,NULL,'2026-04-17','شیمی','آب آهنگ زندگی فصل 3',10,4,2,4,'2026-04-17 09:32:30'),(215,32,NULL,'2026-04-17','فیزیک ','دهم فصل 4',15,5,2,8,'2026-04-17 09:36:26'),(216,32,NULL,'2026-04-17','هندسه ','دهم فصل 3',15,0,0,15,'2026-04-17 09:36:26'),(217,32,NULL,'2026-04-17','ریاضی ','دهم شمارش ',15,6,3,6,'2026-04-17 09:36:26'),(218,32,NULL,'2026-04-17','شیمی','دوازدهم فصل 1',15,4,3,8,'2026-04-17 09:36:26'),(219,23,NULL,'2026-04-17','شیمی','دهم فصل 3',15,0,0,15,'2026-04-17 09:48:45'),(220,23,NULL,'2026-04-17','فیزیک ','نوسان و موج',15,3,4,8,'2026-04-17 09:48:45'),(221,23,NULL,'2026-04-17','حسابان ','فصل 4 مشتق',10,0,1,9,'2026-04-17 09:48:45'),(222,23,NULL,'2026-04-17','هندسه ','بردار ها',10,1,0,9,'2026-04-17 09:48:45'),(223,20,NULL,'2026-04-17','فیزیک ','فصل 6',15,13,0,2,'2026-04-17 10:15:32'),(224,20,NULL,'2026-04-17','شیمی','فصل 3',15,4,3,8,'2026-04-17 10:15:32'),(225,20,NULL,'2026-04-17','حسابان ','دوازدهم فصل 6 ',10,2,1,7,'2026-04-17 10:15:32'),(226,20,NULL,'2026-04-17','هندسه ','فصل 4',10,3,0,7,'2026-04-17 10:15:32'),(227,20,NULL,'2026-04-17','گسسته ','فصل 3',10,4,1,5,'2026-04-17 10:15:32'),(228,24,NULL,'2026-04-17','شیمی ','فصل 3',15,4,7,4,'2026-04-17 10:21:23'),(229,24,NULL,'2026-04-17','حسابان','',10,0,0,10,'2026-04-17 10:21:23'),(230,24,NULL,'2026-04-17','گسسته ','',10,4,3,3,'2026-04-17 10:21:23'),(231,24,NULL,'2026-04-17','هندسه ','',10,6,2,2,'2026-04-17 10:21:23'),(232,24,NULL,'2026-04-24','حسابان ','مشتق',10,0,0,10,'2026-04-24 09:03:40'),(233,24,NULL,'2026-04-24','شیمی','فصل 2 سلول های الکترولیتی ',15,3,10,2,'2026-04-24 09:03:40'),(234,24,NULL,'2026-04-24','هندسه ','مقاطع مخروطی',15,0,0,15,'2026-04-24 09:03:40'),(235,24,NULL,'2026-04-24','گسسته ','گراف',15,3,5,7,'2026-04-24 09:03:40'),(236,19,NULL,'2026-04-24','فیزیک','فصل 2 دینامیک',13,1,4,8,'2026-04-24 09:23:19'),(237,19,NULL,'2026-04-24','شیمی ','فصل 2 سلول های سوختی',15,2,2,11,'2026-04-24 09:23:19'),(238,19,NULL,'2026-04-24','گسسته ','گراف ',15,2,4,9,'2026-04-24 09:23:19'),(239,21,NULL,'2026-04-24','فیزیک ','فیزیک اتمی و هسته ای ',15,1,0,14,'2026-04-24 09:43:53'),(240,21,NULL,'2026-04-24','شیمی','فصل 3',15,1,0,14,'2026-04-24 09:43:53'),(241,21,NULL,'2026-04-24','حسابان ','مشتق',15,0,0,15,'2026-04-24 09:43:53'),(242,32,NULL,'2026-04-24','شیمی ','فصل دوم ',15,3,2,10,'2026-04-24 10:37:57'),(243,32,NULL,'2026-04-24','هندسه','دهم چند ضلعی ها ',10,2,0,8,'2026-04-24 10:37:57'),(244,32,NULL,'2026-04-24','فیزیک','دهم فصل 4',15,4,2,9,'2026-04-24 10:37:57'),(245,32,NULL,'2026-04-24','ریاضی','دهم آمار و احتمال',15,5,4,6,'2026-04-24 10:37:57'),(246,20,NULL,'2026-04-24','هندسه ','فصل 3',20,7,2,11,'2026-04-24 10:43:21'),(247,20,NULL,'2026-04-24','حسابان ','کاربرد مشتق ',20,2,2,16,'2026-04-24 10:43:21'),(248,20,NULL,'2026-04-24','فیزیک ','دهم فصل 2 فشار ',20,12,1,7,'2026-04-24 10:43:21'),(249,69,NULL,'2026-04-24','زیست ','فصل 7 دهم ',15,7,3,5,'2026-04-24 13:17:20'),(250,69,NULL,'2026-04-24','شیمی ','فصل 2 یازدهم ',15,0,0,15,'2026-04-24 13:17:20'),(251,69,NULL,'2026-04-24','ریاضی','فصل 7 دوازدهم ',15,0,2,13,'2026-04-24 13:17:20'),(252,71,NULL,'2026-04-24','زیست ','جامع ',45,33,11,1,'2026-04-24 13:19:44'),(253,71,NULL,'2026-04-24','شیمی','جامع ',35,28,4,3,'2026-04-24 13:19:44'),(254,71,NULL,'2026-04-24','ریاضی','جامع ',30,20,2,8,'2026-04-24 13:19:44'),(255,71,NULL,'2026-04-24','فیزیک','جامع ',30,20,6,4,'2026-04-24 13:19:44'),(260,83,NULL,'2026-04-24','زیست ','فصل 4 تا 8 یازدهم ',25,14,8,3,'2026-04-24 14:31:36'),(261,83,NULL,'2026-04-24','شیمی ','فصل 1و2 یازدهم ',15,4,5,6,'2026-04-24 14:31:36'),(262,83,NULL,'2026-04-24','ریاضی','تابع مثلثاتی',15,0,1,14,'2026-04-24 14:31:36'),(263,83,NULL,'2026-04-24','فیزیک ','فصل 1و4 دهم ',15,1,1,13,'2026-04-24 14:31:36'),(264,36,NULL,'2026-10-02','زیست','فصل 1و2 دهم',20,13,4,3,'2026-10-02 11:26:39'),(265,36,NULL,'2026-10-02','شیمی','فصل 1 دهم تا نور ',15,4,4,7,'2026-10-02 11:26:39'),(266,36,NULL,'2026-10-02','ریاضی','فصل 1 دهم',15,3,1,11,'2026-10-02 11:26:39'),(267,36,NULL,'2026-10-02','فیزیک','فصل 1 دهم',15,7,2,6,'2026-10-02 11:26:39'),(268,94,NULL,'2026-10-02','زیست','فصل 1 گفتار 1و2',15,5,5,5,'2026-10-02 11:31:16'),(269,94,NULL,'2026-10-02','شیمی','فصل 1 تا جدول تناوبی',15,3,1,11,'2026-10-02 11:31:16'),(270,94,NULL,'2026-10-02','ریاضی','فصل 1 درس 1و2',15,2,1,12,'2026-10-02 11:31:16'),(271,94,NULL,'2026-10-02','فیزیک ','فصل 1 تا اول چگالی',15,2,1,12,'2026-10-02 11:31:16'),(272,92,NULL,'2026-10-02','زیست','فصل 1 یازدهم',15,4,4,7,'2026-10-02 12:30:54'),(273,92,NULL,'2026-10-02','فیزیک','فصل 1 یازدهم تا قبل قانون کلن ',15,6,2,7,'2026-10-02 12:30:54'),(274,92,NULL,'2026-10-02','ریاضی','فصل 1 یازدهم درس 1',15,1,1,13,'2026-10-02 12:30:54'),(275,92,NULL,'2026-10-02','شیمی ','یازدهم درس 5 ',15,7,3,5,'2026-10-02 12:30:54'),(276,110,NULL,'2026-10-02','زیست','فصل 1 دوازدهم گفتار 1و2',20,5,0,15,'2026-10-02 12:34:33'),(277,110,NULL,'2026-10-02','شیمی','فصل 1 دوازدهم تا رسانایی محول اسید و باز',15,9,2,4,'2026-10-02 12:34:33'),(278,110,NULL,'2026-10-02','ریاضی ','فصل 1 دوازدهم تا درس 4',14,0,1,13,'2026-10-02 12:34:33'),(279,110,NULL,'2026-10-02','فیزیک','فصل 1 تا نمودار شتاب',15,5,1,9,'2026-10-02 12:34:33'),(280,50,NULL,'2026-10-02','زیست ','فصل 1 یازدهم گفتار 1',15,8,7,0,'2026-10-02 12:51:06'),(281,50,NULL,'2026-10-02','فیزیک','فصل 1 یازدهم تا کلن',15,6,3,6,'2026-10-02 12:51:06'),(282,50,NULL,'2026-10-02','ریاضی ','فصل 1 درس 1 یازدهم',15,7,3,5,'2026-10-02 12:51:06'),(283,50,NULL,'2026-10-02','شیمی','فصل 1 تا شعاع اتمی ',15,12,3,0,'2026-10-02 12:51:06'),(284,93,NULL,'2026-10-02','زیست ','فصل 1 یازدهم گفتار 1',15,6,7,2,'2026-10-02 12:53:23'),(285,93,NULL,'2026-10-02','شیمی','فصل 1 یازدهم تا شعاع اتمی',15,10,4,1,'2026-10-02 12:53:23'),(286,93,NULL,'2026-10-02','فیزیک ','فصل 1 یازدهم تا قانون کلن',15,0,3,12,'2026-10-02 12:53:23'),(287,53,NULL,'2026-10-02','زیست ','فصل 1و2 دهم تا گفتار 1 ',15,6,6,3,'2026-10-02 12:55:17'),(288,53,NULL,'2026-10-02','شیمی ','فصل 1 دهم ',15,6,3,6,'2026-10-02 12:55:17'),(289,53,NULL,'2026-10-02','ریاضی ','فصل 1 دهم',15,0,0,15,'2026-10-02 12:55:17'),(290,53,NULL,'2026-10-02','فیزیک ','فصل 1 دهم ',15,1,3,11,'2026-10-02 12:55:17'),(291,101,NULL,'2026-10-02','زیست ','فصل 1 یازدهم گفتار 1',15,4,2,9,'2026-10-02 12:59:10'),(292,101,NULL,'2026-10-02','شیمی','فصل 1 یازدهم تا شعاع اتمی',15,5,3,7,'2026-10-02 12:59:10'),(293,101,NULL,'2026-10-02','ریاضی ','فصل 1 یازدهم درس 1',15,0,0,15,'2026-10-02 12:59:10'),(294,101,NULL,'2026-10-02','فیزیک','فصل 1 یازدهم تا کلن ',15,4,5,6,'2026-10-02 12:59:10'),(530,50,NULL,'2026-10-16','زیست ','فصل 2 یازدهم ',20,10,8,2,'2026-10-16 10:35:30'),(531,50,NULL,'2026-10-16','فیزیک ','فصل 3 یازدهم ',15,5,4,6,'2026-10-16 10:35:30'),(532,50,NULL,'2026-10-16','شیمی','فصل 1 یازدهم',15,6,7,2,'2026-10-16 10:35:30'),(533,50,NULL,'2026-10-16','ریاضی','فصل 1 یازدهم',15,5,1,9,'2026-10-16 10:35:30'),(534,119,NULL,'2026-10-16','زیست ','فصل 2و3 دهم',25,10,4,11,'2026-10-16 10:55:55'),(535,119,NULL,'2026-10-16','فیزیک ','فصل 2 دهم',20,1,3,16,'2026-10-16 10:55:55'),(536,119,NULL,'2026-10-16','شیمی','فصل 1 دهم',20,2,2,16,'2026-10-16 10:55:55'),(537,119,NULL,'2026-10-16','ریاضی','فصل 2 دهم',15,2,5,8,'2026-10-16 10:55:55'),(538,123,NULL,'2026-10-22','ریاضی ','دوازدهم درس 2',10,1,4,5,'2026-10-22 17:06:55'),(539,123,NULL,'2026-10-22','ادبیات ','درس 3و 4 ',15,3,9,3,'2026-10-22 17:06:55'),(540,123,NULL,'2026-10-22','عربی ','درس 2 ',15,6,6,3,'2026-10-22 17:06:55'),(541,123,NULL,'2026-10-22','تاریخ ','درس 3',10,2,8,0,'2026-10-22 17:06:55'),(542,123,NULL,'2026-10-22','علوم اجتماعی','درس 3',10,3,5,2,'2026-10-22 17:06:55'),(543,123,NULL,'2026-10-22','فلسفه ','درس 3',10,5,3,2,'2026-10-22 17:06:55'),(544,106,NULL,'2026-10-22','ریاضی','فصل 1 ',10,2,1,7,'2026-10-22 17:09:52'),(545,106,NULL,'2026-10-22','ادبیات','درس 5و6 ',13,6,0,7,'2026-10-22 17:09:52'),(546,106,NULL,'2026-10-22','عربی ','درس 3و4',15,5,0,10,'2026-10-22 17:09:52'),(547,106,NULL,'2026-10-22','تاریخ ','درس 3',10,5,3,2,'2026-10-22 17:09:52'),(548,106,NULL,'2026-10-22','علوم اجتماعی','درس 2',10,6,2,2,'2026-10-22 17:09:52'),(549,106,NULL,'2026-10-22','جغرافیا','درس 4',10,8,2,0,'2026-10-22 17:09:52'),(550,52,NULL,'2026-10-22','ریاضی','فصل 1 درس 1',10,3,0,7,'2026-10-22 17:13:06'),(551,52,NULL,'2026-10-22','ادبیات ','درس 6و7',15,5,4,6,'2026-10-22 17:13:06'),(552,52,NULL,'2026-10-22','عربی','درس 2 ',10,0,2,8,'2026-10-22 17:13:06'),(553,52,NULL,'2026-10-22','تاریخ ','درس 2',10,5,1,4,'2026-10-22 17:13:06'),(554,52,NULL,'2026-10-22','جغرافیا','درس 1 و 2 و 3',10,5,3,2,'2026-10-22 17:13:06'),(555,52,NULL,'2026-10-22','علوم اجتماعی','درس 9 ',10,3,4,3,'2026-10-22 17:13:06'),(556,52,NULL,'2026-10-22','فلسفه ','درس 3 ',15,5,3,7,'2026-10-22 17:13:06'),(557,107,NULL,'2026-10-22','ریاضی','فصل 1 ',10,3,5,2,'2026-10-22 17:31:52'),(558,107,NULL,'2026-10-22','ادبیات','درس 3و4',15,8,7,0,'2026-10-22 17:31:52'),(559,107,NULL,'2026-10-22','عربی ','درس 3',15,7,7,1,'2026-10-22 17:31:52'),(560,107,NULL,'2026-10-22','جغرافیا ','درس 1 و 2',10,4,4,2,'2026-10-22 17:31:52'),(561,107,NULL,'2026-10-22','علوم اجتماعی','درس 3',10,8,1,1,'2026-10-22 17:31:52'),(562,127,NULL,'2026-10-22','ریاضی ','فصل 1 و 2',10,4,2,4,'2026-10-22 17:54:50'),(563,127,NULL,'2026-10-22','عربی','درس 1 تا 3',10,7,2,1,'2026-10-22 17:54:50'),(564,127,NULL,'2026-10-22','علوم','',40,22,5,13,'2026-10-22 17:54:50'),(565,127,NULL,'2026-10-22','مطالعات ','',10,6,3,1,'2026-10-22 17:54:50'),(566,127,NULL,'2026-10-22','فارسی','',10,8,1,1,'2026-10-22 17:54:50'),(567,94,NULL,'2026-10-23','زیست ','فصل 1 کامل',15,4,5,6,'2026-10-23 09:59:28'),(568,94,NULL,'2026-10-23','فیزیک','فصل 1 کامل',15,1,1,13,'2026-10-23 09:59:28'),(569,94,NULL,'2026-10-23','شیمی ','فصل 1 تا آخر نور',15,1,1,13,'2026-10-23 09:59:28'),(570,94,NULL,'2026-10-23','ریاضی ','فصل 1 کامل',15,1,2,12,'2026-10-23 09:59:28'),(571,129,NULL,'2026-10-23','زیست','فصل 1و2 دوازدهم ',25,13,11,1,'2026-10-23 10:14:47'),(572,129,NULL,'2026-10-23','فیزیک','فصل 1 دوازدهم',20,12,2,6,'2026-10-23 10:14:47'),(573,129,NULL,'2026-10-23','شیمی','فصل 1 دوازدهم',20,4,6,10,'2026-10-23 10:14:47'),(574,129,NULL,'2026-10-23','ریاضی','تابع',20,3,2,15,'2026-10-23 10:14:47'),(575,36,NULL,'2026-10-23','زیست ','',15,10,1,4,'2026-10-23 20:46:39'),(576,36,NULL,'2026-10-23','ریاضی','',15,3,4,8,'2026-10-23 20:46:39'),(577,36,NULL,'2026-10-23','فیزیک ','',15,9,2,4,'2026-10-23 20:46:39'),(578,36,NULL,'2026-10-23','شیمی','',15,6,4,5,'2026-10-23 20:46:39'),(579,53,NULL,'2026-10-23','زیست','',15,7,3,5,'2026-10-23 20:47:50'),(580,53,NULL,'2026-10-23','شیمی','',15,7,5,3,'2026-10-23 20:47:50'),(581,53,NULL,'2026-10-23','ریاضی','',15,1,2,12,'2026-10-23 20:47:50'),(582,53,NULL,'2026-10-23','فیزیک ','',15,3,5,7,'2026-10-23 20:47:50'),(583,93,NULL,'2026-10-23','زیست ','',15,4,7,4,'2026-10-23 20:48:32'),(584,93,NULL,'2026-10-23','شیمی','',15,8,5,2,'2026-10-23 20:48:32'),(585,93,NULL,'2026-10-23','ریاضی','',15,0,0,15,'2026-10-23 20:48:32'),(586,93,NULL,'2026-10-23','فیزیک','',15,3,2,10,'2026-10-23 20:48:32'),(587,101,NULL,'2026-10-23','زیست','',15,2,6,7,'2026-10-23 20:49:18'),(588,101,NULL,'2026-10-23','شیمی ','',15,7,3,5,'2026-10-23 20:49:18'),(589,101,NULL,'2026-10-23','ریاضی ','',15,0,2,13,'2026-10-23 20:49:18'),(590,101,NULL,'2026-10-23','فیزیک ','',15,2,2,11,'2026-10-23 20:49:18'),(591,133,NULL,'2026-10-23','زیست','',15,8,4,3,'2026-10-23 20:50:43'),(592,133,NULL,'2026-10-23','شیمی','',15,3,3,9,'2026-10-23 20:50:43'),(593,133,NULL,'2026-10-23','ریاضی ','',15,7,2,6,'2026-10-23 20:50:43'),(594,133,NULL,'2026-10-23','فیزیک','',15,6,1,8,'2026-10-23 20:50:43'),(595,96,NULL,'2026-10-23','زیست ','',15,9,3,3,'2026-10-23 20:53:25'),(596,96,NULL,'2026-10-23','شیمی','',15,8,1,6,'2026-10-23 20:53:25'),(597,96,NULL,'2026-10-23','ریاضی','',15,3,3,9,'2026-10-23 20:53:25'),(598,96,NULL,'2026-10-23','فیزیک ','',15,7,2,6,'2026-10-23 20:53:25'),(599,97,NULL,'2026-10-23','زیست','',15,2,5,8,'2026-10-23 20:54:04'),(600,97,NULL,'2026-10-23','شیمی','',15,7,4,4,'2026-10-23 20:54:04'),(601,97,NULL,'2026-10-23','ریاضی ','',15,2,3,10,'2026-10-23 20:54:04'),(602,97,NULL,'2026-10-23','فیزیک','',15,2,2,11,'2026-10-23 20:54:04');
/*!40000 ALTER TABLE `exam_results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exams`
--

DROP TABLE IF EXISTS `exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exams` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shelf_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `student_id` int NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('in_progress','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'in_progress',
  `total_questions` int DEFAULT '0',
  `correct_answers` int DEFAULT '0',
  `score` decimal(5,2) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exams`
--

LOCK TABLES `exams` WRITE;
/*!40000 ALTER TABLE `exams` DISABLE KEYS */;
/*!40000 ALTER TABLE `exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `files` (
  `id` int NOT NULL AUTO_INCREMENT,
  `owner_type` enum('student','supporter','admin') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نوع مالک فایل',
  `owner_id` int NOT NULL COMMENT 'شناسه مالک فایل',
  `file_type` enum('exam','report','other') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نوع فایل',
  `report_date` date NOT NULL COMMENT 'تاریخ مربوطه',
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'مسیر ذخیره',
  `file_size` int DEFAULT NULL COMMENT 'حجم فایل به بایت',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'توضیحات فایل',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `files`
--

LOCK TABLES `files` WRITE;
/*!40000 ALTER TABLE `files` DISABLE KEYS */;
INSERT INTO `files` VALUES (1,'student',2,'report','2025-12-16','uploads/reports/2_1765902534.jpg',NULL,NULL,'2025-12-16 19:58:54'),(3,'student',2,'report','2025-12-16','uploads/reports/2_1765913125.jpg',NULL,NULL,'2025-12-16 22:55:25'),(4,'student',2,'report','2025-12-16','uploads/reports/2_1765913159.jpg',NULL,NULL,'2025-12-16 22:55:59'),(5,'student',2,'report','2025-12-16','uploads/reports/2_1765913159.jpg',NULL,NULL,'2025-12-16 22:55:59'),(6,'student',2,'report','2025-12-16','uploads/reports/2_1765913160.jpg',NULL,NULL,'2025-12-16 22:56:00'),(7,'student',2,'report','2025-12-16','uploads/reports/2_1765913160.jpg',NULL,NULL,'2025-12-16 22:56:00'),(8,'student',2,'report','2025-12-20','uploads/reports/2_1766255302.jpg',NULL,NULL,'2025-12-20 21:58:22'),(9,'student',6,'report','2025-12-22','uploads/reports/6_1766420603.jpg',NULL,NULL,'2025-12-22 19:53:23'),(10,'student',6,'report','2025-12-27','uploads/reports/6_1766843204.jpg',NULL,NULL,'2025-12-27 17:16:44'),(11,'student',6,'report','2025-12-27','uploads/reports/6_1766844398.jpg',NULL,NULL,'2025-12-27 17:36:38'),(14,'student',6,'report','2026-01-08','uploads/reports/6_1767874590.jpg',NULL,NULL,'2026-01-08 15:46:30'),(15,'student',50,'exam','2026-10-16','exams/50/6ad1c978a63a3.pdf',1158288,'','2026-10-16 10:21:36');
/*!40000 ALTER TABLE `files` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grades`
--

DROP TABLE IF EXISTS `grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grades` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grades`
--

LOCK TABLES `grades` WRITE;
/*!40000 ALTER TABLE `grades` DISABLE KEYS */;
INSERT INTO `grades` VALUES (1,'7'),(2,'8'),(3,'9'),(4,'10'),(5,'11'),(6,'12');
/*!40000 ALTER TABLE `grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `instructors`
--

DROP TABLE IF EXISTS `instructors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `instructors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'نام و نام خانوادگی',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'عنوان/سمت',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT 'توضیحات',
  `image_url` text COLLATE utf8mb4_unicode_ci COMMENT 'آدرس تصویر (اختیاری)',
  `initial_letter` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'حرف اول برای نمایش',
  `display_order` int DEFAULT '0' COMMENT 'ترتیب نمایش',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_instructors_id` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `instructors`
--

LOCK TABLES `instructors` WRITE;
/*!40000 ALTER TABLE `instructors` DISABLE KEYS */;
INSERT INTO `instructors` VALUES (1,'دکتر محمد موسوی','مشاور تخصصی تحصیلی','',NULL,'م',1,'2025-12-19 14:24:00','2026-10-15 08:41:27'),(2,'استاد علی موسوی','مدرس ریاضی','','instructors/6ae0c1c626970.webp','ا',2,'2025-12-19 14:24:00','2026-10-27 18:52:46'),(3,'دکتر اسماعیل حاجیان','مدرس فیزیک','','instructors/6ae0c1e6ace84.webp','د',3,'2025-12-19 14:24:00','2026-10-27 18:53:18'),(4,'استاد رضا نصرالله زاده','مدرس شیمی','',NULL,'ر',4,'2025-12-19 14:24:00','2026-10-15 08:41:27'),(9,'دکتر طاها حبیبی','مدرس شیمی','دانشجوی دانشگاه علوم پزشکی بابل\r\n','instructors/6ae0c1f35307b.webp','د',5,'2026-01-23 12:47:28','2026-10-28 01:04:40'),(10,'دکتر مانی محسنی','مدرس زیست','','instructors/6ae0bfbfd9545.webp','د',0,'2026-09-18 09:39:31','2026-10-27 18:44:07'),(11,'استاد رضا رحمان زاده ','مدرس ادبیات و عربی','دارای ۷ سال سابقه تدریس تخصصی ادبیات و عربی، مدرس رسمی ادبیات فارسی آموزش و پرورش، رتبه دو‌رقمی کنکور سراسری رشته علوم انسانی سال ۹۸ و دارنده مدال برنز کشوری المپیاد ادبی.','instructors/6ae0e64b7db1c4.15890484.webp','ا',0,'2026-09-30 00:07:27','2026-10-27 21:28:35'),(12,'دکتر فاطمه میریان','مدرس زبان انگلیسی','دارای ۴ سال سابقه تدریس تخصصی زبان انگلیسی، فارغ‌التحصیل رشته پزشکی عمومی از دانشگاه علوم پزشکی بابل و دارای دیپلم زبان انگلیسی از کانون زبان ایران. ایشان در کنکور سراسری سال ۹۸ رتبه ۳۸۹ را کسب کرده‌اند.','instructors/6ae0e692395958.18779445.webp','د',0,'2026-09-30 00:07:47','2026-10-27 21:29:46');
/*!40000 ALTER TABLE `instructors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_codes`
--

DROP TABLE IF EXISTS `login_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_codes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `code` varchar(6) COLLATE utf8mb4_persian_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_code` (`code`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_codes`
--

LOCK TABLES `login_codes` WRITE;
/*!40000 ALTER TABLE `login_codes` DISABLE KEYS */;
INSERT INTO `login_codes` VALUES (3,0,'588822','2026-09-22 21:51:47',1,'2026-09-23 01:16:47'),(4,0,'200034','2026-09-22 21:57:04',1,'2026-09-23 01:22:04'),(5,0,'377138','2026-09-22 21:57:08',1,'2026-09-23 01:22:08'),(6,0,'749026','2026-09-22 21:58:10',1,'2026-09-23 01:23:10'),(7,0,'657693','2026-09-22 21:59:37',1,'2026-09-23 01:24:37'),(8,0,'131064','2026-09-22 22:00:12',1,'2026-09-23 01:25:12'),(9,0,'243989','2026-09-22 22:00:15',1,'2026-09-23 01:25:15'),(10,0,'914725','2026-09-22 22:33:12',1,'2026-09-23 01:58:12'),(11,0,'836851','2026-09-22 23:13:44',1,'2026-09-23 02:38:44'),(12,0,'258422','2026-09-22 23:13:49',1,'2026-09-23 02:38:49'),(13,0,'401971','2026-09-22 23:15:05',1,'2026-09-23 02:40:05'),(14,0,'187786','2026-09-22 23:15:07',1,'2026-09-23 02:40:07'),(15,0,'168849','2026-09-22 23:58:17',1,'2026-09-23 03:23:17'),(16,0,'943968','2026-09-22 23:58:20',1,'2026-09-23 03:23:20'),(17,6,'976632','2026-09-23 00:04:03',1,'2026-09-23 03:29:03'),(18,6,'219214','2026-09-23 00:09:24',1,'2026-09-23 03:34:24'),(19,0,'511251','2026-09-23 12:39:46',1,'2026-09-23 16:04:46'),(20,6,'367031','2026-09-23 12:40:48',1,'2026-09-23 16:05:48'),(21,6,'887948','2026-09-23 12:47:26',1,'2026-09-23 16:12:26'),(22,0,'530308','2026-09-23 12:50:39',1,'2026-09-23 16:15:39'),(23,6,'565343','2026-09-23 12:50:58',1,'2026-09-23 16:15:58'),(24,0,'351260','2026-09-23 12:51:24',1,'2026-09-23 16:16:24'),(25,6,'755777','2026-09-23 13:53:50',1,'2026-09-23 17:18:50'),(26,0,'447463','2026-09-23 14:03:13',1,'2026-09-23 17:28:13'),(27,0,'371457','2026-09-23 14:03:51',1,'2026-09-23 17:28:51'),(28,0,'921258','2026-09-23 14:05:34',1,'2026-09-23 17:30:34'),(29,0,'242027','2026-09-23 14:10:38',1,'2026-09-23 17:35:38'),(30,0,'306063','2026-09-24 08:55:32',1,'2026-09-24 12:20:32'),(31,0,'141097','2026-09-24 08:59:23',1,'2026-09-24 12:24:23'),(32,0,'008910','2026-09-24 08:59:39',1,'2026-09-24 12:24:39'),(33,0,'392321','2026-09-24 12:07:14',1,'2026-09-24 15:32:14'),(34,0,'940241','2026-09-24 12:07:15',1,'2026-09-24 15:32:15'),(35,0,'994535','2026-09-24 12:14:16',1,'2026-09-24 15:39:16'),(36,0,'012340','2026-09-24 18:09:15',1,'2026-09-24 21:34:15'),(37,0,'924605','2026-09-24 20:40:53',1,'2026-09-25 00:05:53'),(38,0,'702169','2026-09-25 00:28:24',1,'2026-09-25 03:53:24'),(39,0,'829455','2026-09-25 00:29:27',1,'2026-09-25 03:54:27'),(40,0,'667867','2026-09-25 00:59:46',1,'2026-09-25 04:24:46'),(41,0,'188520','2026-09-25 09:25:01',1,'2026-09-25 12:50:01'),(42,6,'196015','2026-09-25 13:17:31',1,'2026-09-25 16:42:32'),(43,6,'317965','2026-09-25 14:01:40',1,'2026-09-25 17:26:40'),(44,6,'029135','2026-09-29 19:35:06',1,'2026-09-29 23:00:06'),(45,6,'206155','2026-09-29 22:10:04',1,'2026-09-30 01:35:04'),(46,6,'106689','2026-09-29 22:13:14',1,'2026-09-30 01:38:14'),(47,6,'991768','2026-10-01 03:09:43',1,'2026-10-01 06:34:43'),(48,6,'645088','2026-10-01 11:50:40',1,'2026-10-01 15:15:40'),(49,6,'168344','2026-10-02 07:22:01',1,'2026-10-02 10:47:01'),(50,6,'220323','2026-10-02 10:51:22',1,'2026-10-02 14:16:22'),(51,6,'848242','2026-10-02 10:51:36',1,'2026-10-02 14:16:36'),(52,0,'708892','2026-10-02 12:46:00',1,'2026-10-02 16:11:00'),(53,0,'433286','2026-10-02 12:55:43',1,'2026-10-02 16:20:43'),(54,6,'955206','2026-10-02 16:43:32',1,'2026-10-02 20:08:32'),(55,6,'714030','2026-10-02 21:10:27',1,'2026-10-03 00:35:27'),(56,0,'955322','2026-10-03 09:13:36',0,'2026-10-03 12:38:36'),(57,6,'506292','2026-10-03 09:16:28',1,'2026-10-03 12:41:28'),(58,6,'760132','2026-10-03 09:20:59',1,'2026-10-03 12:45:59'),(59,6,'394483','2026-10-03 09:41:17',1,'2026-10-03 13:06:17'),(60,6,'992957','2026-10-03 09:47:48',1,'2026-10-03 13:12:48'),(61,6,'312885','2026-10-03 12:35:12',1,'2026-10-03 16:00:12'),(62,6,'404619','2026-10-03 12:44:38',1,'2026-10-03 16:09:38'),(63,6,'442169','2026-10-03 12:46:06',1,'2026-10-03 16:11:06'),(64,6,'561791','2026-10-03 12:57:34',1,'2026-10-03 16:22:34'),(65,6,'296775','2026-10-03 17:26:11',1,'2026-10-03 20:51:11'),(66,6,'330662','2026-10-04 14:11:55',1,'2026-10-04 17:36:55'),(67,95,'732183','2026-10-14 18:16:08',1,'2026-10-14 21:41:08'),(68,95,'552464','2026-10-14 18:32:02',1,'2026-10-14 21:57:02'),(69,95,'817706','2026-10-14 18:36:33',0,'2026-10-14 22:01:33'),(70,161,'649095','2026-10-14 18:40:58',1,'2026-10-14 22:05:58'),(71,161,'024192','2026-10-14 18:44:59',1,'2026-10-14 22:09:59'),(72,161,'538752','2026-10-14 18:45:46',1,'2026-10-14 22:10:46'),(73,161,'805596','2026-10-14 18:51:49',1,'2026-10-14 22:16:49'),(74,161,'928322','2026-10-14 19:19:12',1,'2026-10-14 22:44:12'),(75,161,'131605','2026-10-14 19:47:56',1,'2026-10-14 23:12:56'),(76,6,'350417','2026-10-15 05:40:40',0,'2026-10-15 09:05:40'),(77,161,'002506','2026-10-15 06:18:22',1,'2026-10-15 09:43:22'),(78,161,'825879','2026-10-16 06:25:20',1,'2026-10-16 09:50:20'),(79,161,'339498','2026-10-22 08:41:04',1,'2026-10-22 12:06:04'),(80,161,'075260','2026-10-22 12:45:05',1,'2026-10-22 16:10:05'),(81,161,'285255','2026-10-23 16:21:03',1,'2026-10-23 19:46:03'),(82,161,'057402','2026-10-23 17:33:52',1,'2026-10-23 20:58:52'),(83,161,'218652','2026-10-23 18:43:47',1,'2026-10-23 22:08:47'),(84,179,'749930','2026-10-26 17:36:07',0,'2026-10-26 21:01:07'),(85,161,'044309','2026-10-27 14:15:03',1,'2026-10-27 17:40:03'),(86,161,'504025','2026-10-28 22:18:58',1,'2026-10-29 01:43:58'),(87,161,'445281','2026-10-28 22:36:42',1,'2026-10-29 02:01:42');
/*!40000 ALTER TABLE `login_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_persian_ci NOT NULL,
  `executed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'002_fix_weekly_plans.sql','2026-09-23 00:05:33'),(2,'003_add_2fa.sql','2026-09-24 12:18:58'),(3,'004_fix_exam_percentage.sql','2026-09-24 12:18:58'),(4,'005_planner_backend.sql','2026-09-24 15:02:16');
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parent_contacts`
--

DROP TABLE IF EXISTS `parent_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parent_contacts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL COMMENT 'ارتباط با جدول students',
  `parent_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'نام والد (اختیاری)',
  `relationship` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'نسبت: father, mother, guardian',
  `phone` varchar(15) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'شماره همراه والد',
  `is_primary` tinyint(1) DEFAULT '0' COMMENT 'آیا شماره اصلی است؟',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent_contacts_student` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parent_contacts`
--

LOCK TABLES `parent_contacts` WRITE;
/*!40000 ALTER TABLE `parent_contacts` DISABLE KEYS */;
INSERT INTO `parent_contacts` VALUES (1,84,'پدر','father','09031816120',1,'2026-09-30 01:45:56','2026-09-30 01:45:56');
/*!40000 ALTER TABLE `parent_contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plan_templates`
--

DROP TABLE IF EXISTS `plan_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_persian_ci NOT NULL,
  `student_id` int DEFAULT NULL,
  `week_date` varchar(20) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `items_json` longtext COLLATE utf8mb4_persian_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_templates`
--

LOCK TABLES `plan_templates` WRITE;
/*!40000 ALTER TABLE `plan_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `plan_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `questions`
--

DROP TABLE IF EXISTS `questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `questions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `record_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `doc_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'Question',
  `question_text` text COLLATE utf8mb4_unicode_ci,
  `choices` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `topics` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `question_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `difficulty` int DEFAULT '0',
  `references` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_created` datetime DEFAULT NULL,
  `duplicate` tinyint DEFAULT '0',
  `result` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'پاسخ صحیح (مثلاً 1,2,3,4)',
  `lesson` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `questions_chk_1` CHECK (json_valid(`choices`)),
  CONSTRAINT `questions_chk_2` CHECK (json_valid(`topics`)),
  CONSTRAINT `questions_chk_3` CHECK (json_valid(`references`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `questions`
--

LOCK TABLES `questions` WRITE;
/*!40000 ALTER TABLE `questions` DISABLE KEYS */;
/*!40000 ALTER TABLE `questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `records`
--

DROP TABLE IF EXISTS `records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `record_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shelf_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `doc_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Question یا غیره',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `records`
--

LOCK TABLES `records` WRITE;
/*!40000 ALTER TABLE `records` DISABLE KEYS */;
/*!40000 ALTER TABLE `records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_attachments`
--

DROP TABLE IF EXISTS `report_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `kind` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tg_file_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_attachments`
--

LOCK TABLES `report_attachments` WRITE;
/*!40000 ALTER TABLE `report_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `report_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_broadcast_recipients`
--

DROP TABLE IF EXISTS `report_broadcast_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_broadcast_recipients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `broadcast_id` int NOT NULL,
  `student_id` int NOT NULL,
  `message_id` int DEFAULT NULL,
  `outbox_id` int DEFAULT NULL,
  `status` enum('pending','sent','failed','blocked') COLLATE utf8mb4_persian_ci NOT NULL DEFAULT 'pending',
  `telegram_message_id` varchar(64) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `error` varchar(500) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_recipient_broadcast` (`broadcast_id`),
  KEY `idx_recipient_student` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_broadcast_recipients`
--

LOCK TABLES `report_broadcast_recipients` WRITE;
/*!40000 ALTER TABLE `report_broadcast_recipients` DISABLE KEYS */;
INSERT INTO `report_broadcast_recipients` VALUES (5,5,117,16,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 08:26:26','2026-10-16 08:26:26'),(6,6,117,17,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 08:30:21','2026-10-16 08:30:21'),(7,7,109,18,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(8,7,94,19,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(9,7,50,20,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(10,7,101,21,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(11,7,100,22,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(12,7,49,23,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(13,7,97,24,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(14,7,119,25,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(15,7,99,26,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(16,7,53,27,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(17,7,103,28,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(18,7,36,29,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(19,7,92,30,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(20,7,93,31,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(21,7,110,32,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(22,7,96,33,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41'),(23,7,102,34,NULL,'failed',NULL,'recipient_not_linked','2026-10-16 15:39:41','2026-10-16 15:39:41');
/*!40000 ALTER TABLE `report_broadcast_recipients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_broadcasts`
--

DROP TABLE IF EXISTS `report_broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_broadcasts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supporter_id` int NOT NULL,
  `audience` enum('no_report_today','all_students') COLLATE utf8mb4_persian_ci NOT NULL,
  `day` date NOT NULL,
  `body` text COLLATE utf8mb4_persian_ci,
  `attachment_count` int NOT NULL DEFAULT '0',
  `recipient_count` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_broadcast_supporter` (`supporter_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_broadcasts`
--

LOCK TABLES `report_broadcasts` WRITE;
/*!40000 ALTER TABLE `report_broadcasts` DISABLE KEYS */;
INSERT INTO `report_broadcasts` VALUES (5,2,'all_students','2026-10-16','سلام',0,1,'2026-10-16 08:26:26'),(6,2,'all_students','2026-10-16','سلام',0,1,'2026-10-16 08:30:21'),(7,3,'all_students','2026-10-16','سلام',0,17,'2026-10-16 15:39:41');
/*!40000 ALTER TABLE `report_broadcasts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_message_attachments`
--

DROP TABLE IF EXISTS `report_message_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_message_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message_id` int NOT NULL,
  `kind` enum('photo','document','voice','video','audio') COLLATE utf8mb4_persian_ci NOT NULL,
  `tg_file_id` varchar(255) COLLATE utf8mb4_persian_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `mime_type` varchar(127) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `file_size` bigint DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_att_message` (`message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_message_attachments`
--

LOCK TABLES `report_message_attachments` WRITE;
/*!40000 ALTER TABLE `report_message_attachments` DISABLE KEYS */;
INSERT INTO `report_message_attachments` VALUES (1,8,'photo','AgACAgQAAxkBAAIIpWrHFnfLJ6P9KuV0_kQdieX6wEmgAAKjD2sbMrg4UgogQDLAXCDHAQADAgADeAADPQQ',NULL,NULL,NULL,'2026-10-08 04:05:13');
/*!40000 ALTER TABLE `report_message_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_messages`
--

DROP TABLE IF EXISTS `report_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `thread_id` int NOT NULL,
  `student_id` int NOT NULL,
  `sender_role` enum('student','supporter','broadcast') COLLATE utf8mb4_persian_ci NOT NULL,
  `sender_account_id` int DEFAULT NULL,
  `body` text COLLATE utf8mb4_persian_ci,
  `media_group_id` varchar(64) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `is_broadcast` tinyint(1) NOT NULL DEFAULT '0',
  `read_by_student` tinyint(1) NOT NULL DEFAULT '0',
  `read_by_supporter` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_thread` (`thread_id`,`id`),
  KEY `idx_msg_student_supporter_read` (`student_id`,`read_by_supporter`),
  KEY `idx_msg_student_student_read` (`student_id`,`read_by_student`),
  KEY `idx_msg_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_messages`
--

LOCK TABLES `report_messages` WRITE;
/*!40000 ALTER TABLE `report_messages` DISABLE KEYS */;
INSERT INTO `report_messages` VALUES (1,1,117,'supporter',2,'سلام علی جان',NULL,0,1,1,'2026-10-05 02:59:26'),(2,1,117,'supporter',2,'سلام علی جان',NULL,0,1,1,'2026-10-05 03:01:36'),(4,3,117,'student',117,'سلام',NULL,0,1,1,'2026-10-07 17:39:03'),(5,3,117,'supporter',2,'اللهم صلی علی محمد و آل محمد و عجل فرجهم',NULL,0,1,1,'2026-10-07 17:40:22'),(6,3,117,'student',117,'حیوانات خانگی',NULL,0,1,1,'2026-10-07 17:41:16'),(7,3,117,'supporter',2,'سیستم صوتی',NULL,0,1,1,'2026-10-07 17:42:02'),(8,4,117,'student',117,NULL,NULL,0,1,1,'2026-10-08 04:05:13'),(9,4,117,'supporter',2,'به به',NULL,0,1,1,'2026-10-08 04:06:19'),(10,5,117,'supporter',2,'سلام علی جان',NULL,0,0,1,'2026-10-08 20:56:33'),(11,5,117,'supporter',2,'سلام خوبین',NULL,0,0,1,'2026-10-08 20:57:13'),(12,6,117,'supporter',2,'سلام',NULL,0,0,1,'2026-10-14 20:05:58'),(16,9,117,'broadcast',2,'سلام',NULL,1,0,1,'2026-10-16 08:26:26'),(17,9,117,'broadcast',2,'سلام',NULL,1,0,1,'2026-10-16 08:30:21'),(18,10,109,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(19,11,94,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(20,12,50,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(21,13,101,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(22,14,100,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(23,15,49,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(24,16,97,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(25,17,119,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(26,18,99,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(27,19,53,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(28,20,103,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(29,21,36,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(30,22,92,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(31,23,93,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(32,24,110,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(33,25,96,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(34,26,102,'broadcast',3,'سلام',NULL,1,0,1,'2026-10-16 15:39:41'),(35,27,117,'supporter',2,'سلام ناهید جون',NULL,0,0,1,'2026-10-21 16:07:54');
/*!40000 ALTER TABLE `report_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_replies`
--

DROP TABLE IF EXISTS `report_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_replies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `author_user_id` int NOT NULL,
  `reply_text` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_replies`
--

LOCK TABLES `report_replies` WRITE;
/*!40000 ALTER TABLE `report_replies` DISABLE KEYS */;
/*!40000 ALTER TABLE `report_replies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report_threads`
--

DROP TABLE IF EXISTS `report_threads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report_threads` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `day` date NOT NULL,
  `supporter_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_thread_student_day` (`student_id`,`day`),
  KEY `idx_thread_supporter` (`supporter_id`),
  KEY `idx_thread_day` (`day`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report_threads`
--

LOCK TABLES `report_threads` WRITE;
/*!40000 ALTER TABLE `report_threads` DISABLE KEYS */;
INSERT INTO `report_threads` VALUES (1,117,'2026-10-05',2,'2026-10-05 02:59:26','2026-10-05 02:59:26'),(3,117,'2026-10-07',2,'2026-10-07 17:39:03','2026-10-07 17:39:03'),(4,117,'2026-10-08',2,'2026-10-08 04:05:13','2026-10-08 04:05:13'),(5,117,'2026-10-09',2,'2026-10-08 20:56:33','2026-10-08 20:56:33'),(6,117,'2026-10-14',2,'2026-10-14 20:05:58','2026-10-14 20:05:58'),(9,117,'2026-10-16',2,'2026-10-16 08:26:26','2026-10-16 08:26:26'),(10,109,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(11,94,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(12,50,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(13,101,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(14,100,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(15,49,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(16,97,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(17,119,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(18,99,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(19,53,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(20,103,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(21,36,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(22,92,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(23,93,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(24,110,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(25,96,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(26,102,'2026-10-16',3,'2026-10-16 15:39:41','2026-10-16 15:39:41'),(27,117,'2026-10-21',2,'2026-10-21 16:07:54','2026-10-21 16:07:54');
/*!40000 ALTER TABLE `report_threads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reports`
--

DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_user_id` int NOT NULL,
  `report_date` date NOT NULL,
  `report_text` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','replied') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `assigned_staff_user_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reports`
--

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reports_status`
--

DROP TABLE IF EXISTS `reports_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reports_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `supporter_id` int DEFAULT NULL,
  `file_id` int DEFAULT NULL COMMENT 'لینک به فایل گزارش',
  `report_text` text COLLATE utf8mb4_unicode_ci COMMENT 'متن گزارش',
  `report_date` date NOT NULL,
  `status` enum('pending','replied') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `reply_text` text COLLATE utf8mb4_unicode_ci COMMENT 'متن پاسخ پشتیبان',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `replied_at` datetime DEFAULT NULL COMMENT 'زمان پاسخ',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reports_status`
--

LOCK TABLES `reports_status` WRITE;
/*!40000 ALTER TABLE `reports_status` DISABLE KEYS */;
INSERT INTO `reports_status` VALUES (1,2,NULL,1,'گزارش تصویری','2025-12-16','replied','آفرین خسته نباشی','2025-12-16 19:58:54','2025-12-16 19:59:11'),(2,2,NULL,3,'گزارش تصویری','2025-12-16','pending',NULL,'2025-12-16 22:55:25',NULL),(3,2,NULL,4,'گزارش تصویری','2025-12-16','pending',NULL,'2025-12-16 22:55:59',NULL),(4,2,NULL,5,'گزارش تصویری','2025-12-16','pending',NULL,'2025-12-16 22:55:59',NULL),(5,2,NULL,6,'گزارش تصویری','2025-12-16','pending',NULL,'2025-12-16 22:56:00',NULL),(6,2,NULL,7,'گزارش تصویری','2025-12-16','replied','آفرین عالی بود','2025-12-16 22:56:00','2025-12-16 22:56:28'),(7,2,NULL,NULL,'Miu','2025-12-19','replied','hi','2025-12-19 13:38:48','2025-12-19 15:04:16'),(8,2,NULL,8,'گزارش تصویری','2025-12-20','pending',NULL,'2025-12-20 21:58:22',NULL),(9,6,NULL,9,'گزارش تصویری','2025-12-22','replied','آفرین','2025-12-22 19:53:23','2025-12-22 19:53:44'),(10,6,NULL,NULL,'مرسی','2025-12-22','pending',NULL,'2025-12-22 19:54:01',NULL),(11,6,2,10,'گزارش تصویری','2025-12-27','pending',NULL,'2025-12-27 17:16:44',NULL),(12,6,2,11,'گزارش تصویری','2025-12-27','replied','آفرین','2025-12-27 17:36:38','2025-12-27 17:42:42'),(13,6,2,NULL,'مرسی','2025-12-27','replied','❤️❤️','2025-12-27 17:42:54','2025-12-27 17:43:06'),(14,6,2,NULL,'📝 ثبت گزارش','2026-01-01','pending',NULL,'2026-01-01 19:29:46',NULL),(15,6,2,NULL,'📊 عملکرد من','2026-01-01','pending',NULL,'2026-01-01 19:29:51',NULL),(16,6,2,NULL,'📝 ثبت گزارش','2026-01-01','pending',NULL,'2026-01-01 19:31:21',NULL),(17,6,2,NULL,'سلام','2026-01-01','replied','چیشد','2026-01-01 19:31:44','2026-01-01 19:32:38'),(18,6,2,14,'گزارش تصویری','2026-01-08','replied','مرسی','2026-01-08 15:46:30','2026-01-08 15:46:49');
/*!40000 ALTER TABLE `reports_status` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`%`*/ /*!50003 TRIGGER `calc_response_time` BEFORE UPDATE ON `reports_status` FOR EACH ROW BEGIN
    IF NEW.status = 'replied' AND OLD.status = 'pending' THEN
        SET NEW.replied_at = NOW();
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','مدیر','2026-01-06 19:28:56'),(2,'student','دانش‌آموز','2026-01-06 19:28:56'),(3,'supporter','پشتیبان','2026-01-06 19:28:56'),(4,'teacher','معلم','2026-01-06 19:28:56');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shelves`
--

DROP TABLE IF EXISTS `shelves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shelves` (
  `id` int NOT NULL AUTO_INCREMENT,
  `shelf_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '#3b82f6',
  `duration` int DEFAULT NULL COMMENT 'مدت زمان آزمون (دقیقه)',
  `topic` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exam_date` date DEFAULT NULL,
  `grade` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lesson_distribution` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `shelves_chk_1` CHECK (json_valid(`lesson_distribution`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shelves`
--

LOCK TABLES `shelves` WRITE;
/*!40000 ALTER TABLE `shelves` DISABLE KEYS */;
/*!40000 ALTER TABLE `shelves` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_staff_assignments`
--

DROP TABLE IF EXISTS `student_staff_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_staff_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_user_id` int NOT NULL,
  `staff_user_id` int NOT NULL,
  `relation` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'supporter',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_staff_assignments`
--

LOCK TABLES `student_staff_assignments` WRITE;
/*!40000 ALTER TABLE `student_staff_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_staff_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_supporter_assignments`
--

DROP TABLE IF EXISTS `student_supporter_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `student_supporter_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `supporter_id` int NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `assigned_by` int DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deactivated_at` datetime DEFAULT NULL,
  `active_student_id` int GENERATED ALWAYS AS ((case when (`is_active` = 1) then `student_id` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_active_student` (`active_student_id`),
  KEY `idx_ssa_supporter_active` (`supporter_id`,`is_active`),
  KEY `idx_ssa_student_history` (`student_id`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_supporter_assignments`
--

LOCK TABLES `student_supporter_assignments` WRITE;
/*!40000 ALTER TABLE `student_supporter_assignments` DISABLE KEYS */;
INSERT INTO `student_supporter_assignments` (`id`, `student_id`, `supporter_id`, `is_active`, `assigned_by`, `note`, `assigned_at`, `deactivated_at`) VALUES (1,36,3,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(2,49,3,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(3,53,3,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(4,102,3,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(5,103,3,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(6,110,3,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(7,117,2,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(8,119,3,1,NULL,'auto-assigned by field/grade','2026-10-16 12:51:06',NULL),(16,50,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(17,92,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(18,93,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(19,94,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(20,96,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(21,97,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(22,99,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(23,100,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(24,101,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL),(25,109,3,1,NULL,'auto-assigned by field','2026-10-16 12:52:17',NULL);
/*!40000 ALTER TABLE `student_supporter_assignments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_persian_ci NOT NULL,
  `grade` int NOT NULL COMMENT 'پایه تحصیلی',
  `field` varchar(50) COLLATE utf8mb4_persian_ci DEFAULT NULL COMMENT 'رشته: ریاضی، تجربی، انسانی، راهنمایی',
  `chat_id` bigint DEFAULT NULL COMMENT 'شناسه تلگرام',
  `phone` varchar(15) COLLATE utf8mb4_persian_ci DEFAULT NULL COMMENT 'شماره موبایل',
  `national_id` varchar(10) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `supporter_id` varchar(256) COLLATE utf8mb4_persian_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_students_supporter` (`supporter_id`)
) ENGINE=InnoDB AUTO_INCREMENT=135 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (19,'سبحان عیسی زاده',12,'ریاضی',NULL,'09118233225','2051530599',0,'2026-03-13 08:10:59','2'),(20,'ابوالفضل کبودتبار',12,'ریاضی',NULL,'09101240664','2147483647',1,'2026-03-13 08:12:04','2'),(21,'امیرحسین شکرالله نژاد',12,'ریاضی',NULL,'09369084951','2147483641',0,'2026-03-13 09:03:30','2'),(22,'امیرعلی شکرالله زاده',12,'ریاضی',NULL,'09021619786','2051495564',0,'2026-03-13 09:04:26','2'),(23,'مهدی سلطان زاده',12,'ریاضی',NULL,'09114254803','2051483353',0,'2026-03-13 09:05:23','2'),(24,'هادی حسنزاده',12,'ریاضی',NULL,'09113167588','2147483647',0,'2026-03-13 09:06:11','2'),(25,'محدثه محمدی',12,'تجربی',NULL,'09117341335','2147483647',0,'2026-03-13 10:26:31','3'),(26,'فاطمه سادات محسن نیا',12,'ریاضی',NULL,'09044834911','2147483647',0,'2026-03-13 13:38:55','2'),(27,'فاطمه زهرا طاهری اطاقسرا',12,'تجربی',NULL,'09019571254','2051505871',0,'2026-03-15 18:07:20','3'),(28,'امیرمحمد لاغری',12,'تجربی',NULL,'09118647493','2147483647',0,'2026-03-15 18:08:38','3'),(29,'حسنا نازکتبار',10,'تجربی',NULL,'09118998269','2147483647',0,'2026-03-15 18:09:52','3'),(30,'مهلا کاظم پور',12,'انسانی',NULL,'09361128487','2147483647',0,'2026-03-15 18:11:06',NULL),(32,'سینا حسین نژاد',12,'ریاضی',NULL,'09016786401','111096030',0,'2026-03-15 18:14:25','2'),(33,'مجتبی آبسته',12,'انسانی',NULL,'09113116589','2051501378',0,'2026-03-15 19:48:20',NULL),(34,'پرهام قلی پور',9,'راهنمایی',NULL,'09916811860','2147483647',0,'2026-03-15 19:49:11',NULL),(35,'مریم آخوندی',12,'تجربی',NULL,'09336590718','2051525080',0,'2026-03-15 19:49:54','3'),(36,'مبینا داداش نژاد',12,'تجربی',NULL,'09056548837','2147483647',1,'2026-03-15 19:50:35','3'),(37,'کوثر علیجانی',12,'تجربی',NULL,'09039052498','2051443017',0,'2026-03-15 19:52:00','3'),(38,'فاطمه صفری',10,'ریاضی',NULL,'09128904677','152386084',0,'2026-03-15 19:52:50','2'),(39,'عطیه رمضان نژاد',12,'انسانی',NULL,'09926270166','2051530785',0,'2026-03-15 19:53:49',NULL),(40,'غزل حسینی',12,'انسانی',NULL,'09375036633','2051524378',0,'2026-03-15 19:54:31',NULL),(41,'زهرا ابراهیمی',12,'تجربی',NULL,'09010115064','2147483647',0,'2026-03-15 19:56:06','3'),(42,'ستایش ذکری',11,'تجربی',NULL,'09382563339','2051559317',0,'2026-03-15 19:56:45','3'),(43,'هستی طالبی',12,'ریاضی',NULL,'09386283528','2051540403',0,'2026-03-15 19:57:46','2'),(45,'شیدا داداشی',12,'تجربی',NULL,'09941341103','2051444064',0,'2026-03-15 19:59:52','3'),(46,'یگانه اصغر تبار',12,'انسانی',NULL,'09011178140','2147483645',0,'2026-03-15 20:01:22',NULL),(47,'مهزاد ساعدی',12,'انسانی',NULL,'09940082669','2051487200',0,'2026-03-15 20:02:25',NULL),(48,'سما سیف الله زاده',12,'راهنمایی',NULL,'09376142772','2051400032',0,'2026-03-15 20:03:50',NULL),(49,'حدیث متولی',12,'تجربی',NULL,'09929110293','2147483647',1,'2026-03-15 20:04:39','3'),(52,'فاطره پورحبیب',12,'انسانی',NULL,'09304307472','2051571287',1,'2026-03-15 20:12:25',NULL),(53,'فاطمه پورحبیب',12,'تجربی',NULL,'09357555756','2051571295',1,'2026-03-15 20:13:16','3'),(54,'مونا جام گوهری',10,'انسانی',NULL,'09118137417','2051646376',0,'2026-03-15 20:22:22',NULL),(55,'مروا روشن ضمیر',10,'تجربی',NULL,'09928101293','2051618259',0,'2026-03-15 20:23:12','3'),(56,'دینا پورصادق',8,'راهنمایی',NULL,'09304249097','2051766215',0,'2026-03-15 20:25:29',NULL),(57,'فاطمه گلچوب',12,'تجربی',NULL,'09397910166','2147483647',0,'2026-03-15 20:26:12','3'),(58,'امیرحسین عباسپور',11,'تجربی',NULL,'09046592464','2051598290',0,'2026-03-15 20:27:02','3'),(59,'فاطره اکبرپور',12,'انسانی',NULL,'09914397043','2051538611',0,'2026-03-15 20:27:44',NULL),(60,'فاطمه غلامی',12,'انسانی',NULL,'09011198634','2147483647',0,'2026-03-15 20:28:26',NULL),(61,'تارا رحمانی',7,'راهنمایی',NULL,'09112188415','1234567891',0,'2026-03-15 20:29:23',NULL),(62,'سینا عزیزپور',12,'تجربی',NULL,'09332949227','2147483647',0,'2026-03-15 20:30:13','3'),(63,'کوثر طالبی',12,'انسانی',NULL,'09906788091','2051501114',0,'2026-03-15 20:30:54',NULL),(64,'دینا عابدی',12,'ریاضی',NULL,'09937353086','1111111111',0,'2026-03-15 20:32:30','2'),(65,'ترنم تیموری',10,'ریاضی',NULL,'09390196629','2051670013',0,'2026-03-15 20:34:14','2'),(66,'هستی شجاعی',10,'ریاضی',NULL,'09014475266','2051644195',0,'2026-03-15 20:35:13','2'),(68,'فاطمه زهرا تجلی',12,'تجربی',NULL,'09380491047','2051485560',0,'2026-03-19 08:41:36','3'),(69,'فاطمه علیزاده',12,'تجربی',NULL,'09118635390','2051533441',0,'2026-03-19 08:45:23','3'),(70,'نازنین زهرا گتابی',9,'راهنمایی',NULL,'09056425736','2051714010',0,'2026-03-19 08:46:04',NULL),(71,'هستی داغمه چی',11,'تجربی',NULL,'09929968032','2147483647',0,'2026-03-19 08:46:57','3'),(72,'مارال طاهری',12,'تجربی',NULL,'09938480208','2051528594',0,'2026-03-19 08:47:46','3'),(73,'تارا محمدی',8,'راهنمایی',NULL,'09391754184','2051699186',0,'2026-03-19 08:48:37',NULL),(74,'مصطفی حبیبی',12,'تجربی',NULL,'09112180388','2051517460',0,'2026-03-19 08:49:39','3'),(75,'محمد سجاد ولی پور',7,'راهنمایی',NULL,'09113114465','2051893586',0,'2026-03-19 08:50:36',NULL),(76,'الینا تجلی',12,'تجربی',NULL,'09111111111','2147483647',0,'2026-03-19 13:31:34','3'),(77,'فاطمه رضوانی',12,'انسانی',NULL,'09031816120','2147483647',0,'2026-03-27 11:51:57',NULL),(78,'زهرا براری',12,'ریاضی',NULL,'09031816122','111096039',0,'2026-03-27 13:24:31','2'),(79,'محمد امین مصطفی نژاد',12,'تجربی',NULL,'09101240663','2051501373',0,'2026-03-27 13:44:41','3'),(80,'علی مهرعلی تبار',12,'تجربی',NULL,'09929094401','2147483647',0,'2026-03-27 13:52:21','3'),(83,'فاطمه گریزاد',12,'تجربی',NULL,'09222222222','333333333',0,'2026-04-10 12:04:30','3'),(92,'محمدحسین رضوانی',11,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:50:23','3'),(93,'مروا روشن ضمیر',11,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:51:14','3'),(94,'امیراحمد طاهری',10,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:52:21','3'),(96,'نازنین نوروز نژاد',11,'تجربی',NULL,'09334469055','4980571212',1,'2026-10-02 10:52:43','3'),(97,'زهرا خانبابایی',11,'تجربی',NULL,'09117762049','2051665052',1,'2026-10-02 10:53:17','3'),(99,'علیرضا گرجی نژاد',11,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:53:39','3'),(100,'پویان حبیب پور',11,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:54:01','3'),(101,'باران علیپور',11,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:54:16','3'),(102,'یکتا مصلح',12,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:54:53','3'),(103,'کیمیا درزی',12,'تجربی',NULL,'09000000000','0',1,'2026-10-02 10:58:50','3'),(104,'هانا پوراصغر',11,'انسانی',NULL,'09000000000','0',1,'2026-10-02 11:00:12',NULL),(105,'غزل نجف پور',12,'انسانی',NULL,'09365421339','4980520261',1,'2026-10-02 11:00:34',NULL),(106,'پوریا حسنجانی',12,'انسانی',NULL,'09306791133','2051548234',1,'2026-10-02 11:00:58',NULL),(107,'ملینا رنجبری',12,'انسانی',NULL,'09057551735','0201531089',1,'2026-10-02 11:01:21',NULL),(108,'فاطمه زهرا تاج الدین',12,'انسانی',NULL,'09000000000','0',1,'2026-10-02 11:01:48',NULL),(109,'امید رامش',11,'تجربی',NULL,'09000000000','0',1,'2026-10-02 11:02:05','3'),(110,'مریم اسماعیل پور',12,'تجربی',NULL,'09000000000','0',1,'2026-10-02 12:16:49','3'),(119,'سونیا لطف الله زاده',12,'تجربی',NULL,'09000000000','2051234567',1,'2026-10-16 10:53:29','3'),(120,'Mahdiyeh Asgharntaj',12,'انسانی',NULL,'09011082577','4980531581',1,'2026-10-18 14:04:15',NULL),(121,'کارن رودباریان',7,'راهنمایی',NULL,'09113908439','2051883211',1,'2026-10-19 10:32:10',NULL),(123,'امیرعلی شعبان عمرانی',12,'انسانی',NULL,'09046835961','2051605874',1,'2026-10-22 15:06:17',NULL),(124,'ثنا کریمیان',12,'انسانی',NULL,'09334489821','2150943581',1,'2026-10-22 16:14:49',NULL),(125,'نیایش گوهری',11,'انسانی',NULL,'09028025548','7110029085',1,'2026-10-22 16:15:51',NULL),(126,'محمدسجاد محمدی',9,'راهنمایی',NULL,'09119605507','7110039277',1,'2026-10-22 16:16:42',NULL),(127,'ثنا معصوم نیا',9,'راهنمایی',NULL,'09929768618','2051777373',1,'2026-10-22 17:44:11',NULL),(128,'دینا پورصادق',9,'راهنمایی',NULL,'09015086112','2051766215',1,'2026-10-22 17:55:27',NULL),(129,'محمدرضا داداش زاده',12,'تجربی',NULL,'09000000000','0000000000',1,'2026-10-23 10:07:25','0'),(130,'فاطمه گریزاد',12,'تجربی',NULL,'09025212497','7110015114',1,'2026-10-23 10:54:03','0'),(131,'علی اکبر علیجانی',10,'تجربی',NULL,'09366154103','7110034763',1,'2026-10-23 11:23:59',NULL),(132,'فاطمه زهرا حیدری',12,'تجربی',NULL,'09118032947','0000000000',1,'2026-10-23 19:47:39','0'),(133,'ضحا پورصادق',10,'تجربی',NULL,'09000000000','0000000000',1,'2026-10-23 20:49:59',NULL),(134,'امیررضا موسوی',12,'تجربی',NULL,'09119953665','2051497621',0,'2026-10-23 21:02:59','0');
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supporter_scopes`
--

DROP TABLE IF EXISTS `supporter_scopes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supporter_scopes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supporter_id` int NOT NULL,
  `field` varchar(50) COLLATE utf8mb4_persian_ci NOT NULL,
  `grade` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supporter_scope` (`supporter_id`,`field`,`grade`),
  KEY `idx_scope_field_grade` (`field`,`grade`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supporter_scopes`
--

LOCK TABLES `supporter_scopes` WRITE;
/*!40000 ALTER TABLE `supporter_scopes` DISABLE KEYS */;
INSERT INTO `supporter_scopes` VALUES (2,0,'تجربی',12,'2026-10-03 16:36:55'),(7,2,'ریاضی',12,'2026-10-27 23:06:25'),(8,3,'تجربی',12,'2026-10-28 01:04:53');
/*!40000 ALTER TABLE `supporter_scopes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supporter_stats`
--

DROP TABLE IF EXISTS `supporter_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supporter_stats` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grade` int DEFAULT NULL,
  `field` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `total_reports` bigint DEFAULT NULL,
  `replied_count` bigint DEFAULT NULL,
  `pending_count` bigint DEFAULT NULL,
  `avg_response_hours` decimal(24,4) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supporter_stats`
--

LOCK TABLES `supporter_stats` WRITE;
/*!40000 ALTER TABLE `supporter_stats` DISABLE KEYS */;
/*!40000 ALTER TABLE `supporter_stats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supporters`
--

DROP TABLE IF EXISTS `supporters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supporters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grade` int NOT NULL COMMENT 'پایه‌ای که پشتیبانی می‌کند',
  `field` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'رشته یا درس',
  `phone` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `chat_id` bigint DEFAULT NULL COMMENT 'شناسه تلگرام',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `admin_id` int DEFAULT NULL COMMENT 'مدیری که این پشتیبان تحت نظر اوست',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_supporters_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supporters`
--

LOCK TABLES `supporters` WRITE;
/*!40000 ALTER TABLE `supporters` DISABLE KEYS */;
INSERT INTO `supporters` VALUES (2,'مهندس محمدحسین داودی',12,'ریاضی',NULL,7958508995,'supporters/2/6ae0fd38b645b3.96909346.webp',1,NULL,'2025-12-27 17:05:05'),(3,'دکتر امیررضا موسوی',12,'تجربی',NULL,897836656,'supporters/3/6ae0fc7112b982.32908233.webp',1,NULL,'2026-09-25 17:29:46');
/*!40000 ALTER TABLE `supporters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `telegram_2fa_challenges`
--

DROP TABLE IF EXISTS `telegram_2fa_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `telegram_2fa_challenges` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `challenge_nonce` varchar(64) COLLATE utf8mb4_persian_ci NOT NULL,
  `ticket_jti` varchar(64) COLLATE utf8mb4_persian_ci NOT NULL,
  `user_id` int NOT NULL,
  `attempts` int NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_challenge_nonce` (`challenge_nonce`),
  KEY `idx_ticket_jti` (`ticket_jti`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `telegram_2fa_challenges`
--

LOCK TABLES `telegram_2fa_challenges` WRITE;
/*!40000 ALTER TABLE `telegram_2fa_challenges` DISABLE KEYS */;
INSERT INTO `telegram_2fa_challenges` VALUES (1,'44f57cc93f7feadd8ea11005fdfffb38','8c8ff94bf63cf416f068c31b5c653106',6,0,'2026-10-03 09:41:17',NULL,'2026-10-03 13:06:17'),(2,'02a18e02411aad48b2100ff6900708c0','8c8ff94bf63cf416f068c31b5c653106',6,0,'2026-10-03 09:47:48',NULL,'2026-10-03 13:12:48'),(3,'da5d7edba1c7a83dc7a748316f1f2f0d','19b86d4fc964ad56ff7b74ad7f9cb1da',6,0,'2026-10-03 12:35:12',NULL,'2026-10-03 16:00:12'),(4,'88f7daff25771f18486e102c551bb0d2','19b86d4fc964ad56ff7b74ad7f9cb1da',6,0,'2026-10-03 12:44:38',NULL,'2026-10-03 16:09:38'),(5,'e91e4ed673387cbee64b92b57eb09ab2','90173005e1d76ce1364dec5bc7d7efff',6,0,'2026-10-03 12:46:06','2026-10-03 12:41:26','2026-10-03 16:11:06'),(6,'5ede448ec3609864fd34c71f08d07280','b611220076ec5dcd691268867c5b9b8d',6,0,'2026-10-03 12:57:34',NULL,'2026-10-03 16:22:34'),(7,'03ca29a493a2bd9bd4a83691030c6be4','34e484ee39df5c11e02a594af624f27f',6,0,'2026-10-03 17:26:11','2026-10-03 17:21:27','2026-10-03 20:51:11');
/*!40000 ALTER TABLE `telegram_2fa_challenges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `telegram_auth_nonces`
--

DROP TABLE IF EXISTS `telegram_auth_nonces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `telegram_auth_nonces` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `kind` enum('payload','ticket') COLLATE utf8mb4_persian_ci NOT NULL,
  `nonce` varchar(64) COLLATE utf8mb4_persian_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kind_nonce` (`kind`,`nonce`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `telegram_auth_nonces`
--

LOCK TABLES `telegram_auth_nonces` WRITE;
/*!40000 ALTER TABLE `telegram_auth_nonces` DISABLE KEYS */;
INSERT INTO `telegram_auth_nonces` VALUES (26,'payload','0cdc52713c8e9b7125aafb31518385660ae8464f2d5bdab64e71bd958969d9e9','2026-10-03 08:31:50',NULL,'2026-10-03 11:56:50'),(27,'ticket','d3fa605dd23d404faaae9b053840c36e','2026-10-03 08:36:50',NULL,'2026-10-03 11:56:50'),(28,'payload','308acb5e9ba98428bcfb510d60f1eed5442ef198ed8fd15bb6145b414cb7bae9','2026-10-03 09:41:08',NULL,'2026-10-03 13:06:08'),(29,'ticket','8c8ff94bf63cf416f068c31b5c653106','2026-10-03 09:46:09',NULL,'2026-10-03 13:06:09'),(30,'payload','5ec46ddbdd79aa6d072ed3b5cf56d32d58f3b14eb11374f4c7c07b000a08e972','2026-10-03 12:34:47',NULL,'2026-10-03 15:59:47'),(31,'ticket','f8c4424e6814168c19e4794daead99a5','2026-10-03 12:39:47',NULL,'2026-10-03 15:59:47'),(32,'payload','b6c0ecf3942816e0388a86a38c96b853c085074f77532848b8f215067ec913b1','2026-10-03 12:34:48',NULL,'2026-10-03 15:59:48'),(33,'ticket','19b86d4fc964ad56ff7b74ad7f9cb1da','2026-10-03 12:39:48',NULL,'2026-10-03 15:59:48'),(34,'payload','f71a320788cf040f0ca5528478ca1f81d516db0fd25e45db4080fb6b539a276c','2026-10-03 12:46:00',NULL,'2026-10-03 16:11:00'),(35,'ticket','6a9738502190d14ec9ba2d7a8bdb2d7d','2026-10-03 12:51:00',NULL,'2026-10-03 16:11:00'),(36,'payload','feb7e06af84fb072fc60e596e86e17f9a86ca848dd8cf802b01d6e4a03ee6792','2026-10-03 12:46:00',NULL,'2026-10-03 16:11:00'),(37,'ticket','90173005e1d76ce1364dec5bc7d7efff','2026-10-03 12:51:00','2026-10-03 12:41:26','2026-10-03 16:11:00'),(38,'payload','4a178a590d977badfdd2130a7bff3d2cdc535056bbe12d0fe8d503db343efb5e','2026-10-03 12:57:21',NULL,'2026-10-03 16:22:21'),(39,'ticket','3fc2eae104e7cd42dee44abdc495049a','2026-10-03 13:02:21',NULL,'2026-10-03 16:22:21'),(40,'payload','61d0298d4e5d6029d43c1d20f72eb8b26d3c296c93434dd9f9f69920287a97bb','2026-10-03 12:57:23',NULL,'2026-10-03 16:22:23'),(41,'ticket','b611220076ec5dcd691268867c5b9b8d','2026-10-03 13:02:23',NULL,'2026-10-03 16:22:23'),(42,'payload','d8447a38fa65ebfd9283025819305a44681e4956c9f53b1dbc24f3b1b7a9571d','2026-10-03 13:12:51',NULL,'2026-10-03 16:37:51'),(43,'ticket','834fa57a66a094b4b1437a05dc615408','2026-10-03 13:17:51',NULL,'2026-10-03 16:37:51'),(44,'payload','9886c2d45fc0033e898936d86cc2563d1821be71b9f0de06d55ffe398763c240','2026-10-03 13:35:29',NULL,'2026-10-03 17:00:29'),(45,'ticket','d279f4eb5d6b5d1c81ed67ad0a3a5ba0','2026-10-03 13:40:29',NULL,'2026-10-03 17:00:29'),(46,'payload','804f82e8517bec0110adf843fb27fc6074693f3ad4ba061ee795fe90cdfdcdb0','2026-10-03 17:26:01',NULL,'2026-10-03 20:51:01'),(47,'ticket','b17cfc2740d6c478d2289ccef1c5b83a','2026-10-03 17:31:01',NULL,'2026-10-03 20:51:01'),(48,'payload','4d57919156a7c66e6935124112e4f765d037804d7a97ad69a434ef8082b16a50','2026-10-03 17:26:01',NULL,'2026-10-03 20:51:01'),(49,'ticket','34e484ee39df5c11e02a594af624f27f','2026-10-03 17:31:01','2026-10-03 17:21:27','2026-10-03 20:51:01');
/*!40000 ALTER TABLE `telegram_auth_nonces` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `telegram_links`
--

DROP TABLE IF EXISTS `telegram_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `telegram_links` (
  `id` int NOT NULL AUTO_INCREMENT,
  `telegram_user_id` bigint NOT NULL,
  `chat_id` bigint NOT NULL,
  `role` enum('student','supporter','admin') COLLATE utf8mb4_persian_ci NOT NULL,
  `account_id` int NOT NULL,
  `is_blocked` tinyint(1) NOT NULL DEFAULT '0',
  `linked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_telegram_role` (`telegram_user_id`,`role`),
  UNIQUE KEY `uk_role_account` (`role`,`account_id`),
  KEY `idx_telegram_chat` (`chat_id`),
  KEY `idx_telegram_blocked` (`role`,`is_blocked`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `telegram_links`
--

LOCK TABLES `telegram_links` WRITE;
/*!40000 ALTER TABLE `telegram_links` DISABLE KEYS */;
/*!40000 ALTER TABLE `telegram_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tutoring_classrooms`
--

DROP TABLE IF EXISTS `tutoring_classrooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tutoring_classrooms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `instructor_id` int NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classroom_instructor` (`instructor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tutoring_classrooms`
--

LOCK TABLES `tutoring_classrooms` WRITE;
/*!40000 ALTER TABLE `tutoring_classrooms` DISABLE KEYS */;
/*!40000 ALTER TABLE `tutoring_classrooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tutoring_sessions`
--

DROP TABLE IF EXISTS `tutoring_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tutoring_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_date` date NOT NULL,
  `classroom_id` int NOT NULL,
  `student_id` int NOT NULL,
  `attendance_id` bigint unsigned DEFAULT NULL,
  `entered_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `client_entered_at` datetime DEFAULT NULL,
  `entry_source` enum('auto','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `client_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `open_student_key` int GENERATED ALWAYS AS (if((`ended_at` is null),`student_id`,NULL)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sess_client_uuid` (`client_uuid`),
  UNIQUE KEY `uq_sess_one_open_per_student` (`open_student_key`),
  KEY `idx_sess_day_class` (`session_date`,`classroom_id`),
  KEY `idx_sess_class_open` (`classroom_id`,`ended_at`),
  KEY `fk_sess_student` (`student_id`),
  KEY `fk_sess_attendance` (`attendance_id`),
  KEY `fk_sess_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tutoring_sessions`
--

LOCK TABLES `tutoring_sessions` WRITE;
/*!40000 ALTER TABLE `tutoring_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `tutoring_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tutoring_teacher_status_log`
--

DROP TABLE IF EXISTS `tutoring_teacher_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tutoring_teacher_status_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instructor_id` int NOT NULL,
  `status` enum('absent','ready','break') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_date` date NOT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `client_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_status_client_uuid` (`client_uuid`),
  KEY `idx_status_teacher_day` (`instructor_id`,`status_date`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tutoring_teacher_status_log`
--

LOCK TABLES `tutoring_teacher_status_log` WRITE;
/*!40000 ALTER TABLE `tutoring_teacher_status_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `tutoring_teacher_status_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','supporter','student','teacher') COLLATE utf8mb4_unicode_ci NOT NULL,
  `linked_id` int DEFAULT NULL COMMENT 'شناسه در جدول مربوطه (student_id یا supporter_id)',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `last_login` datetime DEFAULT NULL,
  `chat_id` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supporter_id` int DEFAULT NULL,
  UNIQUE KEY `chat_id` (`chat_id`),
  KEY `idx_users_id` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=197 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (95,'محمدحسین داودی','09904670738',NULL,'$2a$14$6i/JMC/vh7eFGPTW/T2slOlaLVDZlAQg7fqYnG9xb2/tUVG1MyAx.','supporter',2,'2026-10-05 06:22:47',NULL,'7958508995',NULL),(161,'محمدحسین داودی','09965124781',NULL,'$2a$14$6i/JMC/vh7eFGPTW/T2slOlaLVDZlAQg7fqYnG9xb2/tUVG1MyAx.','admin',117,'2026-10-05 06:28:12',NULL,'7943517873',NULL),(162,'امیررضا موسوی','09119953665',NULL,'$2y$10$halIKkLTzJH6XcefMSY8ze7TeFZFhpmezz3TJ7fwSxpH3Gvhe4yVu','supporter',3,'2026-10-15 13:43:35',NULL,'897836656',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `weekly_plans`
--

DROP TABLE IF EXISTS `weekly_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `weekly_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `student_id` int NOT NULL,
  `week_date` date NOT NULL,
  `weekly_notes` text COLLATE utf8mb4_persian_ci,
  `times_json` longtext COLLATE utf8mb4_persian_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_week` (`student_id`,`week_date`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `weekly_plans`
--

LOCK TABLES `weekly_plans` WRITE;
/*!40000 ALTER TABLE `weekly_plans` DISABLE KEYS */;
INSERT INTO `weekly_plans` VALUES (3,50,'2026-09-28','','[{\"start\":\"6:00\",\"end\":\"7:00\"},{\"start\":\"7:00\",\"end\":\"8:00\"},{\"start\":\"8:00\",\"end\":\"9:00\"},{\"start\":\"9:00\",\"end\":\"10:00\"},{\"start\":\"10:00\",\"end\":\"11:00\"},{\"start\":\"11:00\",\"end\":\"12:00\"},{\"start\":\"12:00\",\"end\":\"13:00\"},{\"start\":\"13:00\",\"end\":\"14:00\"},{\"start\":\"14:00\",\"end\":\"15:00\"},{\"start\":\"15:00\",\"end\":\"16:00\"},{\"start\":\"16:00\",\"end\":\"17:00\"},{\"start\":\"19:00\",\"end\":\"20:00\"},{\"start\":\"20:00\",\"end\":\"21:00\"},{\"start\":\"22:00\",\"end\":\"23:00\"}]','2026-10-16 10:12:58','2026-10-16 12:08:40'),(4,109,'2026-10-12','','[{\"start\":\"6:00\",\"end\":\"7:00\"},{\"start\":\"7:00\",\"end\":\"8:00\"},{\"start\":\"8:00\",\"end\":\"9:00\"},{\"start\":\"9:00\",\"end\":\"10:00\"},{\"start\":\"10:00\",\"end\":\"11:00\"},{\"start\":\"11:00\",\"end\":\"12:00\"},{\"start\":\"12:00\",\"end\":\"13:00\"},{\"start\":\"13:00\",\"end\":\"14:00\"},{\"start\":\"14:00\",\"end\":\"15:00\"},{\"start\":\"15:00\",\"end\":\"16:00\"},{\"start\":\"16:00\",\"end\":\"17:00\"},{\"start\":\"19:00\",\"end\":\"20:00\"},{\"start\":\"20:00\",\"end\":\"21:00\"},{\"start\":\"22:00\",\"end\":\"23:00\"}]','2026-10-16 12:01:02','2026-10-16 12:01:02'),(5,105,'2026-10-22','','[{\"start\":\"8:15\",\"end\":\"9:15\"},{\"start\":\"9:30\",\"end\":\"10:30\"},{\"start\":\"10:45\",\"end\":\"11:45\"},{\"start\":\"12:00\",\"end\":\"1:00\"},{\"start\":\"3:15\",\"end\":\"4:15\"},{\"start\":\"4:30\",\"end\":\"5:30\"},{\"start\":\"5:45\",\"end\":\"6:45\"},{\"start\":\"7:00\",\"end\":\"8:00\"},{\"start\":\"8:30\",\"end\":\"9:30\"}]','2026-10-22 12:10:21','2026-10-22 12:10:21');
/*!40000 ALTER TABLE `weekly_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'famo'
--

--
-- Dumping routines for database 'famo'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-29  9:58:31

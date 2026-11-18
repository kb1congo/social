-- MySQL dump 10.13  Distrib 8.4.13, for Linux (x86_64)
--
-- Host: localhost    Database: whatsapp_gateway
-- ------------------------------------------------------
-- Server version	8.4.13

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
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` varchar(80) DEFAULT NULL,
  `details` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contacts`
--

DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `whatsapp_account_id` bigint unsigned NOT NULL,
  `whatsapp_id` varchar(80) NOT NULL,
  `display_name` varchar(190) DEFAULT NULL,
  `phone_number` varchar(32) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contacts_account_whatsapp_id` (`whatsapp_account_id`,`whatsapp_id`),
  KEY `idx_contacts_whatsapp_account` (`whatsapp_account_id`),
  CONSTRAINT `fk_contacts_whatsapp_account` FOREIGN KEY (`whatsapp_account_id`) REFERENCES `whatsapp_accounts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contacts`
--

LOCK TABLES `contacts` WRITE;
/*!40000 ALTER TABLE `contacts` DISABLE KEYS */;
INSERT INTO `contacts` VALUES (1,1,'120363415082875625@g.us','PDDM Communication',NULL,'2026-11-14 13:59:16','2026-11-14 14:22:17'),(8,1,'242061900299@s.whatsapp.net',NULL,'242061900299','2026-11-14 14:10:00','2026-11-14 14:10:00'),(11,1,'status@broadcast','Habib-Merveille BOUKOUYA-',NULL,'2026-11-14 14:10:30','2026-11-14 14:10:30'),(33,1,'120363372117548888@g.us','fatima',NULL,'2026-11-14 14:17:45','2026-11-14 14:17:45'),(49,1,'124012919292050@lid','💻🖥️⌨️',NULL,'2026-11-14 14:32:41','2026-11-14 14:32:41'),(50,1,'242057040684@s.whatsapp.net',NULL,'242057040684','2026-11-14 14:36:14','2026-11-14 14:36:14'),(55,1,'24174899277-1586903256@g.us','Emery EN',NULL,'2026-11-14 14:56:26','2026-11-14 15:09:51'),(56,1,'124012919292050@s.whatsapp.net',NULL,'124012919292050','2026-11-14 15:09:50','2026-11-14 15:09:50');
/*!40000 ALTER TABLE `contacts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conversations`
--

DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conversations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint unsigned NOT NULL,
  `assigned_user_id` bigint unsigned DEFAULT NULL,
  `status` enum('open','pending','closed') NOT NULL DEFAULT 'open',
  `last_message_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conversations_assigned_user` (`assigned_user_id`),
  KEY `idx_conversations_last_message` (`last_message_at`),
  KEY `fk_conversations_contact` (`contact_id`),
  CONSTRAINT `fk_conversations_assigned_user` FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_conversations_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conversations`
--

LOCK TABLES `conversations` WRITE;
/*!40000 ALTER TABLE `conversations` DISABLE KEYS */;
INSERT INTO `conversations` VALUES (1,1,NULL,'open','2026-11-14 15:22:18','2026-11-14 13:59:16','2026-11-14 14:22:17'),(2,8,NULL,'open','2026-11-14 15:09:58','2026-11-14 14:10:00','2026-11-14 14:10:00'),(3,11,NULL,'open','2026-11-14 15:16:42','2026-11-14 14:10:30','2026-11-14 14:16:42'),(4,33,NULL,'open','2026-11-14 15:17:46','2026-11-14 14:17:45','2026-11-14 14:17:45'),(5,49,NULL,'open','2026-11-14 15:54:14','2026-11-14 14:32:41','2026-11-14 14:54:13'),(6,50,NULL,'open','2026-11-14 15:36:48','2026-11-14 14:36:14','2026-11-14 14:36:49'),(7,55,NULL,'open','2026-11-14 16:15:04','2026-11-14 14:56:26','2026-11-14 15:15:06'),(8,56,NULL,'open','2026-11-14 16:00:31','2026-11-14 15:09:50','2026-11-14 15:09:50');
/*!40000 ALTER TABLE `conversations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint unsigned NOT NULL,
  `whatsapp_message_id` varchar(120) DEFAULT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `message_type` varchar(40) NOT NULL DEFAULT 'chat',
  `body` text,
  `sent_by_user_id` bigint unsigned DEFAULT NULL,
  `whatsapp_timestamp` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_messages_whatsapp_id` (`whatsapp_message_id`),
  KEY `idx_messages_conversation_created` (`conversation_id`,`created_at`),
  KEY `fk_messages_sent_by_user` (`sent_by_user_id`),
  CONSTRAINT `fk_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_messages_sent_by_user` FOREIGN KEY (`sent_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
INSERT INTO `messages` VALUES (1,1,'AC281A681EA988CCDFBBCC01172CBD78','inbound','chat','J\'ai parlé de brûler les étapes parce que le but principal de la création de ce groupe n\'a pas été atteint et nous voulions déjà parler d\'autres choses. En plus la décision ne viendra pas que de moi, Aubin et toi. Nous devrions réunir toutes les conditions pour faire exactement ce que tu viens de dire. La logique est là, est ce que tout le monde est d\'accord avec cette logique ? Déjà même pour répondre à un message c\'est tout un problème. Tu as vu loin et je suis d\'accord avec toi, ceux qui nous lisent ont aussi leur mot à dire pour qu\'on avance. Pour mon compte, ma priorité est les cotisation ensuite on pourra voir ce cas. Exactement la réciprocité devrait être pour tout le monde, malheureusement c\'est impossible dans notre monde voilà pourquoi, quand Raphy m\'a proposé de créer et de modérer ce groupe, j\'avais une autre vision avec le temps, j\'ai changé d\'avis et j\'ai même donné mes 2K malgré la galère qui nous a pris en otage.',NULL,'2026-11-14 14:59:16','2026-11-14 13:59:16'),(2,1,'AC2FD2D1AF167D28CB27AE230D36B1BC','inbound','chat','Chers freres mettons un peu de vin dans notre verre de vin',NULL,'2026-11-14 15:04:37','2026-11-14 14:04:36'),(3,1,'AC3DE926F8312034CE3B44557AE03273','inbound','chat','Tozo solola kaka ti.',NULL,'2026-11-14 15:05:31','2026-11-14 14:05:31'),(4,1,'AC1187607495DB5F57F0AF946747F9E0','inbound','chat','Moi je pesnse k\'à compter de cette veillée, ce cahier nous servira de référence, ceux qui cotiseront seront led admis aux future assistances',NULL,'2026-11-14 15:06:05','2026-11-14 14:06:05'),(5,1,'ACC323199232119E823FF462254162D3','inbound','chat','Olingui kwiti hein 😂',NULL,'2026-11-14 15:06:06','2026-11-14 14:06:06'),(6,1,'AC4E8F83CA93B2EC317F22D9A197C490','inbound','chat','Notre erreur d\'hier ne doit plus avoir sa place aujourd\'hui',NULL,'2026-11-14 15:07:38','2026-11-14 14:07:37'),(7,1,'ACA542F2A874FE20BE44D50413A6A96A','inbound','chat','Aubin et Diop svp veuillez à ce que ce cahier soit officiel et référentiel\n\nTout ceux qui cotiseront feront partir de cette plate forme et à l\'issue de cela on pourra faire asseoir une base juridique',NULL,'2026-11-14 15:09:43','2026-11-14 14:09:43'),(8,2,'3EB09DE587DD16A217DF40','outbound','chat','salut',NULL,'2026-11-14 15:09:58','2026-11-14 14:10:00'),(9,1,'ACC53B846BA2D561B18A9524E151294E','inbound','chat','Je suis d\'accord avec toi, mais je ne sais pas qu\'appelles-tu par assistance ? Ceux qui ne vont pas cotiser mais qui ont apporté leurs assistance physique ne méritent pas qu\'on les assistes aussi ? Moi je suis orphelin de père et de mère, ma grand-mère est partie. Je ne souhaite pas un malheur puisse nous arriver, sauf un évènement heureux.',NULL,'2026-11-14 15:10:18','2026-11-14 14:10:18'),(11,3,'ACECF33D1573671696884E3F1E3C9591','inbound','chat','Bon .... mon feu père avait beaucoup de cheveux mais je ne sais là où il avait caché mes photos la, je cherche encore 😂🤣 .. disant que moi aussi j\'ai eu beaucoup de cheveux, c\'est génétique nonnnn.',NULL,'2026-11-14 15:10:30','2026-11-14 14:10:30'),(12,1,'ACEC2970B2C39FEAE90880EEE37B4AE6','inbound','chat','Moi je suis toute oui',NULL,'2026-11-14 15:10:40','2026-11-14 14:10:39'),(13,1,'AC9F3B3D4FF6AC0E1618B4E32ABF3D23','inbound','chat','Voilà ne t\'en fait pas pour ça je sais conserver. Un grand archiviste.',NULL,'2026-11-14 15:11:11','2026-11-14 14:11:10'),(14,1,'AC3B40680A427C1FA4F3FA62C9B5B0A9','inbound','chat','Diop si tu suis bien Lanvain il s\'agit d\'assistance financière',NULL,'2026-11-14 15:11:29','2026-11-14 14:11:29'),(15,1,'AC459992112D63466E728203B3CB8CC4','inbound','chat','C\'est là où est le debay',NULL,'2026-11-14 15:12:05','2026-11-14 14:12:04'),(16,1,'AC9AF12F3B93CB09FD36AD27DB701530','inbound','chat','Debat',NULL,'2026-11-14 15:12:09','2026-11-14 14:12:09'),(17,1,'AC307282302C60CED7EC554989AC897F','inbound','chat','Et j\'interviens pour cela',NULL,'2026-11-14 15:12:42','2026-11-14 14:12:41'),(18,1,'AC8FDBCD65C20C2575E2CD6FAE8E8790','inbound','chat','Oui oui ! Il a trop venu des situations où on a créé un groupe à cet effet et après c\'est mort, c\'est pourquoi il veut prendre ses dispositions 😂😂😂',NULL,'2026-11-14 15:12:42','2026-11-14 14:12:42'),(19,1,'ACF0E89E2DB8E49244DB0156DD76B64A','inbound','chat','Yes frérot !',NULL,'2026-11-14 15:13:00','2026-11-14 14:12:59'),(20,1,'AC8D4596B0C7348A09A887EA42638743','inbound','chat','Voila prkoi plus haut j\'ai dit stop à nos erreur d\'hier',NULL,'2026-11-14 15:13:26','2026-11-14 14:13:26'),(21,1,'ACB5CD88AB5FAC3634F46C6EED2B6046','inbound','chat','Nous allons honorer, mère Mouss même avec les faibles moyens financiers.',NULL,'2026-11-14 15:13:52','2026-11-14 14:13:51'),(22,1,'ACAA164C857A106E53B5CF61CB2E223C','inbound','chat','Kitoko ! J\'avais déjà compris.',NULL,'2026-11-14 15:14:04','2026-11-14 14:14:03'),(23,1,'AC17A43DFC37FF6A6279A4221831AB4B','inbound','chat','Merxi bakala',NULL,'2026-11-14 15:14:22','2026-11-14 14:14:21'),(24,3,'AC6730C447EA01CBA759786ECF9EBA7F','inbound','chat','@duc viens voir tel père tel fils... en 1988 regarde le noeud et la chemise boutonnée au col...j\'ai juste hérité.',NULL,'2026-11-14 15:15:24','2026-11-14 14:15:23'),(25,1,'AC2FCACD6529B4E4E743F1C3E41BF1D3','inbound','chat','Je suis d\'accord avec toi Ramos, je suggérais que l\'on enterre d\'abord notre maman et ensuite nous pouvions envisager des éventuelles questions comme la création d\'une mutuelle avec documents demandés, après l\'enterrement on peut organiser une réunion en présentielle où physique afin de mettre les points sur les I. Voici ici ma contribution.',NULL,'2026-11-14 15:15:29','2026-11-14 14:15:28'),(26,1,'ACDB0F6D9CB00326391DA87EBA14DB12','inbound','chat',NULL,NULL,'2026-11-14 15:15:33','2026-11-14 14:15:32'),(27,1,'ACF1A1D8FFA7588DD3C998F6207D5A9F','inbound','chat','Je passe ma jrnee chez le gros',NULL,'2026-11-14 15:15:51','2026-11-14 14:15:51'),(28,1,'AC3D42D0ACA0E3E7B1FD49C91FD1735B','inbound','chat',NULL,NULL,'2026-11-14 15:16:02','2026-11-14 14:16:01'),(29,1,'ACD4A81FC81E7EF544C5BD13D322CE4A','inbound','chat',NULL,NULL,'2026-11-14 15:16:10','2026-11-14 14:16:09'),(30,3,'AC88BBCF9B685547C79D5BD2CD9F8B8B','inbound','chat','EAET BOUKOUYA pour la vie.',NULL,'2026-11-14 15:16:42','2026-11-14 14:16:42'),(31,1,'AC584D1F90DF99D6765456E01CDB86F1','inbound','chat','Je suis d\'avis',NULL,'2026-11-14 15:16:48','2026-11-14 14:16:47'),(32,1,'AC3ED8BFED595CB86F4E4AF3F4799353','inbound','chat','Je suis d\'accord avec toi Ramos, je suggérais que l\'on enterre d\'abord notre maman et ensuite nous pouvions envisager des éventuelles questions comme la création d\'une mutuelle avec documents demandés, après l\'enterrement on peut organiser une réunion en présentielle où par visioconférence afin de mettre les points sur les I. Voici ici ma contribution.',NULL,'2026-11-14 15:17:03','2026-11-14 14:17:02'),(33,4,'A5E8572E1B27AF0B3846D97A7C320FC9-1','inbound','chat',NULL,NULL,'2026-11-14 15:17:46','2026-11-14 14:17:45'),(34,4,'A5E8572E1B27AF0B3846D97A7C320FC9','inbound','chat',NULL,NULL,'2026-11-14 15:17:46','2026-11-14 14:17:50'),(35,1,'ACB2BCB6E7E5AD17EDF2B0735B1DF11F','inbound','chat','Goût fort !',NULL,'2026-11-14 15:17:57','2026-11-14 14:17:56'),(36,1,'AC77CC668A0F49C8459AA4EB2984E6B2','inbound','chat','C\'est ce que j\'ai dit en amont.',NULL,'2026-11-14 15:18:36','2026-11-14 14:18:35'),(37,1,'AC46EE0556D2E1340E3DE4199F8F984A','inbound','chat','Salut le de ma part Ramos, j\'y serai là lundi, je me sens un peu mal',NULL,'2026-11-14 15:18:41','2026-11-14 14:18:41'),(38,1,'ACCC8BF3F60045BC8D6F592F87BD572E','inbound','chat','Kitoko',NULL,'2026-11-14 15:18:56','2026-11-14 14:18:56'),(39,1,'AC7C794938B15B32E921D279CE64E6AE','inbound','chat',NULL,NULL,'2026-11-14 15:19:07','2026-11-14 14:19:07'),(40,1,'AC84C686EB9407BF9D6320438500AA10','inbound','chat','👋🏽👋🏽👋🏽👋🏽👋🏽👋🏽',NULL,'2026-11-14 15:19:24','2026-11-14 14:19:23'),(41,1,'ACA32C2FBBF4DF9682C713FBD1B3AB5F','inbound','chat','Top',NULL,'2026-11-14 15:19:32','2026-11-14 14:19:32'),(42,1,'AC31697811583449D715A88F23802A72','inbound','chat',NULL,NULL,'2026-11-14 15:19:40','2026-11-14 14:19:40'),(43,1,'AC05432159F131077A3517897C912654','inbound','chat','Bjr cadet',NULL,'2026-11-14 15:19:52','2026-11-14 14:19:52'),(44,1,'ACEE6648218CB458C02F6E83DC994A8C','inbound','chat','Ah ok ✌️ donc prenons notre mal en patience, l\'objectif ici c\'est de cotiser et après on verra comment faire vivre le truc',NULL,'2026-11-14 15:20:11','2026-11-14 14:20:11'),(45,1,'ACE70F74CEE39018B7C957C7EBCD41D5','inbound','chat','Tu vas bien je pende',NULL,'2026-11-14 15:20:11','2026-11-14 14:20:11'),(46,1,'ACE9C27DA7AFC344CE1CD001F7CF9E17','inbound','chat','Pense',NULL,'2026-11-14 15:20:18','2026-11-14 14:20:17'),(47,1,'AC49744C8164020074D4CEDCFC411E22','inbound','chat',NULL,NULL,'2026-11-14 15:20:31','2026-11-14 14:20:30'),(48,1,'AC067179EAC4C14E70BA80D471D39696','inbound','chat','Tout ça déjà dit bakala.',NULL,'2026-11-14 15:22:18','2026-11-14 14:22:17'),(49,5,'AC3E502A081033251394898C0EF97278','inbound','chat','Oui',NULL,'2026-11-14 15:32:41','2026-11-14 14:32:41'),(50,6,'3EB0DA46C7F91F6E3481A3','outbound','chat','je test une app que jai conuc',NULL,'2026-11-14 15:36:12','2026-11-14 14:36:14'),(51,6,'3EB0D4D870F9BFBD894E4B','outbound','chat','est ce que tu vois mes messages?',NULL,'2026-11-14 15:36:48','2026-11-14 14:36:49'),(52,5,'AC334F663CC610FBEA86A126C39FE5D5','inbound','unknown',NULL,NULL,'2026-11-14 15:54:06','2026-11-14 14:54:06'),(53,7,'AC01F5F661213B0D5262CE00D54CF873','inbound','chat','Merci mon cher. Bon week-end à Tous. J\'ose croire que tous se portent bien !',NULL,'2026-11-14 15:56:26','2026-11-14 14:56:26'),(54,8,'3EB0751B4FE82715473E05','outbound','chat','un autre test',NULL,'2026-11-14 15:59:55','2026-11-14 15:09:50'),(55,8,'3EB07AFA57968DBC67FE95','outbound','chat','un autre test 2',NULL,'2026-11-14 16:00:31','2026-11-14 15:09:50'),(56,7,'ACB9CE071E52553ADD1FE9DE8CE83433','inbound','unknown',NULL,NULL,'2026-11-14 16:09:50','2026-11-14 15:09:51'),(57,7,'3EB0F110056F85348E021A','outbound','chat','un autre test 2',NULL,'2026-11-14 16:15:04','2026-11-14 15:15:06');
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(160) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','supervisor','operator') NOT NULL DEFAULT 'operator',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Chancel KALA-KALA ZAHOU','kb1congo@gmail.com','$2y$12$WRK..dMRBkK.HUIF8uNfHeH/rDwczDaLRz9oPt8IjNFhD8MxM2Uuu','admin',1,'2026-11-08 18:57:24','2026-11-14 08:54:29');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `whatsapp_accounts`
--

DROP TABLE IF EXISTS `whatsapp_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `whatsapp_accounts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `account_id` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `phone_number` varchar(32) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'idle',
  `last_connected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_whatsapp_accounts_account_id` (`account_id`),
  UNIQUE KEY `uq_whatsapp_accounts_phone_number` (`phone_number`),
  KEY `idx_whatsapp_accounts_user` (`user_id`),
  KEY `idx_whatsapp_accounts_status` (`status`),
  CONSTRAINT `fk_whatsapp_accounts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `whatsapp_accounts`
--

LOCK TABLES `whatsapp_accounts` WRITE;
/*!40000 ALTER TABLE `whatsapp_accounts` DISABLE KEYS */;
INSERT INTO `whatsapp_accounts` VALUES (1,1,'wa_cc6801df6c82d746e625bf82','066206174',NULL,'connecting',NULL,'2026-11-14 09:17:33','2026-11-14 09:17:37');
/*!40000 ALTER TABLE `whatsapp_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'whatsapp_gateway'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-14 15:22:44

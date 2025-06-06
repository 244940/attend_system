-- MySQL dump 10.13  Distrib 9.0.1, for Win64 (x86_64)
--
-- Host: localhost    Database: attend_data
-- ------------------------------------------------------
-- Server version	8.0.39

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
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins` (
  `admin_id` varchar(50) NOT NULL,
  `admin_name` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `face_encoding` blob,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `hashed_password` varchar(255) DEFAULT NULL,
  `password_changed` tinyint(1) DEFAULT '0',
  `citizen_id` varchar(13) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `citizen_id` (`citizen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES ('A001','α╕Öα╕▓α╕çα╕¬α╕▓α╕º α╕¢α╕┤α╕óα╕ºα╕úα╕úα╕ô α╕¬α╕▓α╕óα╕ºα╕▒α╕Öα╕öα╕╡','piyawan.sai1d@gmail.com',NULL,'2025-04-25 06:07:58','$2y$10$S8IlWEcHTD49oU.a3xS1R.HVJHovddJWRboJkSq.bmYVRCfSWkqje',1,'1529500004627','female','2002-12-19','0987521615','Miss Piyawan Saiwandee');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `id` int NOT NULL,
  `student_id` bigint DEFAULT NULL,
  `scan_time` datetime DEFAULT NULL,
  `status` enum('present','absent','late') DEFAULT NULL,
  `schedule_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `schedule_id` (`schedule_id`),
  CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `courses`
--

DROP TABLE IF EXISTS `courses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `courses` (
  `course_id` int NOT NULL AUTO_INCREMENT,
  `course_name` varchar(255) NOT NULL,
  `course_code` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `name_en` varchar(255) DEFAULT NULL,
  `teacher_id` bigint DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `group_number` int DEFAULT NULL,
  `semester` enum('first','second','summer') DEFAULT NULL,
  `c_year` year DEFAULT NULL,
  `teacher_name` varchar(255) DEFAULT NULL,
  `year_code` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`course_id`),
  UNIQUE KEY `course_code` (`course_code`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `courses`
--

LOCK TABLES `courses` WRITE;
/*!40000 ALTER TABLE `courses` DISABLE KEYS */;
INSERT INTO `courses` VALUES (1,'α╕¿α╕┤α╕Ñα╕¢α╕░','01418211','2025-05-12 05:25:37','Art',5424000001,'Monday','12:30:00','08:25:00',800,'first',2025,'α╣üα╕öα╕ç','60'),(2,'α╕ºα╕úα╕úα╕ôα╕üα╕úα╕úα╕í','01355103','2025-05-12 06:21:00','Literature and Science',5424000001,'Wednesday','08:23:00','05:22:00',800,'first',2025,'α╣üα╕öα╕ç','60');
/*!40000 ALTER TABLE `courses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollments`
--

DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enrollments` (
  `enrollment_id` int NOT NULL,
  `student_id` bigint DEFAULT NULL,
  `course_id` int DEFAULT NULL,
  `enrollment_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`enrollment_id`),
  KEY `student_id` (`student_id`),
  KEY `fk_enrollments_course` (`course_id`),
  CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  CONSTRAINT `fk_enrollments_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollments`
--

LOCK TABLES `enrollments` WRITE;
/*!40000 ALTER TABLE `enrollments` DISABLE KEYS */;
/*!40000 ALTER TABLE `enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedules`
--

DROP TABLE IF EXISTS `schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedules` (
  `schedule_id` int NOT NULL AUTO_INCREMENT,
  `teacher_id` bigint DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `course_id` int DEFAULT NULL,
  PRIMARY KEY (`schedule_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `course_id` (`course_id`),
  CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedules`
--

LOCK TABLES `schedules` WRITE;
/*!40000 ALTER TABLE `schedules` DISABLE KEYS */;
INSERT INTO `schedules` VALUES (1,5424000001,'Monday','08:25:00','12:30:00',1),(2,5424000001,'Wednesday','05:22:00','08:23:00',2);
/*!40000 ALTER TABLE `schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `students` (
  `student_id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `face_encoding` blob,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `hashed_password` varchar(255) DEFAULT NULL,
  `password_changed` tinyint(1) DEFAULT '0',
  `citizen_id` varchar(13) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `citizen_id` (`citizen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (6421600001,'α╕Öα╕▓α╕çα╕¬α╕▓α╕ºα╕Öα╕áα╕▒α╕ùα╕ú α╕íα╕╡α╕¬α╕╕α╕é','naphat.m@ku.th',NULL,'2025-05-06 03:47:11',NULL,0,'1529500004635','female','2004-09-25','0898765433','Ms. Naphat Meesuk'),(6421600002,'α╕Öα╕▓α╕çα╕¬α╕▓α╕ºα╕¢α╕ºα╕╡α╕ôα╕▓ α╕¬α╕öα╣âα╕¬','piyawan.saiw@ku.th',NULL,'2025-05-06 03:48:31',NULL,0,'1529500004637','female','2003-06-18','0851234568','Ms. Pawina Sodsai'),(6421600003,'α╕Öα╕▓α╕óα╕üα╕┤α╕òα╕òα╕┤α╕èα╕▒α╕ó α╣Çα╕úα╕╡α╕óα╕Öα╣Çα╕üα╣êα╕ç','kittichai.r@ku.th',NULL,'2025-05-06 03:48:51',NULL,0,'1529500004634','male','2003-04-12','0812345679','Mr. Kittichai Riangekt'),(6421600004,'α╕Öα╕▓α╕óα╕ÿα╕Öα╕áα╕▒α╕ùα╕ú α╕éα╕óα╕▒α╕Öα╣Çα╕úα╕╡α╕óα╕Ö','thanaphat.k@ku.th',NULL,'2025-05-06 03:49:07',NULL,0,'1529500004636','male','2002-12-01','0876543211','Mr. Thanaphat Khayanrian'),(6421600005,'α╕Öα╕▓α╕óα╕¿α╕╕α╕áα╕èα╕▒α╕ó α╕ëα╕Ñα╕▓α╕öα╕½α╕Ñα╕▒α╕üα╣üα╕½α╕Ñα╕í','suphachai.c@ku.th',NULL,'2025-05-06 03:49:21',NULL,0,'1529500004638','male','2004-02-14','0839876544','Mr. Suphachai Chalatlaem'),(6421600006,'α╕«α╕▓α╣üα╕Ñα╕Öα╕öα╣î','wonwinpor@gmail.com',NULL,'2025-05-06 03:49:31',NULL,0,'1456667899012','male','2002-07-18','0976648217','Haaland');
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teachers`
--

DROP TABLE IF EXISTS `teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teachers` (
  `teacher_id` bigint NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `face_encoding` blob,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `hashed_password` varchar(255) DEFAULT NULL,
  `password_changed` tinyint(1) DEFAULT '0',
  `citizen_id` varchar(13) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `name_en` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`teacher_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `citizen_id` (`citizen_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teachers`
--

LOCK TABLES `teachers` WRITE;
/*!40000 ALTER TABLE `teachers` DISABLE KEYS */;
INSERT INTO `teachers` VALUES (5424000001,'α╣üα╕öα╕ç','meiyouniwozenmeban@gmail.com',_binary '\0\0\0ÇtS╛┐\0\0\0\αr\╞?\0\0\0 \µ\Z┴?\0\0\0\0º,ê┐\0\0\0`\εF╕┐\0\0\0Çëz~?\0\0\0@╕.┤┐\0\0\0Çåm╜┐\0\0\0└│u\├?\0\0\0@Å┌░┐\0\0\0ÇÉ]\╘?\0\0\0@î»í┐\0\0\0\α\Ω\≡─┐\0\0\0áp\Z▓┐\0\0\0@rs¼┐\0\0\0└sa\╦?\0\0\0Ç╢ê╨┐\0\0\0Ç¼Dñ┐\0\0\0└╨¢»┐\0\0\0ÇV{Æ┐\0\0\0`\±O»?\0\0\0\α²8¡?\0\0\0`Q2╢?\0\0\0ÇQ«ƒ┐\0\0\0@[v»┐\0\0\0 Ç┘┐\0\0\0\α¬┴¼┐\0\0\0Ç>\≤╡┐\0\0\0áµ£¼?\0\0\0└Ügá┐\0\0\0\α╬╗╢┐\0\0\0  :í┐\0\0\0ái╔┐\0\0\0@íí╡┐\0\0\0áⁿ?ò?\0\0\0\0▐ò?\0\0\0\α[¢₧┐\0\0\0á\▐┤┐\0\0\0Ç\"Å\╔?\0\0\0\0íR½┐\0\0\0`h╜╞┐\0\0\0\0╞ªª?\0\0\0 $E▒?\0\0\0└╦Ü\╟?\0\0\0\0\╫\═?\0\0\0`bÆ?\0\0\0└\τ\σ⌐?\0\0\0└G╝╣┐\0\0\0\α\≤C┴?\0\0\0└k┬┐\0\0\0\0P«æ?\0\0\0 b┴?\0\0\0 \╩╕?\0\0\0└ÿ─½?\0\0\0\αP╕ü?\0\0\0@₧d╢┐\0\0\0\α	╦⌐┐\0\0\0á■\├?\0\0\0ÇëB┤┐\0\0\0@αªÇ┐\0\0\0á¢\┬?\0\0\0\αY«┐\0\0\0\0y┬ö┐\0\0\0\0AM│┐\0\0\0@7â\╩?\0\0\0`rg½?\0\0\0 ]╛┐\0\0\0áå╔┐\0\0\0Ç₧┤?\0\0\0└\╚O½┐\0\0\0\α⌐º┐\0\0\0└£¿?\0\0\0`0╝╞┐\0\0\0Çè│─┐\0\0\0└+╤┐\0\0\0\0\▐Uá?\0\0\0\αJ>\█?\0\0\0\0º?\0\0\0\0(ç╞┐\0\0\0ÇQσé┐\0\0\0└\┬\≡╖┐\0\0\0Ç¢┐\0\0\0└T\Σ▓?\0\0\0@i\∞┴?\0\0\0└[╖ñ?\0\0\0Ç╕▓æ┐\0\0\0á.j╢┐\0\0\0`r1ä?\0\0\0└éƒ\╔?\0\0\0\0û╟▓┐\0\0\0@gU₧┐\0\0\0@▒i\╩?\0\0\0`V\≥û┐\0\0\0\0⌐º?\0\0\0\0)eá┐\0\0\0 \Φeº?\0\0\0@├│┐\0\0\0ÇAù«?\0\0\0 p╚╡┐\0\0\0\0┤£ò?\0\0\0@\╤Xû┐\0\0\0└\╫2É┐\0\0\0\0\∩/Ü┐\0\0\0\0G\0╡?\0\0\0└äô┬┐\0\0\0 ê>┐?\0\0\0\0₧0í┐\0\0\0@1h╡?\0\0\0á\╫;í?\0\0\0\0\╙?ª┐\0\0\0`┤$ó┐\0\0\0Ç░âá┐\0\0\0á\τ}└?\0\0\0`\≡á╩┐\0\0\0ÇÜ┴\╚?\0\0\0\0\┬#\─?\0\0\0`\├\⌡p?\0\0\0á└\⌠\┬?\0\0\0@&╡«?\0\0\0Ç_É╜?\0\0\0\0 1`┐\0\0\0@\∩\Σ⌐?\0\0\0@¿`╔┐\0\0\0 æ ▒┐\0\0\0@mú?\0\0\0@fb£┐\0\0\0\α\σ╕?\0\0\0@a╛ç?','2025-05-06 04:08:39','$2y$10$lM03GB4LbErl0FM8qxk8SeztlkbCK/.LI/ekKoOrUwfdhvXeiDN3W',1,'1529500004678','male','1990-06-29','0976648900','Deng');
/*!40000 ALTER TABLE `teachers` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-05-19 12:31:14

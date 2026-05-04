-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Feb 20, 2026 at 01:16 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tracking`
--

-- --------------------------------------------------------

--
-- Table structure for table `openers`
--

CREATE TABLE `openers` (
  `id` int(11) NOT NULL,
  `email` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `ip_address` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `server_ip` varchar(40) DEFAULT NULL,
  `server_ptr` varchar(180) DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci DEFAULT NULL,
  `when` timestamp NOT NULL DEFAULT current_timestamp(),
  `mx` varchar(255) DEFAULT NULL,
  `deployment` varchar(180) DEFAULT NULL,
  `campaign` varchar(100) DEFAULT NULL,
  `maillist` varchar(180) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `openers`
--

INSERT INTO `openers` (`id`, `email`, `ip_address`, `server_ip`, `server_ptr`, `user_agent`, `location`, `when`, `mx`, `deployment`, `campaign`, `maillist`) VALUES
(1, 'dre@kabelfoon.net', '163.158.141.163', '68.183.25.165', NULL, 'Mozilla/5.0 (iPad; CPU OS 9_3_5 like Mac OS X) AppleWebKit/601.1.46 (KHTML, like Gecko) Mobile/13G36', 'Netherlands', '2019-12-24 08:30:55', NULL, 'app7', NULL, 'nl openers (latest)'),
(2, 'bert.boers@ziggo.nl', '94.215.192.210', '178.128.180.253', NULL, 'Mozilla/5.0 (Linux; Android 9; SM-G960F Build/PPR1.180610.011; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/79.0.3945.93 Mobile Safari/537.36', 'Netherlands', '2019-12-24 08:31:03', 'mxin5.ziggo.nl', 'app7', NULL, 'nl openers (latest)');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

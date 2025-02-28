-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 28, 2025 at 04:35 AM
-- Server version: 8.0.41
-- PHP Version: 8.3.15

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `riskverustech_risk`
--

-- --------------------------------------------------------

--
-- Table structure for table `colors`
--

CREATE TABLE `colors` (
  `color_id` int NOT NULL,
  `name` varchar(20) NOT NULL,
  `hex_code` varchar(7) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `games`
--

CREATE TABLE `games` (
  `game_id` int NOT NULL,
  `current_turn` int DEFAULT NULL,
  `phase` enum('reinforce','attack','fortify') DEFAULT 'reinforce',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `conquest_pending` tinyint DEFAULT '0',
  `conquest_from_id` int DEFAULT NULL,
  `conquest_to_id` int DEFAULT NULL,
  `conquest_dice` int DEFAULT NULL,
  `turn_order` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `game_log`
--

CREATE TABLE `game_log` (
  `log_id` int NOT NULL,
  `game_id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(255) NOT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `game_players`
--

CREATE TABLE `game_players` (
  `game_id` int NOT NULL,
  `user_id` int NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `army_reserve` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `game_settings`
--

CREATE TABLE `game_settings` (
  `game_id` int NOT NULL,
  `setting_name` varchar(50) NOT NULL,
  `setting_value` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `territories`
--

CREATE TABLE `territories` (
  `territory_id` int NOT NULL,
  `game_id` int DEFAULT NULL,
  `name` varchar(50) DEFAULT NULL,
  `owner_id` int DEFAULT NULL,
  `armies` int DEFAULT '1',
  `master_territory_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `territory_adjacencies`
--

CREATE TABLE `territory_adjacencies` (
  `territory_id` int NOT NULL,
  `adjacent_id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `territory_master`
--

CREATE TABLE `territory_master` (
  `territory_id` int NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_admin` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `nickname` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `colors`
--
ALTER TABLE `colors`
  ADD PRIMARY KEY (`color_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`game_id`);

--
-- Indexes for table `game_log`
--
ALTER TABLE `game_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `game_id` (`game_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `game_players`
--
ALTER TABLE `game_players`
  ADD PRIMARY KEY (`game_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `game_settings`
--
ALTER TABLE `game_settings`
  ADD PRIMARY KEY (`game_id`,`setting_name`);

--
-- Indexes for table `territories`
--
ALTER TABLE `territories`
  ADD PRIMARY KEY (`territory_id`),
  ADD KEY `game_id` (`game_id`),
  ADD KEY `owner_id` (`owner_id`),
  ADD KEY `master_territory_id` (`master_territory_id`);

--
-- Indexes for table `territory_adjacencies`
--
ALTER TABLE `territory_adjacencies`
  ADD PRIMARY KEY (`territory_id`,`adjacent_id`),
  ADD KEY `adjacent_id` (`adjacent_id`);

--
-- Indexes for table `territory_master`
--
ALTER TABLE `territory_master`
  ADD PRIMARY KEY (`territory_id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `colors`
--
ALTER TABLE `colors`
  MODIFY `color_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `games`
--
ALTER TABLE `games`
  MODIFY `game_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `game_log`
--
ALTER TABLE `game_log`
  MODIFY `log_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `territories`
--
ALTER TABLE `territories`
  MODIFY `territory_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `territory_master`
--
ALTER TABLE `territory_master`
  MODIFY `territory_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `game_log`
--
ALTER TABLE `game_log`
  ADD CONSTRAINT `game_log_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`),
  ADD CONSTRAINT `game_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `game_players`
--
ALTER TABLE `game_players`
  ADD CONSTRAINT `game_players_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`),
  ADD CONSTRAINT `game_players_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `game_settings`
--
ALTER TABLE `game_settings`
  ADD CONSTRAINT `game_settings_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`);

--
-- Constraints for table `territories`
--
ALTER TABLE `territories`
  ADD CONSTRAINT `territories_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`),
  ADD CONSTRAINT `territories_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `territories_ibfk_3` FOREIGN KEY (`master_territory_id`) REFERENCES `territory_master` (`territory_id`);

--
-- Constraints for table `territory_adjacencies`
--
ALTER TABLE `territory_adjacencies`
  ADD CONSTRAINT `territory_adjacencies_ibfk_1` FOREIGN KEY (`territory_id`) REFERENCES `territory_master` (`territory_id`),
  ADD CONSTRAINT `territory_adjacencies_ibfk_2` FOREIGN KEY (`adjacent_id`) REFERENCES `territory_master` (`territory_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

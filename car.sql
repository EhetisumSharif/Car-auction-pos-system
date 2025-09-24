-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 10, 2025 at 06:18 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `car`
--

-- --------------------------------------------------------

--
-- Table structure for table `auction_and_pricing`
--

CREATE TABLE `auction_and_pricing` (
  `lot_number` varchar(50) NOT NULL,
  `auction_house_name` varchar(100) NOT NULL,
  `auction_datetime` datetime NOT NULL,
  `service_charge_jpy` decimal(15,2) DEFAULT NULL,
  `end_price_jpy` decimal(15,2) DEFAULT NULL,
  `chassis_number` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auction_and_pricing`
--

INSERT INTO `auction_and_pricing` (`lot_number`, `auction_house_name`, `auction_datetime`, `service_charge_jpy`, `end_price_jpy`, `chassis_number`) VALUES
('30251', 'USS Nagoya', '2024-05-20 00:00:00', 220000.00, 1170000.00, 'NKE165-7217207');

-- --------------------------------------------------------

--
-- Table structure for table `car_details`
--

CREATE TABLE `car_details` (
  `chassis_number` varchar(50) NOT NULL,
  `make` varchar(50) NOT NULL,
  `grade` varchar(50) DEFAULT NULL,
  `mileage_km` int(11) DEFAULT NULL,
  `engine_cc` int(11) DEFAULT NULL,
  `color` varchar(30) DEFAULT NULL,
  `current_location` varchar(100) DEFAULT NULL,
  `car_photo` varchar(255) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `year_of_manufacture` year(4) DEFAULT NULL,
  `yard_arrival_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `car_details`
--

INSERT INTO `car_details` (`chassis_number`, `make`, `grade`, `mileage_km`, `engine_cc`, `color`, `current_location`, `car_photo`, `model`, `engine_number`, `year_of_manufacture`, `yard_arrival_date`) VALUES
('NKE165-7217207', 'Toyota', '4.5', 15179, 1500, 'WHITE', 'Delivered', 'uploads/68a2436688e28_11zon_cropped.png', 'Axio', '1ZNZ-R776783', '2019', '2024-06-03');

-- --------------------------------------------------------

--
-- Table structure for table `client`
--

CREATE TABLE `client` (
  `client_id` int(11) NOT NULL,
  `client_name` varchar(100) NOT NULL,
  `company_name` varchar(100) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client`
--

INSERT INTO `client` (`client_id`, `client_name`, `company_name`, `contact_email`, `phone_number`, `address`) VALUES
(20, 'MR. ASHISH KUMAR', 'BCBL', '01710204041@gmail.com', '01710204041', 'BAPEX');

-- --------------------------------------------------------

--
-- Table structure for table `currency_exchange_rate`
--

CREATE TABLE `currency_exchange_rate` (
  `rate_id` int(11) NOT NULL,
  `jpy_to_usd_rate` decimal(10,6) NOT NULL,
  `usd_to_jpy_rate` decimal(10,6) NOT NULL,
  `usd_to_bdt_rate` decimal(10,6) NOT NULL,
  `rate_date` date NOT NULL DEFAULT curdate(),
  `under_invoice_rate` decimal(10,6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `currency_exchange_rate`
--

INSERT INTO `currency_exchange_rate` (`rate_id`, `jpy_to_usd_rate`, `usd_to_jpy_rate`, `usd_to_bdt_rate`, `rate_date`, `under_invoice_rate`) VALUES
(32, 0.006897, 145.000000, 123.000000, '2024-05-20', 125.000000),
(33, 0.006803, 147.000000, 123.500000, '2024-11-30', 125.000000),
(34, 0.007143, 140.000000, 120.500000, '2025-08-18', 122.000000),
(35, 0.007143, 140.000000, 120.500000, '2025-08-28', 122.000000);

-- --------------------------------------------------------

--
-- Table structure for table `lc`
--

CREATE TABLE `lc` (
  `id` int(11) NOT NULL,
  `lc_number` varchar(50) NOT NULL,
  `bank` varchar(100) NOT NULL,
  `lc_value_usd` decimal(10,2) NOT NULL,
  `car_chassis_no` varchar(100) NOT NULL,
  `cfr_price_usd` decimal(10,2) NOT NULL,
  `status` varchar(20) NOT NULL,
  `issue_date` date NOT NULL,
  `payment_date` date NOT NULL,
  `under_invoice_usd` decimal(10,2) DEFAULT NULL,
  `over_invoice_usd` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lc`
--

INSERT INTO `lc` (`id`, `lc_number`, `bank`, `lc_value_usd`, `car_chassis_no`, `cfr_price_usd`, `status`, `issue_date`, `payment_date`, `under_invoice_usd`, `over_invoice_usd`) VALUES
(9, 'SIBL-0003', 'SIBL', 10000.00, 'NKE165-7217207', 9586.83, 'Paid', '2024-08-31', '2024-11-30', 0.00, 413.17);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `car_chassis_no` varchar(255) NOT NULL,
  `client_id` int(11) NOT NULL,
  `sale_date` date NOT NULL,
  `selling_price_bdt` decimal(10,2) NOT NULL,
  `profit_usd` decimal(10,2) NOT NULL,
  `payment_status` enum('Pending','Paid') NOT NULL DEFAULT 'Pending',
  `duty_bdt` decimal(10,2) NOT NULL,
  `bank_charge_bdt` decimal(10,2) NOT NULL,
  `c_f_charge_bdt` decimal(10,2) NOT NULL,
  `transportation_bdt` decimal(10,2) NOT NULL,
  `other_cost_bdt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `profit_bdt` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `car_chassis_no`, `client_id`, `sale_date`, `selling_price_bdt`, `profit_usd`, `payment_status`, `duty_bdt`, `bank_charge_bdt`, `c_f_charge_bdt`, `transportation_bdt`, `other_cost_bdt`, `profit_bdt`) VALUES
(11, 'NKE165-7217207', 20, '2025-09-02', 1319973.51, 809.72, 'Paid', 10000.00, 15000.00, 5000.00, 5000.00, 1000.00, 100000.00);

-- --------------------------------------------------------

--
-- Table structure for table `shipment_details`
--

CREATE TABLE `shipment_details` (
  `shipment_id` int(11) NOT NULL,
  `port_of_shipment` varchar(100) NOT NULL,
  `estimated_time_of_departure` datetime NOT NULL,
  `vessel_name` varchar(100) DEFAULT NULL,
  `estimated_time_of_arrival` datetime DEFAULT NULL,
  `chassis_number` varchar(50) NOT NULL,
  `status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `shipment_details`
--

INSERT INTO `shipment_details` (`shipment_id`, `port_of_shipment`, `estimated_time_of_departure`, `vessel_name`, `estimated_time_of_arrival`, `chassis_number`, `status`) VALUES
(12, 'NAGOYA', '2024-12-18 00:00:00', 'MALAYSIA PASSION', '2025-05-14 00:00:00', 'NKE165-7217207', 'Delivered');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`) VALUES
(1, 'admin', '12345678');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `auction_and_pricing`
--
ALTER TABLE `auction_and_pricing`
  ADD PRIMARY KEY (`lot_number`),
  ADD KEY `fk_chassis_number_auction` (`chassis_number`);

--
-- Indexes for table `car_details`
--
ALTER TABLE `car_details`
  ADD PRIMARY KEY (`chassis_number`);

--
-- Indexes for table `client`
--
ALTER TABLE `client`
  ADD PRIMARY KEY (`client_id`);

--
-- Indexes for table `currency_exchange_rate`
--
ALTER TABLE `currency_exchange_rate`
  ADD PRIMARY KEY (`rate_id`);

--
-- Indexes for table `lc`
--
ALTER TABLE `lc`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `car_chassis_no` (`car_chassis_no`),
  ADD KEY `client_id` (`client_id`);

--
-- Indexes for table `shipment_details`
--
ALTER TABLE `shipment_details`
  ADD PRIMARY KEY (`shipment_id`),
  ADD KEY `fk_chassis_number_shipment` (`chassis_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `client`
--
ALTER TABLE `client`
  MODIFY `client_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `currency_exchange_rate`
--
ALTER TABLE `currency_exchange_rate`
  MODIFY `rate_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `lc`
--
ALTER TABLE `lc`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `shipment_details`
--
ALTER TABLE `shipment_details`
  MODIFY `shipment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `auction_and_pricing`
--
ALTER TABLE `auction_and_pricing`
  ADD CONSTRAINT `fk_chassis_number_auction` FOREIGN KEY (`chassis_number`) REFERENCES `car_details` (`chassis_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`car_chassis_no`) REFERENCES `car_details` (`chassis_number`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `client` (`client_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `shipment_details`
--
ALTER TABLE `shipment_details`
  ADD CONSTRAINT `fk_chassis_number_shipment` FOREIGN KEY (`chassis_number`) REFERENCES `car_details` (`chassis_number`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

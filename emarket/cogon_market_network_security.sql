-- Cogon Market and Cogon Market Office
-- Network Security Project Database
-- MySQL / MariaDB compatible
-- Import this file using phpMyAdmin or run it in MySQL.

CREATE DATABASE IF NOT EXISTS cogon_market_network
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE cogon_market_network;

-- 1. VLAN information
CREATE TABLE IF NOT EXISTS vlans (
    vlan_id INT PRIMARY KEY,
    vlan_name VARCHAR(50) NOT NULL,
    subnet VARCHAR(30) NOT NULL,
    description VARCHAR(150)
);

INSERT INTO vlans (vlan_id, vlan_name, subnet, description) VALUES
(10, 'Administration', '192.168.10.0/24', 'Administration department'),
(20, 'Operations_Finance', '192.168.20.0/24', 'Market operations and finance'),
(30, 'Management', '192.168.30.0/24', 'Management department'),
(40, 'CCTV', '192.168.40.0/24', 'CCTV cameras and monitoring devices'),
(50, 'Guest_WiFi', '192.168.50.0/24', 'Guest wireless network'),
(60, 'Office_Devices', '192.168.60.0/24', 'Office printers and other devices')
ON DUPLICATE KEY UPDATE
    vlan_name = VALUES(vlan_name),
    subnet = VALUES(subnet),
    description = VALUES(description);

-- 2. Network devices in the market and office
CREATE TABLE IF NOT EXISTS network_devices (
    device_id INT AUTO_INCREMENT PRIMARY KEY,
    device_name VARCHAR(100) NOT NULL,
    device_type VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    location VARCHAR(100) NOT NULL,
    vlan_id INT NULL,
    FOREIGN KEY (vlan_id) REFERENCES vlans(vlan_id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
);

INSERT INTO network_devices
(device_name, device_type, ip_address, location, vlan_id) VALUES
('Router0', 'Router', '10.10.0.1', 'Main Router', NULL),
('Router1', 'Router', '10.10.1.2', 'Cogon Market', NULL),
('Router2', 'Router', '10.10.2.2', 'Cogon Market Office', NULL),
('Switch1', 'Switch', '192.168.10.2', 'Cogon Market', 10),
('Switch2', 'Switch', '192.168.10.3', 'Cogon Market Office', 10),
('Market_CCTV1', 'CCTV Camera', '192.168.40.10', 'Cogon Market', 40),
('Market_NVR1', 'NVR', '192.168.40.30', 'Cogon Market', 40),
('Office_Printer1', 'Printer', '192.168.60.10', 'Cogon Market Office', 60);

-- 3. Security service servers
CREATE TABLE IF NOT EXISTS security_servers (
    server_id INT AUTO_INCREMENT PRIMARY KEY,
    server_name VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    service_name VARCHAR(50) NOT NULL,
    port_number INT NOT NULL,
    protocol VARCHAR(10) NOT NULL
);

INSERT INTO security_servers
(server_name, ip_address, service_name, port_number, protocol) VALUES
('TACACS_Server', '10.10.10.10', 'TACACS+', 49, 'TCP'),
('RADIUS_Server', '10.10.10.11', 'RADIUS Authentication', 1812, 'UDP'),
('Syslog_Server', '10.10.10.12', 'Syslog', 514, 'UDP'),
('NTP_Server', '10.10.10.13', 'NTP', 123, 'UDP');

-- 4. Security event logs
CREATE TABLE IF NOT EXISTS security_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    device_name VARCHAR(100) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    description TEXT,
    event_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. Administrator account records
-- Store only secure password hashes, never plain-text passwords.
CREATE TABLE IF NOT EXISTS admin_accounts (
    admin_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL DEFAULT 'Administrator',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. Sample security log
INSERT INTO security_logs (device_name, event_type, description)
VALUES ('Router1', 'SSH_ACCESS', 'Sample SSH access event for project demonstration');

-- 7. View the saved records
SELECT * FROM vlans;
SELECT * FROM network_devices;
SELECT * FROM security_servers;
SELECT * FROM security_logs;
SELECT admin_id, username, role, created_at FROM admin_accounts;

-- NOTE:
-- This database stores project documentation and sample records.
-- It does not configure Cisco VLANs, SSH, NTP, Syslog, TACACS+,
-- or RADIUS. Configure and test those services separately in
-- Cisco Packet Tracer or on the actual network devices.

CREATE DATABASE IF NOT EXISTS mpin_spaghetti_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mpin_spaghetti_db;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  role ENUM('ADMIN','INVENTORY_MANAGER','ORDER_MANAGER','DELIVERY_MANAGER','FINANCE_OFFICER','CUSTOMER') NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS global_store (
  item_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_name VARCHAR(160) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock_qty INT UNSIGNED NOT NULL,
  CHECK (price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_orders (
  order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  total DECIMAL(12,2) NOT NULL,
  status ENUM('PENDING','PAID','SHIPPED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_system_orders_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_system_orders_item FOREIGN KEY (item_id) REFERENCES global_store(item_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_deliveries (
  delivery_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  assigned_to INT UNSIGNED NOT NULL,
  status ENUM('PENDING','IN_TRANSIT','DELIVERED') NOT NULL DEFAULT 'PENDING',
  tracking_code VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_deliveries_order FOREIGN KEY (order_id) REFERENCES system_orders(order_id),
  CONSTRAINT fk_deliveries_user FOREIGN KEY (assigned_to) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_transactions (
  payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  status ENUM('PENDING','PAID','REFUSED') NOT NULL DEFAULT 'PENDING',
  processed_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES system_orders(order_id),
  CONSTRAINT fk_payments_user FOREIGN KEY (processed_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO users (username, password, role) VALUES
  ('admin', '$2y$12$iPPBxhL/wZ6oLlnySH6U7eEwa3iqa5.eRIh.eoiefzRPzqM.GgF4.', 'ADMIN'),
  ('inventory', '$2y$12$/nVQ.FmeGRzZs7KkL0GFe.e.Xes2BV8zdawLH4jjjXqXRyGVwxWry', 'INVENTORY_MANAGER'),
  ('orders', '$2y$12$DaXagn6Dj53E3PVMJMp67u/auXMCJmRSqAIzxjl/E7J6U0NoVaFPO', 'ORDER_MANAGER'),
  ('delivery', '$2y$12$HjfTOY/tZluKaQOLc1LdW.rWERQRkahOumRsEdV1Sef5rkTtToHg.', 'DELIVERY_MANAGER'),
  ('finance', '$2y$12$2JpoYLrLFTMh3r7sIpkHoODWk28zIbtvu5zGdyzxPZ2wjxw.ZQasq', 'FINANCE_OFFICER'),
  ('customer', '$2y$12$GrNZyq3aaWtNYabLdkh2PeGdlaLXLrQjKeFl8d6D.3aRyOJxOi9ry', 'CUSTOMER')
ON DUPLICATE KEY UPDATE role = VALUES(role);

INSERT INTO global_store (item_id, item_name, price, stock_qty) VALUES
  (1, 'Clavier mécanique', 79.90, 15),
  (2, 'Écran 27 pouces', 219.00, 8),
  (3, 'Souris sans fil', 34.50, 25)
ON DUPLICATE KEY UPDATE item_name = VALUES(item_name);

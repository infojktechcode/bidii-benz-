CREATE TABLE clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    id_type ENUM('national_id','passport','alien_id') NOT NULL DEFAULT 'national_id',
    id_number VARCHAR(20) NOT NULL,
    city VARCHAR(80) NULL,
    address VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_clients_user_id (user_id),
    UNIQUE KEY uq_clients_id_number (id_number),
    KEY idx_clients_name (full_name),
    CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

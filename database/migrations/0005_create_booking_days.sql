CREATE TABLE booking_days (
    car_id INT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    booking_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (car_id, day),
    KEY idx_bdays_booking (booking_id),
    CONSTRAINT fk_bdays_car FOREIGN KEY (car_id) REFERENCES cars (id) ON DELETE CASCADE,
    CONSTRAINT fk_bdays_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

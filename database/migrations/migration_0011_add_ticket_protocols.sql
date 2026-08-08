-- Ticket protocol entries for operational tracking and audit trail.

CREATE TABLE IF NOT EXISTS ticket_protocols (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    protocol_type ENUM('note','status_change','priority_change','update') NOT NULL DEFAULT 'note',
    message TEXT NOT NULL,
    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    old_priority VARCHAR(20) NULL,
    new_priority VARCHAR(20) NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_protocols_ticket
        FOREIGN KEY (ticket_id) REFERENCES tickets(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ticket_protocols_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_ticket_protocols_ticket_id (ticket_id),
    INDEX idx_ticket_protocols_created_at (created_at),
    INDEX idx_ticket_protocols_type (protocol_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

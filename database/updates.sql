-- Add theme preference column to users table
ALTER TABLE users ADD COLUMN theme_preference ENUM('dark', 'light') DEFAULT 'dark' AFTER status;

-- Add indexes for better performance
ALTER TABLE notifications ADD INDEX idx_user_read (user_id, is_read);
ALTER TABLE notifications ADD INDEX idx_created_at (created_at);
ALTER TABLE return_requests ADD INDEX idx_requester_status (requester_id, status);
ALTER TABLE return_requests ADD INDEX idx_created_at (created_at);
ALTER TABLE audit_logs ADD INDEX idx_user_timestamp (user_id, timestamp);
ALTER TABLE audit_logs ADD INDEX idx_table_record (table_name, record_id);

-- Add foreign key constraints for data integrity
ALTER TABLE return_items ADD CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES return_requests(return_id) ON DELETE CASCADE;
ALTER TABLE return_items ADD CONSTRAINT fk_return_items_item FOREIGN KEY (item_id) REFERENCES inventory(item_id) ON DELETE CASCADE;
ALTER TABLE notification_settings ADD CONSTRAINT fk_notification_settings_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE; 
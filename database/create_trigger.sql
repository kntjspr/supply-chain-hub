DELIMITER $$

DROP TRIGGER IF EXISTS after_request_approval$$

CREATE TRIGGER after_request_approval
AFTER UPDATE ON supply_requests
FOR EACH ROW
BEGIN
    IF NEW.status = 'approved' AND OLD.status != 'approved' THEN
        -- Update request_items status
        UPDATE request_items 
        SET status = 'approved'
        WHERE request_id = NEW.request_id;
        
        -- Log the approved items
        INSERT INTO request_logs (request_id, item_id, quantity, department_id, approval_time)
        SELECT 
            ri.request_id,
            ri.item_id,
            ri.quantity,
            NEW.department_id,
            NOW()
        FROM request_items ri
        WHERE ri.request_id = NEW.request_id;
    END IF;
END$$

DELIMITER ; 
-- Module 3: staff resolution, complainant verification/feedback, and resolution knowledge base.
-- Apply after migrations 003-006. Safe to re-run.
USE icars;

ALTER TABLE complaints ADD COLUMN IF NOT EXISTS resolution_details TEXT NULL AFTER description;
ALTER TABLE complaints ADD COLUMN IF NOT EXISTS resolution_confirmed_at DATETIME NULL AFTER resolved_at;

CREATE TABLE IF NOT EXISTS complaint_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    complaint_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    feedback TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_feedback_complaint (complaint_id),
    CONSTRAINT fk_feedback_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_feedback_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- Successful resolutions are the historical knowledge base; this view keeps case details,
-- category/priority, action history, resolution time, rating, and feedback queryable together.
CREATE OR REPLACE VIEW complaint_resolution_knowledge AS
SELECT c.id AS complaint_id, c.complaint_no, c.category_id, cat.name AS category,
       c.priority_id, p.name AS priority, c.title, c.description, c.resolution_details AS resolution,
       c.created_at, c.resolved_at, TIMESTAMPDIFF(SECOND, c.created_at, c.resolved_at) AS resolution_seconds,
       f.rating, f.feedback
  FROM complaints c
  JOIN complaint_statuses s ON s.id = c.status_id AND s.name IN ('Resolved','Closed')
  LEFT JOIN complaint_categories cat ON cat.id = c.category_id
  LEFT JOIN complaint_levels p ON p.id = c.priority_id
  LEFT JOIN complaint_feedback f ON f.complaint_id = c.id
 WHERE c.resolved_at IS NOT NULL AND c.resolution_details IS NOT NULL;

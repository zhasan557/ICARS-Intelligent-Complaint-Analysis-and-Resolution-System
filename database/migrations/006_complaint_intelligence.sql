-- Local explainable complaint triage. Run after migration 005; safe to re-run.
USE icars;

CREATE TABLE IF NOT EXISTS complaint_recommendations (
    complaint_id INT UNSIGNED NOT NULL,
    suggestion_json JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    decision_json JSON NULL,
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (complaint_id),
    CONSTRAINT fk_recommendation_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    CONSTRAINT fk_recommendation_actor FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

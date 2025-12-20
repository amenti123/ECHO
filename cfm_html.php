CREATE TABLE IF NOT EXISTS cfm_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT,
    reported_by VARCHAR(255) NOT NULL,
    position VARCHAR(255),
    date_feedback_received DATE NOT NULL,
    date_of_report DATE NOT NULL,
    feedback_type ENUM('new', 'pending') DEFAULT 'new',
    organization VARCHAR(255),
    region_id INT,
    zone_id INT,
    woreda_id INT,
    gender ENUM('Male', 'Female', 'Other'),
    age INT,
    community_type VARCHAR(100),
    vulnerability VARCHAR(100),
    language VARCHAR(100),
    actual_feedback TEXT NOT NULL,
    feedback_channel VARCHAR(100),
    feedback_category VARCHAR(100),
    feedback_concern TEXT,
    feedback_status VARCHAR(50) DEFAULT 'New',
    actions_taken TEXT,
    responsibility_follow_up VARCHAR(255),
    expected_closure_date DATE,
    reason_closure_passed TEXT,
    recommendation TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id),
    FOREIGN KEY (region_id) REFERENCES regions(id),
    FOREIGN KEY (zone_id) REFERENCES zones(id),
    FOREIGN KEY (woreda_id) REFERENCES woredas(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Add sample regions, zones, and woredas if they don't exist
INSERT IGNORE INTO regions (name) VALUES 
('Addis Ababa'), ('Afar'), ('Amhara'), ('Benishangul-Gumuz'), 
('Dire Dawa'), ('Gambela'), ('Harari'), ('Oromia'), 
('Sidama'), ('Somali'), ('South West Ethiopia'), 
('Southern Nations, Nationalities, and Peoples'), ('Tigray');

-- Sample zones for Amhara region
INSERT IGNORE INTO zones (region_id, name) VALUES 
(3, 'North Gondar'), (3, 'South Gondar'), (3, 'North Wollo'), 
(3, 'South Wollo'), (3, 'Oromia Zone'), (3, 'Agew Awi');

-- Sample woredas for North Gondar zone
INSERT IGNORE INTO woredas (zone_id, name) VALUES 
(1, 'Gondar Zuria'), (1, 'Dabat'), (1, 'Debark'), 
(1, 'Addi Arkay'), (1, 'Jan Amora');
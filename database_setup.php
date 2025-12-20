-- Create the geographical tables
CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    region_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_zone_region (name, region_id)
);

CREATE TABLE IF NOT EXISTS woredas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    zone_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    UNIQUE KEY unique_woreda_zone (name, zone_id)
);

-- CFM Reports table (assuming it exists, adding missing columns if needed)
CREATE TABLE IF NOT EXISTS cfm_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT,
    reported_by VARCHAR(255),
    position VARCHAR(255),
    date_feedback_received DATE,
    date_of_report DATE,
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
    actual_feedback TEXT,
    feedback_channel VARCHAR(100),
    feedback_category VARCHAR(100),
    feedback_concern TEXT,
    feedback_status ENUM('New', 'Under Review', 'Action Taken', 'Resolved', 'Closed') DEFAULT 'New',
    actions_taken TEXT,
    responsibility_follow_up VARCHAR(255),
    expected_closure_date DATE,
    reason_closure_passed TEXT,
    recommendation TEXT,
    ai_recommendation TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (region_id) REFERENCES regions(id),
    FOREIGN KEY (zone_id) REFERENCES zones(id),
    FOREIGN KEY (woreda_id) REFERENCES woredas(id)
);

-- Insert default regions from your Excel data
INSERT IGNORE INTO regions (name) VALUES 
('Tigray'), ('Afar'), ('Amhara'), ('Oromia'), ('Somali'), 
('B/Gumuz'), ('Souther E'), ('Central Eth'), ('Sidama'), ('South West'),
('Gambela'), ('Harrari'), ('Dir Dawa'), ('Addis Ababa');
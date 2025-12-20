<?php
/**
 * Grant Management System (GMS)
 * Comprehensive grant lifecycle management aligned with UN agencies, INGOs, and major donors
 * Version: 1.0
 * 
 * Integrates with: projects.php, planning.php, budget.php, indicators, enter_data.php
 */

require_once __DIR__ . '/../helpers.php';
require_login();
$pdo = getPDO();
// --- GMS HARDENING + AUTO-MIGRATIONS (added by upgrade) ---
if (function_exists('ob_get_level') && ob_get_level() === 0) { @ob_start(); }
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
} catch (Exception $e) {
    // ignore
}

if (!function_exists('gms_table_has_column')) {
    function gms_table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (Exception $e) {
            return false;
        }
    }
}
if (!function_exists('gms_try_add_column')) {
    function gms_try_add_column(PDO $pdo, string $table, string $column, string $ddl): void {
        try {
            if (!gms_table_has_column($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
            }
        } catch (Exception $e) {
            // ignore
        }
    }
}
if (!function_exists('gms_try_create_workplan_registers')) {
    function gms_try_create_workplan_registers(PDO $pdo): void {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS gms_workplan_registers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    grant_id INT NOT NULL,
                    register_code VARCHAR(60) NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    period_start DATE NULL,
                    period_end DATE NULL,
                    location_name VARCHAR(200) NULL,
                    responsible_person VARCHAR(200) NULL,
                    responsible_email VARCHAR(200) NULL,
                    status ENUM('Planned','Active','Completed','Closed') DEFAULT 'Active',
                    notes TEXT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_grant_register (grant_id, register_code),
                    INDEX idx_grant (grant_id),
                    CONSTRAINT fk_wpreg_grant FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            // ignore
        }
    }
}
if (!function_exists('gms_run_schema_migrations')) {
    function gms_run_schema_migrations(PDO $pdo): void {
        // 1) Create missing tables
        gms_try_create_workplan_registers($pdo);

        // 2) Work Plans donor-standard fields
        gms_try_add_column($pdo, 'gms_work_plans', 'workplan_register_id', "workplan_register_id INT NULL AFTER grant_id");
        gms_try_add_column($pdo, 'gms_work_plans', 'responsible_person', "responsible_person VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'responsible_email', "responsible_email VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'location_name', "location_name VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'result_output', "result_output TEXT NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'indicator_text', "indicator_text TEXT NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'target_quantity', "target_quantity DECIMAL(15,2) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'target_unit', "target_unit VARCHAR(50) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'means_of_verification', "means_of_verification TEXT NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'dependencies', "dependencies TEXT NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'budget_amount', "budget_amount DECIMAL(15,2) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'budget_currency', "budget_currency VARCHAR(20) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'wbs_code', "wbs_code VARCHAR(60) NULL");
        gms_try_add_column($pdo, 'gms_work_plans', 'activity_sequence', "activity_sequence INT NULL");

        // Try to add FK/index (safe if fails)
        try { $pdo->exec("CREATE INDEX idx_wpreg ON gms_work_plans(workplan_register_id)"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE gms_work_plans ADD CONSTRAINT fk_wp_register FOREIGN KEY (workplan_register_id) REFERENCES gms_workplan_registers(id) ON DELETE SET NULL"); } catch (Exception $e) {}

        // 3) Risks donor-standard fields
        gms_try_add_column($pdo, 'gms_risk_register', 'responsible_person', "responsible_person VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_risk_register', 'risk_owner_name', "risk_owner_name VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_risk_register', 'risk_owner_email', "risk_owner_email VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_risk_register', 'due_date', "due_date DATE NULL");
        gms_try_add_column($pdo, 'gms_risk_register', 'residual_risk_level', "residual_risk_level ENUM('Low','Medium','High','Critical') NULL");
        gms_try_add_column($pdo, 'gms_risk_register', 'contingency_plan', "contingency_plan TEXT NULL");
        gms_try_add_column($pdo, 'gms_risk_register', 'risk_causes', "risk_causes TEXT NULL");
        gms_try_add_column($pdo, 'gms_risk_register', 'notes', "notes TEXT NULL");

        // 4) Compliance donor-standard fields
        gms_try_add_column($pdo, 'gms_compliance_issues', 'responsible_person', "responsible_person VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_compliance_issues', 'responsible_person_name', "responsible_person_name VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_compliance_issues', 'responsible_person_email', "responsible_person_email VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_compliance_issues', 'requirement_reference', "requirement_reference VARCHAR(200) NULL");
        gms_try_add_column($pdo, 'gms_compliance_issues', 'evidence_required', "evidence_required TEXT NULL");
        gms_try_add_column($pdo, 'gms_compliance_issues', 'compliance_area', "compliance_area VARCHAR(120) NULL");
        gms_try_add_column($pdo, 'gms_compliance_issues', 'root_cause', "root_cause TEXT NULL");
        gms_try_add_column($pdo, 'gms_compliance_issues', 'follow_up_date', "follow_up_date DATE NULL");
    }
}

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        gms_run_schema_migrations($pdo);
    }
} catch (Exception $e) {
    // ignore
}
// --- END HARDENING + AUTO-MIGRATIONS ---


// Comprehensive Donor Seeding Function - Make it globally accessible
if (!function_exists('seed_comprehensive_donors')) {
    function seed_comprehensive_donors(?PDO $pdo = null): void {
        if (!$pdo) $pdo = getPDO();
    $donors = [
        // UN Agencies - Starting with EHF/UNOCHA as requested
        ['name' => 'EHF/UNOCHA', 'short_name' => 'EHF/UNOCHA', 'donor_type' => 'Pooled Fund'],
        ['name' => 'UNOCHA - Office for the Coordination of Humanitarian Affairs', 'short_name' => 'UNOCHA', 'donor_type' => 'UN Agency'],
        ['name' => 'UNDP - United Nations Development Programme', 'short_name' => 'UNDP', 'donor_type' => 'UN Agency'],
        ['name' => 'UNICEF - United Nations Children\'s Fund', 'short_name' => 'UNICEF', 'donor_type' => 'UN Agency'],
        ['name' => 'UNHCR - United Nations High Commissioner for Refugees', 'short_name' => 'UNHCR', 'donor_type' => 'UN Agency'],
        ['name' => 'WFP - World Food Programme', 'short_name' => 'WFP', 'donor_type' => 'UN Agency'],
        ['name' => 'WHO - World Health Organization', 'short_name' => 'WHO', 'donor_type' => 'UN Agency'],
        ['name' => 'UNFPA - United Nations Population Fund', 'short_name' => 'UNFPA', 'donor_type' => 'UN Agency'],
        ['name' => 'UNESCO - United Nations Educational, Scientific and Cultural Organization', 'short_name' => 'UNESCO', 'donor_type' => 'UN Agency'],
        ['name' => 'FAO - Food and Agriculture Organization', 'short_name' => 'FAO', 'donor_type' => 'UN Agency'],
        ['name' => 'ILO - International Labour Organization', 'short_name' => 'ILO', 'donor_type' => 'UN Agency'],
        ['name' => 'UN Women - United Nations Entity for Gender Equality', 'short_name' => 'UN Women', 'donor_type' => 'UN Agency'],
        ['name' => 'UNEP - United Nations Environment Programme', 'short_name' => 'UNEP', 'donor_type' => 'UN Agency'],
        ['name' => 'UNIDO - United Nations Industrial Development Organization', 'short_name' => 'UNIDO', 'donor_type' => 'UN Agency'],
        ['name' => 'UN-Habitat - United Nations Human Settlements Programme', 'short_name' => 'UN-Habitat', 'donor_type' => 'UN Agency'],
        ['name' => 'IOM - International Organization for Migration', 'short_name' => 'IOM', 'donor_type' => 'UN Agency'],
        ['name' => 'IFAD - International Fund for Agricultural Development', 'short_name' => 'IFAD', 'donor_type' => 'UN Agency'],
        ['name' => 'ITC - International Trade Centre', 'short_name' => 'ITC', 'donor_type' => 'UN Agency'],
        ['name' => 'ITU - International Telecommunication Union', 'short_name' => 'ITU', 'donor_type' => 'UN Agency'],
        ['name' => 'OHCHR - Office of the High Commissioner for Human Rights', 'short_name' => 'OHCHR', 'donor_type' => 'UN Agency'],
        ['name' => 'UNECA - UN Economic Commission for Africa', 'short_name' => 'UNECA', 'donor_type' => 'UN Agency'],
        ['name' => 'UNAIDS - Joint UN Programme on HIV/AIDS', 'short_name' => 'UNAIDS', 'donor_type' => 'UN Agency'],
        ['name' => 'UNCDF - UN Capital Development Fund', 'short_name' => 'UNCDF', 'donor_type' => 'UN Agency'],
        ['name' => 'UNCTAD - UN Conference on Trade and Development', 'short_name' => 'UNCTAD', 'donor_type' => 'UN Agency'],
        ['name' => 'UNDRR - UN Office for Disaster Risk Reduction', 'short_name' => 'UNDRR', 'donor_type' => 'UN Agency'],
        ['name' => 'UNOAU - UN Office to the African Union', 'short_name' => 'UNOAU', 'donor_type' => 'UN Agency'],
        ['name' => 'UNODC - UN Office on Drugs and Crime', 'short_name' => 'UNODC', 'donor_type' => 'UN Agency'],
        ['name' => 'UNOPS - UN Office for Project Services', 'short_name' => 'UNOPS', 'donor_type' => 'UN Agency'],
        
        // Multilateral Development Banks & Global Funds
        ['name' => 'World Bank Group - IDA', 'short_name' => 'World Bank IDA', 'donor_type' => 'Other'],
        ['name' => 'World Bank Group - IBRD', 'short_name' => 'World Bank IBRD', 'donor_type' => 'Other'],
        ['name' => 'World Bank Group - IFC', 'short_name' => 'World Bank IFC', 'donor_type' => 'Other'],
        ['name' => 'African Development Bank (AfDB)', 'short_name' => 'AfDB', 'donor_type' => 'Other'],
        ['name' => 'European Investment Bank (EIB)', 'short_name' => 'EIB', 'donor_type' => 'Other'],
        ['name' => 'Islamic Development Bank (IsDB)', 'short_name' => 'IsDB', 'donor_type' => 'Other'],
        ['name' => 'European Bank for Reconstruction and Development (EBRD)', 'short_name' => 'EBRD', 'donor_type' => 'Other'],
        ['name' => 'Arab Fund for Economic and Social Development', 'short_name' => 'Arab Fund', 'donor_type' => 'Other'],
        ['name' => 'OPEC Fund for International Development (OFID)', 'short_name' => 'OFID', 'donor_type' => 'Other'],
        ['name' => 'The Global Fund to Fight AIDS, Tuberculosis and Malaria', 'short_name' => 'Global Fund', 'donor_type' => 'Foundation'],
        ['name' => 'Gavi, the Vaccine Alliance', 'short_name' => 'Gavi', 'donor_type' => 'Foundation'],
        ['name' => 'Global Environment Facility (GEF)', 'short_name' => 'GEF', 'donor_type' => 'Foundation'],
        ['name' => 'Green Climate Fund (GCF)', 'short_name' => 'GCF', 'donor_type' => 'Foundation'],
        ['name' => 'Adaptation Fund', 'short_name' => 'Adaptation Fund', 'donor_type' => 'Foundation'],
        ['name' => 'Global Partnership for Education (GPE)', 'short_name' => 'GPE', 'donor_type' => 'Foundation'],
        ['name' => 'Education Cannot Wait (ECW)', 'short_name' => 'ECW', 'donor_type' => 'Foundation'],
        ['name' => 'UN Central Emergency Response Fund (CERF)', 'short_name' => 'CERF', 'donor_type' => 'Pooled Fund'],
        ['name' => 'Country-Based Pooled Funds (CBPF)', 'short_name' => 'CBPF', 'donor_type' => 'Pooled Fund'],
        
        // Major Bilateral Donors
        ['name' => 'USAID - United States Agency for International Development', 'short_name' => 'USAID', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'US Department of State', 'short_name' => 'US State Dept', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'CDC - Centers for Disease Control and Prevention', 'short_name' => 'CDC', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'FCDO - UK Foreign, Commonwealth & Development Office (UK Aid)', 'short_name' => 'FCDO', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'BMZ - German Federal Ministry for Economic Cooperation and Development', 'short_name' => 'BMZ', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'GIZ - Deutsche Gesellschaft für Internationale Zusammenarbeit', 'short_name' => 'GIZ', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'KfW - KfW Development Bank', 'short_name' => 'KfW', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'European Union - European Commission (DG INTPA)', 'short_name' => 'EU DG INTPA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'DG ECHO - European Civil Protection and Humanitarian Aid Operations', 'short_name' => 'ECHO', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Sida - Swedish International Development Cooperation Agency', 'short_name' => 'Sida', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'NORAD - Norwegian Agency for Development Cooperation', 'short_name' => 'NORAD', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Netherlands MFA / RVO', 'short_name' => 'Netherlands MFA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'DANIDA - Danish International Development Agency', 'short_name' => 'DANIDA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'SDC - Swiss Agency for Development and Cooperation', 'short_name' => 'SDC', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Global Affairs Canada', 'short_name' => 'Canada GAC', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'AFD - Agence Française de Développement', 'short_name' => 'AFD', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'AICS - Italian Agency for Development Cooperation', 'short_name' => 'AICS', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'AECID - Spanish Agency for International Development Cooperation', 'short_name' => 'AECID', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Enabel - Belgian Development Agency', 'short_name' => 'Enabel', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Irish Aid', 'short_name' => 'Irish Aid', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Ministry for Foreign Affairs of Finland', 'short_name' => 'Finland MFA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'ADA - Austrian Development Agency', 'short_name' => 'ADA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'JICA - Japan International Cooperation Agency', 'short_name' => 'JICA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'KOICA - Korea International Cooperation Agency', 'short_name' => 'KOICA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'TİKA - Turkish Cooperation and Coordination Agency', 'short_name' => 'TİKA', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Chinese Cooperation Funds', 'short_name' => 'China Funds', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Saudi Fund for Development', 'short_name' => 'Saudi Fund', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Qatar Fund for Development', 'short_name' => 'Qatar Fund', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Kuwait Fund for Arab Economic Development', 'short_name' => 'Kuwait Fund', 'donor_type' => 'Bilateral Donor'],
        ['name' => 'Abu Dhabi Fund for Development', 'short_name' => 'Abu Dhabi Fund', 'donor_type' => 'Bilateral Donor'],
        
        // Major INGOs
        ['name' => 'Save the Children International', 'short_name' => 'Save the Children', 'donor_type' => 'Other'],
        ['name' => 'World Vision International', 'short_name' => 'World Vision', 'donor_type' => 'Other'],
        ['name' => 'Plan International', 'short_name' => 'Plan Intl', 'donor_type' => 'Other'],
        ['name' => 'CARE International', 'short_name' => 'CARE', 'donor_type' => 'Other'],
        ['name' => 'Oxfam', 'short_name' => 'Oxfam', 'donor_type' => 'Other'],
        ['name' => 'Mercy Corps', 'short_name' => 'Mercy Corps', 'donor_type' => 'Other'],
        ['name' => 'International Rescue Committee (IRC)', 'short_name' => 'IRC', 'donor_type' => 'Other'],
        ['name' => 'Norwegian Refugee Council (NRC)', 'short_name' => 'NRC', 'donor_type' => 'Other'],
        ['name' => 'Danish Refugee Council (DRC)', 'short_name' => 'DRC', 'donor_type' => 'Other'],
        ['name' => 'Catholic Relief Services (CRS)', 'short_name' => 'CRS', 'donor_type' => 'Other'],
        ['name' => 'Caritas Internationalis', 'short_name' => 'Caritas', 'donor_type' => 'Other'],
        ['name' => 'World Relief', 'short_name' => 'World Relief', 'donor_type' => 'Other'],
        ['name' => 'Samaritan\'s Purse', 'short_name' => 'Samaritan\'s Purse', 'donor_type' => 'Other'],
        ['name' => 'Médecins Sans Frontières (MSF)', 'short_name' => 'MSF', 'donor_type' => 'Other'],
        ['name' => 'Action Against Hunger (ACF)', 'short_name' => 'ACF', 'donor_type' => 'Other'],
        ['name' => 'HI - Humanity & Inclusion', 'short_name' => 'HI', 'donor_type' => 'Other'],
        ['name' => 'Concern Worldwide', 'short_name' => 'Concern', 'donor_type' => 'Other'],
        ['name' => 'GOAL', 'short_name' => 'GOAL', 'donor_type' => 'Other'],
        ['name' => 'Welthungerhilfe (WHH)', 'short_name' => 'WHH', 'donor_type' => 'Other'],
        ['name' => 'Medair', 'short_name' => 'Medair', 'donor_type' => 'Other'],
        ['name' => 'International Medical Corps (IMC)', 'short_name' => 'IMC', 'donor_type' => 'Other'],
        ['name' => 'ACT Alliance', 'short_name' => 'ACT Alliance', 'donor_type' => 'Other'],
        ['name' => 'Lutheran World Federation (LWF)', 'short_name' => 'LWF', 'donor_type' => 'Other'],
        ['name' => 'Islamic Relief Worldwide', 'short_name' => 'Islamic Relief', 'donor_type' => 'Other'],
        ['name' => 'Norwegian Church Aid (NCA)', 'short_name' => 'NCA', 'donor_type' => 'Other'],
        ['name' => 'PATH', 'short_name' => 'PATH', 'donor_type' => 'Other'],
        ['name' => 'Jhpiego', 'short_name' => 'Jhpiego', 'donor_type' => 'Other'],
        ['name' => 'FHI 360', 'short_name' => 'FHI 360', 'donor_type' => 'Other'],
        ['name' => 'Clinton Health Access Initiative (CHAI)', 'short_name' => 'CHAI', 'donor_type' => 'Other'],
        ['name' => 'Population Services International (PSI)', 'short_name' => 'PSI', 'donor_type' => 'Other'],
        ['name' => 'RTI International', 'short_name' => 'RTI', 'donor_type' => 'Other'],
        ['name' => 'Abt Associates', 'short_name' => 'Abt', 'donor_type' => 'Other'],
        ['name' => 'WaterAid', 'short_name' => 'WaterAid', 'donor_type' => 'Other'],
        
        // Ethiopian NNGOs (Sample)
        ['name' => 'Nexus Ethiopia - Nexus of National Humanitarian Actors', 'short_name' => 'Nexus Ethiopia', 'donor_type' => 'Other'],
        ['name' => 'MCMDO - Mothers and Children Multisectoral Development Organization', 'short_name' => 'MCMDO', 'donor_type' => 'Other'],
        ['name' => 'ORDA Ethiopia - Organization for Rehabilitation and Development in Amhara', 'short_name' => 'ORDA', 'donor_type' => 'Other'],
        ['name' => 'Kelem Ethiopia', 'short_name' => 'Kelem', 'donor_type' => 'Other'],
        ['name' => 'Mahibere Hiwot for Social Development', 'short_name' => 'Mahibere Hiwot', 'donor_type' => 'Other'],
        ['name' => 'Mekdim Ethiopia National Association', 'short_name' => 'Mekdim', 'donor_type' => 'Other'],
        ['name' => 'Pro Pride', 'short_name' => 'Pro Pride', 'donor_type' => 'Other'],
        ['name' => 'Hope for Children Organization of Ethiopia', 'short_name' => 'Hope for Children', 'donor_type' => 'Other'],
        ['name' => 'WE-Action - Women Empowerment–Action', 'short_name' => 'WE-Action', 'donor_type' => 'Other'],
        ['name' => 'CRDA / CCRDA - Christian Relief and Development Association', 'short_name' => 'CRDA', 'donor_type' => 'Other'],
        ['name' => 'OSSHD - Organization for Social Services, Health and Development', 'short_name' => 'OSSHD', 'donor_type' => 'Other'],
        ['name' => 'EOTC–DICAC - Ethiopian Orthodox Church Development and Inter-Church Aid Commission', 'short_name' => 'EOTC-DICAC', 'donor_type' => 'Other'],
        ['name' => 'MCDP - Mission for Community Development Programme', 'short_name' => 'MCDP', 'donor_type' => 'Other'],
        ['name' => 'MCDO - Mother and Child Development Organization', 'short_name' => 'MCDO', 'donor_type' => 'Other'],
        ['name' => 'Mums for Mums', 'short_name' => 'Mums for Mums', 'donor_type' => 'Other'],
        ['name' => 'Peace and Development Center (PDC)', 'short_name' => 'PDC', 'donor_type' => 'Other'],
        ['name' => 'Forum for Environment', 'short_name' => 'Forum for Environment', 'donor_type' => 'Other'],
        ['name' => 'EWNHS - Ethiopia Wildlife and Natural History Society', 'short_name' => 'EWNHS', 'donor_type' => 'Other'],
        ['name' => 'Ayzon Foundation', 'short_name' => 'Ayzon', 'donor_type' => 'Foundation'],
        ['name' => 'Shamida Ethiopia', 'short_name' => 'Shamida', 'donor_type' => 'Other'],
        
        // Major International Foundations
        ['name' => 'Bill & Melinda Gates Foundation (BMGF)', 'short_name' => 'Gates Foundation', 'donor_type' => 'Foundation'],
        ['name' => 'Children\'s Investment Fund Foundation (CIFF)', 'short_name' => 'CIFF', 'donor_type' => 'Foundation'],
        ['name' => 'Wellcome Trust', 'short_name' => 'Wellcome', 'donor_type' => 'Foundation'],
        ['name' => 'Ford Foundation', 'short_name' => 'Ford Foundation', 'donor_type' => 'Foundation'],
        ['name' => 'Rockefeller Foundation', 'short_name' => 'Rockefeller', 'donor_type' => 'Foundation'],
        ['name' => 'Open Society Foundations (OSF)', 'short_name' => 'Open Society', 'donor_type' => 'Foundation'],
        ['name' => 'Conrad N. Hilton Foundation', 'short_name' => 'Hilton Foundation', 'donor_type' => 'Foundation'],
        ['name' => 'ELMA Foundation', 'short_name' => 'ELMA', 'donor_type' => 'Foundation'],
        ['name' => 'IKEA Foundation', 'short_name' => 'IKEA Foundation', 'donor_type' => 'Foundation'],
        ['name' => 'Mastercard Foundation', 'short_name' => 'Mastercard Foundation', 'donor_type' => 'Foundation'],
        ['name' => 'Skoll Foundation', 'short_name' => 'Skoll', 'donor_type' => 'Foundation'],
        ['name' => 'MacArthur Foundation', 'short_name' => 'MacArthur', 'donor_type' => 'Foundation'],
        ['name' => 'Hewlett Foundation', 'short_name' => 'Hewlett', 'donor_type' => 'Foundation'],
        ['name' => 'Packard Foundation', 'short_name' => 'Packard', 'donor_type' => 'Foundation'],
        ['name' => 'La Caixa Foundation', 'short_name' => 'La Caixa', 'donor_type' => 'Foundation'],
        ['name' => 'Dubai Cares', 'short_name' => 'Dubai Cares', 'donor_type' => 'Foundation'],
        ['name' => 'Qatar Foundation', 'short_name' => 'Qatar Foundation', 'donor_type' => 'Foundation'],
        ['name' => 'Mohamed Bin Zayed Foundation for Humanity', 'short_name' => 'MBZ Foundation', 'donor_type' => 'Foundation'],
    ];
    
    // Donor currency mapping
    $donorCurrencies = [
        'EHF/UNOCHA' => 'USD', 'UNOCHA' => 'USD', 'UNDP' => 'USD', 'UNICEF' => 'USD', 'UNHCR' => 'USD',
        'WFP' => 'USD', 'WHO' => 'USD', 'UNFPA' => 'USD', 'UNESCO' => 'USD', 'FAO' => 'USD',
        'ILO' => 'USD', 'UN Women' => 'USD', 'UNEP' => 'USD', 'UNIDO' => 'USD', 'UN-Habitat' => 'USD',
        'IOM' => 'USD', 'IFAD' => 'USD', 'ITC' => 'USD', 'ITU' => 'USD', 'OHCHR' => 'USD',
        'UNECA' => 'USD', 'UNAIDS' => 'USD', 'UNCDF' => 'USD', 'UNCTAD' => 'USD', 'UNDRR' => 'USD',
        'UNOAU' => 'USD', 'UNODC' => 'USD', 'UNOPS' => 'USD', 'CERF' => 'USD', 'CBPF' => 'USD',
        'USAID' => 'USD', 'US State Dept' => 'USD', 'CDC' => 'USD',
        'FCDO' => 'GBP', 'BMZ' => 'EUR', 'GIZ' => 'EUR', 'KfW' => 'EUR', 'EU DG INTPA' => 'EUR',
        'ECHO' => 'EUR', 'Sida' => 'SEK', 'NORAD' => 'NOK', 'Netherlands MFA' => 'EUR',
        'DANIDA' => 'DKK', 'SDC' => 'CHF', 'Canada GAC' => 'CAD', 'AFD' => 'EUR', 'AICS' => 'EUR',
        'AECID' => 'EUR', 'Enabel' => 'EUR', 'Irish Aid' => 'EUR', 'Finland MFA' => 'EUR', 'ADA' => 'EUR',
        'JICA' => 'JPY', 'KOICA' => 'KRW', 'TİKA' => 'TRY', 'China Funds' => 'CNY',
        'Saudi Fund' => 'SAR', 'Qatar Fund' => 'QAR', 'Kuwait Fund' => 'KWD', 'Abu Dhabi Fund' => 'AED',
    ];
    
    // First, ensure currency column exists
    try {
        $pdo->exec("ALTER TABLE gms_donors ADD COLUMN IF NOT EXISTS currency VARCHAR(10) DEFAULT 'USD'");
    } catch (PDOException $e) {
        // Column may already exist, ignore
    }
    
    // Ensure unique constraint on name exists (check first to avoid error)
    try {
        $checkStmt = $pdo->query("SHOW INDEX FROM gms_donors WHERE Key_name = 'unique_donor_name'");
        if ($checkStmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE gms_donors ADD UNIQUE KEY unique_donor_name (name)");
        }
    } catch (PDOException $e) {
        // Constraint may already exist or table doesn't exist yet, ignore
    }
    
    // Use INSERT ... ON DUPLICATE KEY UPDATE to ensure all donors are present and updated
    // If unique constraint doesn't exist, use INSERT IGNORE as fallback
    try {
        $stmt = $pdo->prepare("INSERT INTO gms_donors (name, short_name, donor_type, currency, is_active) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE short_name = VALUES(short_name), donor_type = VALUES(donor_type), currency = VALUES(currency), is_active = 1");
    } catch (PDOException $e) {
        // Fallback to INSERT IGNORE if ON DUPLICATE KEY doesn't work
        $stmt = $pdo->prepare("INSERT IGNORE INTO gms_donors (name, short_name, donor_type, currency, is_active) VALUES (?, ?, ?, ?, 1)");
    }
    $inserted = 0;
    foreach ($donors as $donor) {
        $shortName = $donor['short_name'] ?? '';
        $currency = $donorCurrencies[$shortName] ?? 'USD';
        try {
            $stmt->execute([$donor['name'], $shortName, $donor['donor_type'], $currency]);
            $inserted++;
        } catch (PDOException $e) {
            error_log("Error inserting donor " . $donor['name'] . ": " . $e->getMessage());
        }
    }
    error_log("Grants: Seeded/updated $inserted donors in gms_donors table");
    }
}

// Initialize Grant Management System database schema
function init_grant_management_schema(PDO $pdo): void {
    $tables = [
        // 1. Donors & Funding Opportunities
        "CREATE TABLE IF NOT EXISTS gms_donors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            short_name VARCHAR(100),
            donor_type ENUM('UN Agency', 'Bilateral Donor', 'Pooled Fund', 'Foundation', 'Corporate', 'Government', 'Other') NOT NULL,
            contact_name VARCHAR(255),
            contact_email VARCHAR(255),
            contact_phone VARCHAR(50),
            website VARCHAR(255),
            address TEXT,
            country VARCHAR(100),
            notes TEXT,
            currency VARCHAR(10) DEFAULT 'USD',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_donor_type (donor_type),
            INDEX idx_active (is_active),
            INDEX idx_currency (currency)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS gms_funding_opportunities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            donor_id INT NOT NULL,
            opportunity_code VARCHAR(100) UNIQUE,
            title VARCHAR(500) NOT NULL,
            description TEXT,
            opportunity_type ENUM('CFP', 'EOI', 'RfP', 'RfQ', 'RFA', 'Other') DEFAULT 'CFP',
            announcement_date DATE,
            funding_window_start DATE,
            funding_window_end DATE,
            submission_deadline DATETIME,
            opportunity_website VARCHAR(500),
            call_summary TEXT,
            estimated_budget_min DECIMAL(15,2),
            estimated_budget_max DECIMAL(15,2),
            currency VARCHAR(10) DEFAULT 'USD',
            thematic_areas JSON,
            eligible_countries JSON,
            status ENUM('Open', 'Closed', 'Under Review', 'Awarded', 'Cancelled') DEFAULT 'Open',
            assigned_to_user_id INT,
            responsible_person_name VARCHAR(255),
            responsible_person_position VARCHAR(255),
            responsible_person_email VARCHAR(255),
            go_no_go_decision ENUM('Go', 'No-Go', 'Pending') DEFAULT 'Pending',
            go_no_go_date DATE,
            go_no_go_notes TEXT,
            last_notification_date DATE,
            days_remaining INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (donor_id) REFERENCES gms_donors(id) ON DELETE RESTRICT,
            INDEX idx_donor (donor_id),
            INDEX idx_status (status),
            INDEX idx_deadline (submission_deadline),
            INDEX idx_opportunity_type (opportunity_type),
            INDEX idx_announcement_date (announcement_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 2. Grant Master & Agreements
        "CREATE TABLE IF NOT EXISTS gms_grants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_code VARCHAR(100) UNIQUE NOT NULL,
            grant_number VARCHAR(100),
            title VARCHAR(500) NOT NULL,
            donor_id INT NOT NULL,
            opportunity_id INT,
            project_id INT,
            agreement_type ENUM('Grant', 'Cooperative Agreement', 'Contract', 'Sub-grant', 'Other') DEFAULT 'Grant',
            status ENUM('Pipeline', 'Awarded', 'Active', 'Suspended', 'Closed', 'Terminated') DEFAULT 'Pipeline',
            start_date DATE,
            end_date DATE,
            original_end_date DATE,
            total_budget DECIMAL(15,2) NOT NULL DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'USD',
            exchange_rate DECIMAL(10,4) DEFAULT 1.0,
            co_funding_required DECIMAL(15,2) DEFAULT 0,
            co_funding_secured DECIMAL(15,2) DEFAULT 0,
            thematic_areas JSON,
            locations JSON,
            implementing_mode ENUM('Direct', 'Partnership', 'Mixed') DEFAULT 'Direct',
            reporting_frequency ENUM('Monthly', 'Quarterly', 'Semi-Annual', 'Annual', 'Ad-hoc') DEFAULT 'Quarterly',
            reporting_deadline_days INT DEFAULT 30,
            special_conditions TEXT,
            risk_level ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
            created_by_user_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (donor_id) REFERENCES gms_donors(id) ON DELETE RESTRICT,
            FOREIGN KEY (opportunity_id) REFERENCES gms_funding_opportunities(id) ON DELETE SET NULL,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
            INDEX idx_donor (donor_id),
            INDEX idx_project (project_id),
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS gms_grant_agreements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            agreement_number VARCHAR(100),
            agreement_type ENUM('Main Agreement', 'Amendment', 'Addendum', 'Modification') DEFAULT 'Main Agreement',
            signed_date DATE,
            effective_date DATE,
            document_path VARCHAR(500),
            key_terms JSON,
            payment_schedule JSON,
            special_clauses TEXT,
            compliance_requirements JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            INDEX idx_grant (grant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 3. Budget & Financial Tracking
        "CREATE TABLE IF NOT EXISTS gms_grant_budgets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            budget_line_code VARCHAR(50),
            budget_category ENUM('Personnel', 'Travel', 'Equipment', 'Supplies', 'Services', 'Infrastructure', 'Overhead', 'Other') NOT NULL,
            description TEXT,
            budget_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            committed_amount DECIMAL(15,2) DEFAULT 0,
            spent_amount DECIMAL(15,2) DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'USD',
            linked_to_chart_of_accounts VARCHAR(100),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            INDEX idx_grant (grant_id),
            INDEX idx_category (budget_category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS gms_grant_disbursements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            disbursement_number VARCHAR(100),
            disbursement_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'USD',
            exchange_rate DECIMAL(10,4) DEFAULT 1.0,
            amount_local DECIMAL(15,2),
            payment_tranche VARCHAR(50),
            received_date DATE,
            bank_reference VARCHAR(100),
            status ENUM('Expected', 'Received', 'Pending', 'Delayed') DEFAULT 'Expected',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            INDEX idx_grant (grant_id),
            INDEX idx_date (disbursement_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 4. Work Plans & Procurement Plans
        "CREATE TABLE IF NOT EXISTS gms_work_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            activity_code VARCHAR(50),
            activity_name VARCHAR(500) NOT NULL,
            description TEXT,
            output_id INT,
            indicator_id INT,
            responsible_user_id INT,
            start_date DATE,
            end_date DATE,
            status ENUM('Planned', 'In Progress', 'Completed', 'Delayed', 'Cancelled') DEFAULT 'Planned',
            progress_percentage DECIMAL(5,2) DEFAULT 0,
            budget_line_id INT,
            location_id INT,
            location_type ENUM('Region', 'Zone', 'Woreda') DEFAULT 'Woreda',
            milestone_date DATE,
            deliverable_description TEXT,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            FOREIGN KEY (budget_line_id) REFERENCES gms_grant_budgets(id) ON DELETE SET NULL,
            INDEX idx_grant (grant_id),
            INDEX idx_status (status),
            INDEX idx_dates (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS gms_procurement_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            procurement_code VARCHAR(50),
            item_description TEXT NOT NULL,
            category ENUM('Goods', 'Services', 'Works', 'Consultancy') NOT NULL,
            quantity DECIMAL(10,2) DEFAULT 1,
            unit VARCHAR(50),
            estimated_cost DECIMAL(15,2),
            budget_line_id INT,
            procurement_method ENUM('Open Tender', 'Limited Tender', 'Direct Procurement', 'Framework Agreement', 'Other') DEFAULT 'Open Tender',
            planned_date DATE,
            status ENUM('Planned', 'In Progress', 'Awarded', 'Completed', 'Cancelled') DEFAULT 'Planned',
            supplier_name VARCHAR(255),
            contract_number VARCHAR(100),
            contract_date DATE,
            contract_amount DECIMAL(15,2),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            FOREIGN KEY (budget_line_id) REFERENCES gms_grant_budgets(id) ON DELETE SET NULL,
            INDEX idx_grant (grant_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 5. Partners & Sub-grants
        "CREATE TABLE IF NOT EXISTS gms_partners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_code VARCHAR(50) UNIQUE,
            name VARCHAR(255) NOT NULL,
            legal_name VARCHAR(255),
            registration_number VARCHAR(100),
            partner_type ENUM('NGO', 'CBO', 'Government', 'Private Sector', 'Academic', 'Other') NOT NULL,
            contact_person VARCHAR(255),
            contact_email VARCHAR(255),
            contact_phone VARCHAR(50),
            address TEXT,
            country VARCHAR(100),
            thematic_expertise JSON,
            geographic_coverage JSON,
            due_diligence_status ENUM('Not Started', 'In Progress', 'Completed', 'Failed', 'Expired') DEFAULT 'Not Started',
            due_diligence_date DATE,
            due_diligence_score INT,
            capacity_assessment_status ENUM('Pending', 'Completed', 'In Progress') DEFAULT 'Pending',
            capacity_assessment_date DATE,
            risk_rating ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
            is_active TINYINT(1) DEFAULT 1,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_type (partner_type),
            INDEX idx_status (due_diligence_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS gms_sub_grants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            partner_id INT NOT NULL,
            sub_grant_number VARCHAR(100) UNIQUE,
            sub_grant_title VARCHAR(500),
            agreement_date DATE,
            start_date DATE,
            end_date DATE,
            sub_grant_amount DECIMAL(15,2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'USD',
            disbursement_schedule JSON,
            status ENUM('Draft', 'Signed', 'Active', 'Suspended', 'Closed', 'Terminated') DEFAULT 'Draft',
            reporting_requirements JSON,
            monitoring_schedule JSON,
            special_conditions TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            FOREIGN KEY (partner_id) REFERENCES gms_partners(id) ON DELETE RESTRICT,
            INDEX idx_grant (grant_id),
            INDEX idx_partner (partner_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 6. Reporting & Deliverables
        "CREATE TABLE IF NOT EXISTS gms_reporting_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            report_type ENUM('Narrative', 'Financial', 'Combined', 'Interim', 'Final', 'Audit') NOT NULL,
            report_number INT,
            due_date DATE NOT NULL,
            submission_date DATE,
            status ENUM('Pending', 'Draft', 'Submitted', 'Approved', 'Rejected', 'Overdue') DEFAULT 'Pending',
            assigned_to_user_id INT,
            template_path VARCHAR(500),
            document_path VARCHAR(500),
            donor_feedback TEXT,
            last_notification_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            INDEX idx_grant (grant_id),
            INDEX idx_due_date (due_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 7. Risk & Compliance
        "CREATE TABLE IF NOT EXISTS gms_risk_register (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            risk_code VARCHAR(50),
            risk_category ENUM('Programmatic', 'Financial', 'Compliance', 'Reputational', 'Operational', 'Safeguarding') NOT NULL,
            risk_description TEXT NOT NULL,
            likelihood ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
            impact ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
            risk_level ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
            mitigation_measures TEXT,
            responsible_user_id INT,
            status ENUM('Open', 'Mitigated', 'Closed', 'Escalated') DEFAULT 'Open',
            review_date DATE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            INDEX idx_grant (grant_id),
            INDEX idx_status (status),
            INDEX idx_level (risk_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS gms_compliance_issues (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            issue_code VARCHAR(50),
            issue_type ENUM('Audit Finding', 'Spot Check Finding', 'Site Visit Finding', 'Donor Query', 'Internal Review', 'Other') NOT NULL,
            description TEXT NOT NULL,
            severity ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
            identified_date DATE NOT NULL,
            responsible_user_id INT,
            corrective_action TEXT,
            target_resolution_date DATE,
            actual_resolution_date DATE,
            status ENUM('Open', 'In Progress', 'Resolved', 'Closed') DEFAULT 'Open',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            INDEX idx_grant (grant_id),
            INDEX idx_status (status),
            INDEX idx_severity (severity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 8. Logframe & Indicators Linkage
        "CREATE TABLE IF NOT EXISTS gms_grant_indicators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT NOT NULL,
            indicator_id INT,
            indicator_code VARCHAR(50),
            indicator_name VARCHAR(500),
            indicator_level ENUM('Impact', 'Outcome', 'Output', 'Activity') DEFAULT 'Output',
            baseline_value DECIMAL(15,2),
            target_value DECIMAL(15,2),
            current_value DECIMAL(15,2),
            unit_type VARCHAR(50),
            data_source TEXT,
            means_of_verification TEXT,
            reporting_frequency ENUM('Monthly', 'Quarterly', 'Semi-Annual', 'Annual') DEFAULT 'Quarterly',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (grant_id) REFERENCES gms_grants(id) ON DELETE CASCADE,
            INDEX idx_grant (grant_id),
            INDEX idx_indicator (indicator_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // 9. Document Management
        "CREATE TABLE IF NOT EXISTS gms_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grant_id INT,
            document_type ENUM('Proposal', 'Agreement', 'Amendment', 'Report', 'Correspondence', 'Other') NOT NULL,
            document_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INT,
            mime_type VARCHAR(100),
            version VARCHAR(20),
            uploaded_by_user_id INT,
            uploaded_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            description TEXT,
            is_confidential TINYINT(1) DEFAULT 0,
            INDEX idx_grant (grant_id),
            INDEX idx_type (document_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($tables as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("GMS Schema Error: " . $e->getMessage());
        }
    }
}

// Initialize schema on page load
init_grant_management_schema($pdo);

// Force seed comprehensive donors - ensure all donors are in database
try {
    // Always seed to ensure all donors are available
    seed_comprehensive_donors($pdo);
    error_log("Grants: Donors seeded/updated successfully");
} catch (Exception $e) {
    error_log("Error seeding donors: " . $e->getMessage());
}

/**
 * XLSX SIMPLE PARSER (first sheet only)
 * Parses Excel XLSX files to array of rows
 */
function parseXLSXToRows($filePath) {
    $rows = [];
    if (!class_exists('ZipArchive')) {
        return $rows;
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        return $rows;
    }

    $sharedStrings = [];
    $sharedIndex   = $zip->locateName('xl/sharedStrings.xml');
    if ($sharedIndex !== false) {
        $xml = simplexml_load_string($zip->getFromIndex($sharedIndex));
        foreach ($xml->si as $i => $si) {
            $t = '';
            if (isset($si->t)) {
                $t = (string)$si->t;
            } elseif (isset($si->r)) {
                foreach ($si->r as $run) {
                    $t .= (string)$run->t;
                }
            }
            $sharedStrings[(int)$i] = $t;
        }
    }

    $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml');
    if ($sheetIndex === false) {
        $zip->close();
        return $rows;
    }

    $xml = simplexml_load_string($zip->getFromIndex($sheetIndex));
    if (!$xml || !isset($xml->sheetData)) {
        $zip->close();
        return $rows;
    }

    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        foreach ($row->c as $c) {
            $v    = isset($c->v) ? (string)$c->v : '';
            $type = isset($c['t']) ? (string)$c['t'] : '';
            if ($type === 's' && $v !== '' && isset($sharedStrings[(int)$v])) {
                $rowData[] = $sharedStrings[(int)$v];
            } else {
                $rowData[] = $v;
            }
        }
        if (!empty($rowData)) {
            $rows[] = $rowData;
        }
    }

    $zip->close();
    return $rows;
}

// Ensure last_notification_date column exists
try {
    $pdo->exec("ALTER TABLE gms_reporting_schedule ADD COLUMN IF NOT EXISTS last_notification_date DATE");
} catch (PDOException $e) {
    // Column may already exist
}

// Upgrade gms_funding_opportunities table with new fields
try {
    $pdo->exec("ALTER TABLE gms_funding_opportunities 
        ADD COLUMN IF NOT EXISTS proposal_status ENUM('Not Started', 'In Progress', 'Failed to Submit', 'Deadline Passed', 'Submitted', 'Under Review', 'Waiting for Response', 'Declined', 'Failed', 'Succeeded') DEFAULT 'Not Started',
        ADD COLUMN IF NOT EXISTS ai_summary TEXT,
        ADD COLUMN IF NOT EXISTS currency_other VARCHAR(50),
        ADD COLUMN IF NOT EXISTS donor_other VARCHAR(255),
        ADD COLUMN IF NOT EXISTS days_remaining_calculated INT,
        ADD COLUMN IF NOT EXISTS deadline_passed TINYINT(1) DEFAULT 0,
        ADD COLUMN IF NOT EXISTS notification_sent TINYINT(1) DEFAULT 0");
} catch (PDOException $e) {
    // Columns may already exist
    error_log("Opportunity table upgrade: " . $e->getMessage());
}



// Ensure extra columns for Work Plans / Risks / Compliance (standard donor templates)
try { $pdo->exec("ALTER TABLE gms_work_plans ADD COLUMN IF NOT EXISTS responsible_person VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE gms_work_plans ADD COLUMN IF NOT EXISTS location_name VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE gms_risk_register ADD COLUMN IF NOT EXISTS responsible_person VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE gms_compliance_issues ADD COLUMN IF NOT EXISTS responsible_person VARCHAR(255) NULL"); } catch (PDOException $e) {}

// Create opportunity files table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gms_opportunity_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opportunity_id INT NOT NULL,
        file_type ENUM('opportunity_document', 'proposal_document') DEFAULT 'opportunity_document',
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size INT,
        mime_type VARCHAR(100),
        uploaded_by_user_id INT,
        uploaded_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        description TEXT,
        FOREIGN KEY (opportunity_id) REFERENCES gms_funding_opportunities(id) ON DELETE CASCADE,
        INDEX idx_opportunity (opportunity_id),
        INDEX idx_file_type (file_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log("Opportunity files table: " . $e->getMessage());
}

// Create opportunity comments table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gms_opportunity_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        opportunity_id INT NOT NULL,
        parent_comment_id INT NULL,
        commenter_user_id INT NOT NULL,
        commenter_name VARCHAR(255) NOT NULL,
        commenter_email VARCHAR(255),
        commenter_position VARCHAR(255),
        comment_text TEXT NOT NULL,
        is_internal TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (opportunity_id) REFERENCES gms_funding_opportunities(id) ON DELETE CASCADE,
        FOREIGN KEY (parent_comment_id) REFERENCES gms_opportunity_comments(id) ON DELETE CASCADE,
        INDEX idx_opportunity (opportunity_id),
        INDEX idx_parent (parent_comment_id),
        INDEX idx_commenter (commenter_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    error_log("Opportunity comments table: " . $e->getMessage());
}

// Helper function to get project staff emails
function get_project_staff_emails(PDO $pdo, $project_id) {
    $emails = [];
    try {
        $stmt = $pdo->prepare("SELECT PM_email, ed_email, finance_head_email, project_officer_email, opm_email, merl_email 
                               FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($project) {
            if (!empty($project['PM_email'])) $emails['Program Manager'] = $project['PM_email'];
            if (!empty($project['ed_email'])) $emails['Executive Director'] = $project['ed_email'];
            if (!empty($project['finance_head_email'])) $emails['Finance Head'] = $project['finance_head_email'];
            if (!empty($project['project_officer_email'])) $emails['Project Coordinator'] = $project['project_officer_email'];
            if (!empty($project['opm_email'])) $emails['Operations Manager'] = $project['opm_email'];
            if (!empty($project['merl_email'])) $emails['MEAL Manager'] = $project['merl_email'];
        }
    } catch (Exception $e) {
        error_log("Error getting project staff emails: " . $e->getMessage());
    }
    return $emails;
}

// Enhanced function to send email notifications to all project staff
function send_grant_notification_enhanced(PDO $pdo, $grant_id, $subject, $message, $report_id = null) {
    $sent = 0;
    $currentUser = current_user();
    $fromEmail = $currentUser['email'] ?? 'noreply@system.local';
    $fromName = $currentUser['name'] ?? $currentUser['full_name'] ?? 'Grant Management System';
    
    // Get grant details
    $stmt = $pdo->prepare("SELECT g.*, p.id as project_id FROM gms_grants g LEFT JOIN projects p ON g.project_id = p.id WHERE g.id = ?");
    $stmt->execute([$grant_id]);
    $grant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get all project staff emails from projects.php
    $emails = [];
    if ($grant && $grant['project_id']) {
        $stmt = $pdo->prepare("SELECT PM_email, ed_email, finance_head_email, project_officer_email, opm_email, merl_email, owner_name, owner_email FROM projects WHERE id = ?");
        $stmt->execute([$grant['project_id']]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($project) {
            if (!empty($project['PM_email'])) $emails['Program Manager'] = $project['PM_email'];
            if (!empty($project['ed_email'])) $emails['Executive Director'] = $project['ed_email'];
            if (!empty($project['finance_head_email'])) $emails['Finance Head'] = $project['finance_head_email'];
            if (!empty($project['project_officer_email'])) $emails['Project Coordinator'] = $project['project_officer_email'];
            if (!empty($project['opm_email'])) $emails['Operations Manager'] = $project['opm_email'];
            if (!empty($project['merl_email'])) $emails['MEAL Manager'] = $project['merl_email'];
            if (!empty($project['owner_email'])) $emails['Project Owner'] = $project['owner_email'];
        }
    }
    
    // Also get grant owner if set
    if ($grant && !empty($grant['created_by_user_id'])) {
        $stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
        $stmt->execute([$grant['created_by_user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && !empty($user['email'])) {
            $emails['Grant Manager'] = $user['email'];
        }
    }
    
    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    
    foreach ($emails as $role => $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $htmlMessage = "<html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;color:#333;} .header{background:#667eea;color:white;padding:20px;border-radius:5px;} .content{padding:20px;background:#f9f9f9;border-radius:5px;margin:20px 0;} .footer{color:#666;font-size:12px;margin-top:20px;}</style></head><body>";
            $htmlMessage .= "<div class='header'><h2>🏦 Grant Management System</h2><h3>$subject</h3></div>";
            $htmlMessage .= "<p>Dear $role,</p>";
            $htmlMessage .= "<div class='content'>";
            $htmlMessage .= nl2br(htmlspecialchars($message));
            if ($report_id) {
                $htmlMessage .= "<p><strong>Report ID:</strong> RPT-" . str_pad($report_id, 6, '0', STR_PAD_LEFT) . "</p>";
            }
            $htmlMessage .= "</div>";
            $htmlMessage .= "<div class='footer'><p><em>This is an automated notification from the Grant Management System.</em></p>";
            $htmlMessage .= "<p>Grant: " . htmlspecialchars($grant['grant_code'] ?? 'N/A') . " - " . htmlspecialchars($grant['title'] ?? 'N/A') . "</p>";
            $htmlMessage .= "<p>Please do not reply to this email.</p></div>";
            $htmlMessage .= "
<script>
// --- Server-side Export/Import overrides (no Dompdf required; Print->Save as PDF) ---
(function(){
  function getView(){
    const p = new URLSearchParams(window.location.search);
    return p.get('view') || 'grants';
  }
  function getGrantId(){
    const p = new URLSearchParams(window.location.search);
    return p.get('grant_id') || '';
  }
  function viewToModule(view){
    if (view === 'dashboard') return 'grants';
    if (view === 'grants') return 'grants';
    if (view === 'donors') return 'donors';
    if (view === 'opportunities') return 'opportunities';
    if (view === 'workplans') return 'workplans';
    if (view === 'partners') return 'partners';
    if (view === 'budgets') return 'budgets';
    if (view === 'reports') return 'reporting';
    if (view === 'risks') return 'risks';
    return view;
  }

  window.downloadTemplate = function(module){
    window.location.href = '?action=export_template&module=' + encodeURIComponent(module);
  };

  window.importTemplate = function(module){
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.csv,.tsv,.txt,.xlsx,.xls';
    input.onchange = function(e){
      const file = e.target.files[0];
      if (!file) return;
      const formData = new FormData();
      formData.append('module', module);
      formData.append('action', 'import_' + (module || 'grants'));
      formData.append('file', file);
      fetch('?action=import&module=' + encodeURIComponent(module), {
        method: 'POST',
        body: formData,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      })
      .then(r => r.json())
      .then(d => {
        if (d && d.success) {
          showNotification('Successfully imported ' + (d.count || 0) + ' records!', 'success');
          setTimeout(()=>window.location.reload(), 800);
        } else {
          showNotification((d && d.message) ? d.message : 'Import failed', 'error');
        }
      })
      .catch(err => showNotification('Import failed: ' + err, 'error'));
    };
    input.click();
  };

  window.exportDashboard = function(format){
    const view = getView();
    const module = viewToModule(view);
    const grant_id = getGrantId();
    const fmt = (format === 'excel') ? 'excel' : (format === 'pdf' ? 'pdf' : 'print');
    const url = '?action=export&module=' + encodeURIComponent(module) + '&format=' + encodeURIComponent(fmt) + (grant_id ? '&grant_id=' + encodeURIComponent(grant_id) : '');
    window.location.href = url;
  };

  window.exportTable = function(tableId, format){
    exportDashboard(format);
  };

  window.printSection = function(sectionId){
    exportDashboard('print');
  };
})();
</script>

</body></html>";
            
            if (@mail($email, $subject, $htmlMessage, implode("\r\n", $headers))) {
                $sent++;
            }
        }
    }
    return $sent;
}

// Legacy function for backward compatibility
function send_grant_notification($to_emails, $subject, $message) {
    $sent = 0;
    $currentUser = current_user();
    $fromEmail = $currentUser['email'] ?? 'noreply@system.local';
    $fromName = $currentUser['name'] ?? $currentUser['full_name'] ?? 'Grant Management System';
    
    $headers = [];
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    
    foreach ($to_emails as $role => $email) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $htmlMessage = "<html><body>";
            $htmlMessage .= "<h2>$subject</h2>";
            $htmlMessage .= "<p>Dear $role,</p>";
            $htmlMessage .= "<div style='padding: 15px; background: #f5f5f5; border-radius: 5px; margin: 15px 0;'>";
            $htmlMessage .= nl2br(htmlspecialchars($message));
            $htmlMessage .= "</div>";
            $htmlMessage .= "<p><em>This is an automated notification from the Grant Management System.</em></p>";
            $htmlMessage .= "</body></html>";
            
            if (@mail($email, $subject, $htmlMessage, implode("\r\n", $headers))) {
                $sent++;
            }
        }
    }
    return $sent;
}

// Enhanced function to check and send report deadline notifications
function check_report_deadlines(PDO $pdo) {
    $today = date('Y-m-d');
    $nextWeek = date('Y-m-d', strtotime('+7 days'));
    $nextMonth = date('Y-m-d', strtotime('+30 days'));
    
    try {
        // Get reports due in next 7 days
        $stmt = $pdo->prepare("
            SELECT rs.*, g.grant_code, g.title AS grant_title, g.project_id, g.id as grant_id, d.name AS donor_name
            FROM gms_reporting_schedule rs
            JOIN gms_grants g ON rs.grant_id = g.id
            LEFT JOIN gms_donors d ON g.donor_id = d.id
            WHERE rs.status IN ('Pending', 'Draft')
            AND rs.due_date BETWEEN ? AND ?
            AND (rs.last_notification_date IS NULL OR rs.last_notification_date < DATE_SUB(rs.due_date, INTERVAL 3 DAY))
        ");
        $stmt->execute([$today, $nextWeek]);
        $upcomingReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($upcomingReports as $report) {
            $daysUntilDue = (strtotime($report['due_date']) - strtotime($today)) / (60 * 60 * 24);
            
            $subject = "⚠️ Grant Report Due Soon: " . $report['grant_code'] . " - " . $report['report_type'] . " Report #" . $report['report_number'];
            $message = "This is a reminder that a grant report is due soon:\n\n";
            $message .= "Grant: " . $report['grant_title'] . " (" . $report['grant_code'] . ")\n";
            $message .= "Donor: " . ($report['donor_name'] ?? 'N/A') . "\n";
            $message .= "Report Type: " . $report['report_type'] . " Report #" . $report['report_number'] . "\n";
            $message .= "Due Date: " . date('F j, Y', strtotime($report['due_date'])) . "\n";
            $message .= "Days Remaining: " . round($daysUntilDue) . " days\n\n";
            $message .= "Please ensure the report is prepared and submitted on time.";
            
            // Use enhanced notification function
            send_grant_notification_enhanced($pdo, $report['grant_id'], $subject, $message, $report['id']);
            
            // Update last notification date
            $updateStmt = $pdo->prepare("UPDATE gms_reporting_schedule SET last_notification_date = ? WHERE id = ?");
            $updateStmt->execute([$today, $report['id']]);
        }
        
        // Get overdue reports and send notifications
        $stmt = $pdo->prepare("
            SELECT rs.*, g.grant_code, g.title AS grant_title, g.project_id, g.id as grant_id, d.name AS donor_name
            FROM gms_reporting_schedule rs
            JOIN gms_grants g ON rs.grant_id = g.id
            LEFT JOIN gms_donors d ON g.donor_id = d.id
            WHERE rs.status IN ('Pending', 'Draft')
            AND rs.due_date < ?
            AND (rs.last_notification_date IS NULL OR rs.last_notification_date < DATE_SUB(?, INTERVAL 1 DAY))
        ");
        $stmt->execute([$today, $today]);
        $overdueReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($overdueReports as $report) {
            $daysOverdue = (strtotime($today) - strtotime($report['due_date'])) / (60 * 60 * 24);
            
            $subject = "🚨 URGENT: Overdue Report - " . $report['grant_code'] . " - " . $report['report_type'] . " Report #" . $report['report_number'];
            $message = "URGENT: A grant report is overdue:\n\n";
            $message .= "Grant: " . $report['grant_title'] . " (" . $report['grant_code'] . ")\n";
            $message .= "Donor: " . ($report['donor_name'] ?? 'N/A') . "\n";
            $message .= "Report Type: " . $report['report_type'] . " Report #" . $report['report_number'] . "\n";
            $message .= "Due Date: " . date('F j, Y', strtotime($report['due_date'])) . "\n";
            $message .= "Days Overdue: " . round($daysOverdue) . " days\n\n";
            $message .= "Please submit this report immediately to avoid compliance issues.";
            
            send_grant_notification_enhanced($pdo, $report['grant_id'], $subject, $message, $report['id']);
            
            // Update last notification date
            $updateStmt = $pdo->prepare("UPDATE gms_reporting_schedule SET last_notification_date = ? WHERE id = ?");
            $updateStmt->execute([$today, $report['id']]);
        }
        
        // Mark overdue reports
        $overdueStmt = $pdo->prepare("
            UPDATE gms_reporting_schedule 
            SET status = 'Overdue' 
            WHERE status IN ('Pending', 'Draft') 
            AND due_date < ?
        ");
        $overdueStmt->execute([$today]);
        
    } catch (Exception $e) {
        error_log("Error checking report deadlines: " . $e->getMessage());
    }
}

// Check report deadlines on page load (can be moved to cron job)
check_report_deadlines($pdo);

// Check opportunity deadlines on page load (can be moved to cron job)
if (!function_exists('check_opportunity_deadlines')) {
    function check_opportunity_deadlines(PDO $pdo) {
        $today = date('Y-m-d');
        
        try {
            // Get opportunities with deadlines approaching
            $stmt = $pdo->query("
                SELECT o.*, d.name AS donor_name
                FROM gms_funding_opportunities o
                LEFT JOIN gms_donors d ON o.donor_id = d.id
                WHERE o.status = 'Open'
                AND o.submission_deadline IS NOT NULL
                AND o.submission_deadline >= CURDATE()
                AND (o.last_notification_date IS NULL OR o.last_notification_date < CURDATE())
                ORDER BY o.submission_deadline
            ");
            $opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($opportunities as $opp) {
                $deadline = new DateTime($opp['submission_deadline']);
                $today_dt = new DateTime();
                $days_remaining = $today_dt->diff($deadline)->days;
                if ($deadline < $today_dt) {
                    $days_remaining = -$days_remaining;
                }
                
                // Update days remaining in database
                $updateStmt = $pdo->prepare("UPDATE gms_funding_opportunities SET days_remaining = ?, last_notification_date = ? WHERE id = ?");
                $updateStmt->execute([$days_remaining, $today, $opp['id']]);
                
                // Send daily notification if days remaining <= 30
                if ($days_remaining <= 30 && $days_remaining >= 0) {
                    $subject = "⏰ Funding Opportunity Deadline Reminder: " . ($opp['opportunity_code'] ?? 'N/A') . " - " . $days_remaining . " days remaining";
                    $message = "This is a reminder about an upcoming funding opportunity deadline:\n\n";
                    $message .= "Opportunity Code: " . ($opp['opportunity_code'] ?? 'N/A') . "\n";
                    $message .= "Title: " . ($opp['title'] ?? 'N/A') . "\n";
                    $message .= "Donor: " . ($opp['donor_name'] ?? 'N/A') . "\n";
                    $message .= "Opportunity Type: " . ($opp['opportunity_type'] ?? 'CFP') . "\n";
                    $message .= "Submission Deadline: " . date('F j, Y', strtotime($opp['submission_deadline'])) . "\n";
                    $message .= "Days Remaining: " . $days_remaining . " days\n\n";
                    
                    if (!empty($opp['call_summary'])) {
                        $message .= "Call Summary: " . substr($opp['call_summary'], 0, 200) . "...\n\n";
                    }
                    
                    if (!empty($opp['opportunity_website'])) {
                        $message .= "Website: " . $opp['opportunity_website'] . "\n\n";
                    }
                    
                    $message .= "Please ensure all required documents are prepared and submitted on time.";
                    
                    // Send to responsible person if assigned
                    if (!empty($opp['responsible_person_email'])) {
                        if (!function_exists('send_opportunity_notification')) {
                            function send_opportunity_notification($email, $name, $subject, $message) {
                                $currentUser = current_user();
                                $fromEmail = $currentUser['email'] ?? 'noreply@system.local';
                                $fromName = $currentUser['name'] ?? $currentUser['full_name'] ?? 'Grant Management System';
                                
                                $headers = [];
                                $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
                                $headers[] = 'Reply-To: ' . $fromEmail;
                                $headers[] = 'MIME-Version: 1.0';
                                $headers[] = 'Content-Type: text/html; charset=UTF-8';
                                
                                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    $htmlMessage = "<html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;color:#333;} .header{background:#667eea;color:white;padding:20px;border-radius:5px;} .content{padding:20px;background:#f9f9f9;border-radius:5px;margin:20px 0;} .footer{color:#666;font-size:12px;margin-top:20px;}</style></head><body>";
                                    $htmlMessage .= "<div class='header'><h2>🏦 Grant Management System</h2><h3>$subject</h3></div>";
                                    $htmlMessage .= "<div class='content'>";
                                    $htmlMessage .= nl2br(htmlspecialchars($message));
                                    $htmlMessage .= "</div>";
                                    $htmlMessage .= "<div class='footer'><p><em>This is an automated notification from the Grant Management System.</em></p></div>";
                                    $htmlMessage .= "</body></html>";
                                    
                                    return @mail($email, $subject, $htmlMessage, implode("\r\n", $headers));
                                }
                                return false;
                            }
                        }
                        send_opportunity_notification($opp['responsible_person_email'], $opp['responsible_person_name'] ?? 'Responsible Person', $subject, $message);
                    }
                    
                    // Send to all project staff
                    $stmt_projects = $pdo->query("
                        SELECT DISTINCT 
                            PM_email, ed_email, finance_head_email, project_officer_email, 
                            opm_email, merl_email, owner_email
                        FROM projects
                        WHERE (PM_email IS NOT NULL OR ed_email IS NOT NULL OR finance_head_email IS NOT NULL 
                               OR project_officer_email IS NOT NULL OR opm_email IS NOT NULL 
                               OR merl_email IS NOT NULL OR owner_email IS NOT NULL)
                    ");
                    $projects = $stmt_projects->fetchAll(PDO::FETCH_ASSOC);
                    
                    $emails_sent = [];
                    foreach ($projects as $project) {
                        $emails = array_filter([
                            'Program Manager' => $project['PM_email'] ?? null,
                            'Executive Director' => $project['ed_email'] ?? null,
                            'Finance Head' => $project['finance_head_email'] ?? null,
                            'Project Coordinator' => $project['project_officer_email'] ?? null,
                            'Operations Manager' => $project['opm_email'] ?? null,
                            'MEAL Manager' => $project['merl_email'] ?? null,
                            'Project Owner' => $project['owner_email'] ?? null
                        ]);
                        
                        foreach ($emails as $role => $email) {
                            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $emails_sent)) {
                                if (!function_exists('send_opportunity_notification')) {
                                    function send_opportunity_notification($email, $name, $subject, $message) {
                                        $currentUser = current_user();
                                        $fromEmail = $currentUser['email'] ?? 'noreply@system.local';
                                        $fromName = $currentUser['name'] ?? $currentUser['full_name'] ?? 'Grant Management System';
                                        
                                        $headers = [];
                                        $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
                                        $headers[] = 'Reply-To: ' . $fromEmail;
                                        $headers[] = 'MIME-Version: 1.0';
                                        $headers[] = 'Content-Type: text/html; charset=UTF-8';
                                        
                                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                            $htmlMessage = "<html><head><style>body{font-family:Arial,sans-serif;line-height:1.6;color:#333;} .header{background:#667eea;color:white;padding:20px;border-radius:5px;} .content{padding:20px;background:#f9f9f9;border-radius:5px;margin:20px 0;} .footer{color:#666;font-size:12px;margin-top:20px;}</style></head><body>";
                                            $htmlMessage .= "<div class='header'><h2>🏦 Grant Management System</h2><h3>$subject</h3></div>";
                                            $htmlMessage .= "<div class='content'>";
                                            $htmlMessage .= nl2br(htmlspecialchars($message));
                                            $htmlMessage .= "</div>";
                                            $htmlMessage .= "<div class='footer'><p><em>This is an automated notification from the Grant Management System.</em></p></div>";
                                            $htmlMessage .= "</body></html>";
                                            
                                            return @mail($email, $subject, $htmlMessage, implode("\r\n", $headers));
                                        }
                                        return false;
                                    }
                                }
                                send_opportunity_notification($email, $role, $subject, $message);
                                $emails_sent[] = $email;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error checking opportunity deadlines: " . $e->getMessage());
        }
    }
}
check_opportunity_deadlines($pdo);

// Handle AJAX requests - Only for actual AJAX calls, not form submissions
if (isset($_GET['ajax']) || (isset($_GET['action']) && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? $_GET['ajax'] ?? '';
    $response = ['success' => false, 'data' => null, 'message' => ''];
    
    try {
        switch ($action) {
            case 'get_donors':
                $stmt = $pdo->query("SELECT id, name, short_name, donor_type FROM gms_donors WHERE is_active = 1 ORDER BY name");
                $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response['success'] = true;
                break;
                
            case 'get_opportunities':
                $donor_id = !empty($_GET['donor_id']) ? (int)$_GET['donor_id'] : null;
                if ($donor_id) {
                    $stmt = $pdo->prepare("SELECT * FROM gms_funding_opportunities WHERE donor_id = ? ORDER BY submission_deadline DESC");
                    $stmt->execute([$donor_id]);
                } else {
                    $stmt = $pdo->query("SELECT o.*, d.name AS donor_name FROM gms_funding_opportunities o LEFT JOIN gms_donors d ON o.donor_id = d.id ORDER BY o.submission_deadline DESC LIMIT 50");
                    $stmt->execute();
                }
                $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response['success'] = true;
                break;
                
            case 'get_workplans':
                $grant_id = (int)($_GET['grant_id'] ?? 0);
                if ($grant_id > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM gms_work_plans WHERE grant_id = ? ORDER BY start_date");
                    $stmt->execute([$grant_id]);
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                }
                break;
                
            case 'get_partners':
                $stmt = $pdo->query("SELECT * FROM gms_partners WHERE is_active = 1 ORDER BY name");
                $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response['success'] = true;
                break;
                
            case 'get_budgets':
                $grant_id = (int)($_GET['grant_id'] ?? 0);
                if ($grant_id > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM gms_grant_budgets WHERE grant_id = ? ORDER BY budget_category, budget_line_code");
                    $stmt->execute([$grant_id]);
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                }
                break;
                
            case 'get_reporting_schedule':
                $grant_id = (int)($_GET['grant_id'] ?? 0);
                if ($grant_id > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM gms_reporting_schedule WHERE grant_id = ? ORDER BY due_date");
                    $stmt->execute([$grant_id]);
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                } else {
                    // Get all upcoming reports
                    $stmt = $pdo->query("
                        SELECT rs.*, g.grant_code, g.title AS grant_title, d.name AS donor_name
                        FROM gms_reporting_schedule rs
                        JOIN gms_grants g ON rs.grant_id = g.id
                        LEFT JOIN gms_donors d ON g.donor_id = d.id
                        WHERE rs.status IN ('Pending', 'Draft', 'Overdue')
                        ORDER BY rs.due_date
                        LIMIT 50
                    ");
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                }
                break;
                
            case 'get_risks':
                $grant_id = (int)($_GET['grant_id'] ?? 0);
                if ($grant_id > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM gms_risk_register WHERE grant_id = ? ORDER BY risk_level DESC, created_at DESC");
                    $stmt->execute([$grant_id]);
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                }
                break;
                
            case 'get_compliance_issues':
                $grant_id = (int)($_GET['grant_id'] ?? 0);
                if ($grant_id > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM gms_compliance_issues WHERE grant_id = ? ORDER BY severity DESC, identified_date DESC");
                    $stmt->execute([$grant_id]);
                    $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                }
                break;
                
            case 'get_grants':
                $filters = [];
                $params = [];
                
                if (!empty($_GET['donor_id'])) {
                    $filters[] = "g.donor_id = ?";
                    $params[] = (int)$_GET['donor_id'];
                }
                if (!empty($_GET['status'])) {
                    $filters[] = "g.status = ?";
                    $params[] = $_GET['status'];
                }
                if (!empty($_GET['project_id'])) {
                    $filters[] = "g.project_id = ?";
                    $params[] = (int)$_GET['project_id'];
                }
                
                $where = !empty($filters) ? "WHERE " . implode(" AND ", $filters) : "";
                $sql = "SELECT g.*, d.name AS donor_name, p.title AS project_title 
                        FROM gms_grants g 
                        LEFT JOIN gms_donors d ON g.donor_id = d.id 
                        LEFT JOIN projects p ON g.project_id = p.id 
                        $where 
                        ORDER BY g.created_at DESC 
                        LIMIT 100";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $response['data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response['success'] = true;
                break;
                
            case 'get_grant_details':
                $grant_id = (int)($_GET['grant_id'] ?? 0);
                if ($grant_id > 0) {
                    $stmt = $pdo->prepare("
                        SELECT g.*, d.name AS donor_name, d.donor_type, p.title AS project_title,
                               (SELECT SUM(budget_amount) FROM gms_grant_budgets WHERE grant_id = g.id) AS total_budgeted,
                               (SELECT SUM(spent_amount) FROM gms_grant_budgets WHERE grant_id = g.id) AS total_spent,
                               (SELECT COUNT(*) FROM gms_work_plans WHERE grant_id = g.id) AS total_activities,
                               (SELECT COUNT(*) FROM gms_sub_grants WHERE grant_id = g.id) AS total_sub_grants
                        FROM gms_grants g
                        LEFT JOIN gms_donors d ON g.donor_id = d.id
                        LEFT JOIN projects p ON g.project_id = p.id
                        WHERE g.id = ?
                    ");
                    $stmt->execute([$grant_id]);
                    $response['data'] = $stmt->fetch(PDO::FETCH_ASSOC);
                    $response['success'] = true;
                }
                break;
                
            case 'get_dashboard_stats':
                $stats = [
                    'total_grants' => count($grants),
                    'active_grants' => count(array_filter($grants, fn($g) => $g['status'] === 'Active')),
                    'overdue_reports' => count(array_filter($reports, fn($r) => $r['status'] === 'Overdue')),
                    'total_budget' => array_sum(array_column($grants, 'total_budget'))
                ];
                $response['data'] = $stats;
                $response['stats'] = $stats;
                $response['success'] = true;
                break;
                
            case 'ai_query':
                $input = json_decode(file_get_contents('php://input'), true);
                $context = $input['context'] ?? 'General';
                $query = strtolower(trim($input['query'] ?? ''));
                
                // Enhanced AI response system
                $response_text = '';
                
                // Grant-specific queries
                if (strpos($query, 'grant') !== false || strpos($query, 'funding') !== false || $context === 'Grants') {
                    if (strpos($query, 'how many') !== false || strpos($query, 'count') !== false || strpos($query, 'total') !== false) {
                        $total = count($grants);
                        $active = count(array_filter($grants, fn($g) => $g['status'] === 'Active'));
                        $response_text = "You currently have " . $total . " grants in the system, with " . $active . " active grants.";
                    } elseif (strpos($query, 'status') !== false || strpos($query, 'state') !== false) {
                        $statuses = array_count_values(array_column($grants, 'status'));
                        $response_text = "Grant status breakdown: " . implode(', ', array_map(fn($k, $v) => "$k: $v", array_keys($statuses), $statuses));
                    } elseif (strpos($query, 'budget') !== false || strpos($query, 'money') !== false || strpos($query, 'fund') !== false) {
                        $total_budget = array_sum(array_column($grants, 'total_budget'));
                        $response_text = "Total grant budget across all grants: $" . number_format($total_budget, 2);
                    } else {
                        $response_text = "I can help you with grant management. You have " . count($grants) . " grants in the system. Would you like to know about grant status, budgets, or reporting schedules?";
                    }
                }
                // Opportunity-specific queries
                elseif (strpos($query, 'opportunity') !== false || strpos($query, 'proposal') !== false || $context === 'Opportunities') {
                    try {
                        $oppStmt = $pdo->query("SELECT COUNT(*) as cnt, SUM(CASE WHEN go_no_go_decision = 'Go' THEN 1 ELSE 0 END) as applied, SUM(CASE WHEN proposal_status = 'Succeeded' THEN 1 ELSE 0 END) as succeeded FROM gms_funding_opportunities");
                        $oppData = $oppStmt->fetch(PDO::FETCH_ASSOC);
                        $total_opps = $oppData['cnt'] ?? 0;
                        $applied = $oppData['applied'] ?? 0;
                        $succeeded = $oppData['succeeded'] ?? 0;
                        
                        if (strpos($query, 'success') !== false || strpos($query, 'succeed') !== false) {
                            $response_text = "You have " . $succeeded . " successful proposals out of " . $applied . " applied opportunities.";
                        } elseif (strpos($query, 'applied') !== false || strpos($query, 'apply') !== false) {
                            $response_text = "You have applied for " . $applied . " funding opportunities.";
                        } else {
                            $response_text = "You have " . $total_opps . " funding opportunities in the system, with " . $applied . " where you decided to apply (Go decision).";
                        }
                    } catch (Exception $e) {
                        $response_text = "I can help you with funding opportunities. Check the Opportunities section to see all available opportunities and their status.";
                    }
                }
                // Reporting queries
                elseif (strpos($query, 'report') !== false || $context === 'Reporting') {
                    $overdue = count(array_filter($reports, fn($r) => $r['status'] === 'Overdue'));
                    $pending = count(array_filter($reports, fn($r) => $r['status'] === 'Pending'));
                    if (strpos($query, 'overdue') !== false || strpos($query, 'late') !== false) {
                        $response_text = "You have " . $overdue . " overdue reports that need immediate attention.";
                    } elseif (strpos($query, 'pending') !== false) {
                        $response_text = "You have " . $pending . " pending reports.";
                    } else {
                        $response_text = "You have " . count($reports) . " reports in the system. " . $overdue . " are overdue and " . $pending . " are pending.";
                    }
                }
                // General help
                else {
                    $response_text = "I'm your AI assistant for the Grant Management System. I can help you with:\n\n";
                    $response_text .= "• Grant management and tracking\n";
                    $response_text .= "• Funding opportunities and proposals\n";
                    $response_text .= "• Report deadlines and submissions\n";
                    $response_text .= "• Budget analysis and spending\n";
                    $response_text .= "• Donor and partner information\n\n";
                    $response_text .= "What would you like to know? Try asking about grants, opportunities, reports, or budgets.";
                }
                
                $response['success'] = true;
                $response['response'] = $response_text;
                break;
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        error_log("GMS AJAX Error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
}

// Handle Export/Import actions - BEFORE form submissions
if (isset($_GET['action']) && in_array($_GET['action'], ['export', 'export_template', 'import'])) {
    $export_action = $_GET['action'];
    $module = $_GET['module'] ?? '';
    if ($module === '' && isset($_POST['module'])) { $module = (string)$_POST['module']; }
    if ($module === '' && isset($_POST['action'])) {
        $pa = (string)$_POST['action'];
        if (strpos($pa, 'import_') === 0) { $module = substr($pa, 7); }
    }
    if ($module === '') { $module = 'grants'; }
    
    if ($export_action === 'export') {
        $format = $_GET['format'] ?? 'excel'; // excel|csv|pdf|print
        $format = strtolower($format);
        $grantId = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;

        // Build dataset per module
        $rows = [];
        $headers = [];

        $csvOut = function(array $headers, array $rows, string $filename) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            // UTF-8 BOM for Excel
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $r) { fputcsv($out, $r); }
            fclose($out);
            exit;
        };

        $buildHtml = function(string $title, array $headers, array $rows, bool $includePrintHint = true): string {
            $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>';
            $html .= '<style>
                body{font-family:Arial,Helvetica,sans-serif;margin:20px;color:#111;}
                h1{font-size:18px;margin:0 0 10px 0;}
                .meta{font-size:12px;color:#555;margin-bottom:12px;}
                table{width:100%;border-collapse:collapse;font-size:12px;}
                th,td{border:1px solid #ddd;padding:6px;vertical-align:top;}
                th{background:#f3f4f6;text-align:left;}
                .no-print{margin:10px 0;padding:10px;border:1px dashed #aaa;background:#fff;}
                @media print{.no-print{display:none;}}
            </style></head><body>';
            $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
            $html .= '<div class="meta">Generated: ' . date('Y-m-d H:i') . '</div>';
            if ($includePrintHint) {
                $html .= '<div class="no-print"><strong>PDF export:</strong> Use your browser\'s <em>Print</em> dialog and choose <em>Save as PDF</em>.</div>';
            }
            $html .= '<table><thead><tr>';
            foreach ($headers as $h) $html .= '<th>' . htmlspecialchars($h) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                foreach ($r as $cell) $html .= '<td>' . htmlspecialchars((string)$cell) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            if ($includePrintHint) {
                $html .= '<script>try{setTimeout(()=>window.print(),300);}catch(e){}<\/script>';
            }
            $html .= '</body></html>';
            return $html;
        };

        $htmlOut = function(string $title, array $headers, array $rows) use ($buildHtml) {
            header('Content-Type: text/html; charset=utf-8');
            echo $buildHtml($title, $headers, $rows, true);
            exit;
        };

        // Fetch helpers
        $fetchAll = function(string $sql, array $params = []) use ($pdo) {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };

        switch ($module) {
            case 'donors':
                $headers = ['ID','Name','Short Name','Type','Contact Name','Email','Phone','Website','Country','Active'];
                $items = $fetchAll("SELECT * FROM gms_donors ORDER BY name");
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['name'] ?? '', $it['short_name'] ?? '', $it['donor_type'] ?? '',
                        $it['contact_name'] ?? '', $it['contact_email'] ?? '', $it['contact_phone'] ?? '',
                        $it['website'] ?? '', $it['country'] ?? '', $it['is_active'] ?? 1
                    ];
                }
                break;

            case 'opportunities':
                $headers = ['ID','Opportunity Code','Title','Donor','Deadline','Budget Min','Budget Max','Currency','Website','Status'];
                $items = $fetchAll("SELECT o.*, d.name AS donor_name FROM gms_funding_opportunities o LEFT JOIN gms_donors d ON o.donor_id = d.id ORDER BY o.submission_deadline DESC, o.id DESC");
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['opportunity_code'] ?? '', $it['title'] ?? '', $it['donor_name'] ?? '',
                        $it['submission_deadline'] ?? '', $it['budget_min'] ?? '', $it['budget_max'] ?? '',
                        $it['currency'] ?? '', $it['website'] ?? '', $it['status'] ?? ''
                    ];
                }
                break;

            case 'workplan_registers':
                if ($grantId <= 0) { throw new Exception("workplan_registers export requires grant_id"); }
                $headers = ['ID','Register Code','Title','Period Start','Period End','Location','Responsible Person','Responsible Email','Status','Notes'];
                $items = $fetchAll("SELECT * FROM gms_workplan_registers WHERE grant_id = ? AND is_active = 1 ORDER BY period_start DESC, id DESC", [$grantId]);
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['register_code'] ?? '', $it['title'] ?? '', $it['period_start'] ?? '', $it['period_end'] ?? '',
                        $it['location_name'] ?? '', $it['responsible_person'] ?? '', $it['responsible_email'] ?? '',
                        $it['status'] ?? '', $it['notes'] ?? ''
                    ];
                }
                break;

            
                        case 'workplan_registers':
                            $grantCode = trim($rowData['grant code'] ?? $rowData['grant_code'] ?? '');
                            $grantId = (int)($rowData['grant id'] ?? $rowData['grant_id'] ?? 0);
                            if ($grantId <= 0 && $grantCode !== '') {
                                $gs = $pdo->prepare("SELECT id FROM gms_grants WHERE grant_code = ? LIMIT 1");
                                $gs->execute([$grantCode]);
                                $g = $gs->fetch(PDO::FETCH_ASSOC);
                                $grantId = (int)($g['id'] ?? 0);
                            }
                            if ($grantId <= 0) { throw new Exception("Workplan register import requires Grant Code or Grant ID."); }

                            $registerCode = trim($rowData['register code'] ?? $rowData['register_code'] ?? '');
                            if ($registerCode === '') { $registerCode = 'WPREG-' . date('YmdHis'); }
                            $title = trim($rowData['title'] ?? 'Workplan Register');
                            $periodStart = $rowData['period start'] ?? $rowData['period_start'] ?? null;
                            $periodEnd = $rowData['period end'] ?? $rowData['period_end'] ?? null;
                            $locationName = $rowData['location name'] ?? $rowData['location_name'] ?? null;
                            $responsibleEmail = $rowData['responsible email'] ?? $rowData['responsible_email'] ?? null;

                            // Optional: link to Workplan Register by code
                            $registerCode = trim($rowData['register code'] ?? $rowData['register_code'] ?? '');
                            $registerId = 0;
                            if ($registerCode !== '') {
                                try {
                                    $rs = $pdo->prepare("SELECT id FROM gms_workplan_registers WHERE grant_id = ? AND register_code = ? LIMIT 1");
                                    $rs->execute([$grantId, $registerCode]);
                                    $rr = $rs->fetch(PDO::FETCH_ASSOC);
                                    $registerId = (int)($rr['id'] ?? 0);
                                } catch (Exception $e) { $registerId = 0; }
                            }

                            $resultOutput = $rowData['result output'] ?? $rowData['result_output'] ?? null;
                            $indicatorText = $rowData['indicator text'] ?? $rowData['indicator_text'] ?? null;
                            $targetQty = $rowData['target quantity'] ?? $rowData['target_quantity'] ?? null;
                            $targetUnit = $rowData['target unit'] ?? $rowData['target_unit'] ?? null;
                            $mov = $rowData['means of verification'] ?? $rowData['means_of_verification'] ?? null;
                            $dependencies = $rowData['dependencies'] ?? null;
                            $budgetAmount = $rowData['budget amount'] ?? $rowData['budget_amount'] ?? null;
                            $budgetCurrency = $rowData['budget currency'] ?? $rowData['budget_currency'] ?? null;
                            $wbsCode = $rowData['wbs code'] ?? $rowData['wbs_code'] ?? null;
                            $activitySeq = $rowData['activity sequence'] ?? $rowData['activity_sequence'] ?? null;
                            $notes = $rowData['notes'] ?? null;

                            $respPerson = $rowData['responsible person'] ?? $rowData['responsible_person'] ?? null;
                            $respEmail = $rowData['responsible email'] ?? $rowData['responsible_email'] ?? null;
                            $status = $rowData['status'] ?? 'Active';
                            $notes = $rowData['notes'] ?? null;

                            // Upsert by (grant_id, register_code)
                            $ck = $pdo->prepare("SELECT id FROM gms_workplan_registers WHERE grant_id = ? AND register_code = ? LIMIT 1");
                            $ck->execute([$grantId, $registerCode]);
                            $ex = $ck->fetch(PDO::FETCH_ASSOC);

                            if ($ex && !empty($ex['id'])) {
                                $stmt = $pdo->prepare("UPDATE gms_workplan_registers SET title=?, period_start=?, period_end=?, location_name=?, responsible_person=?, responsible_email=?, status=?, notes=?, is_active=1 WHERE id=?");
                                $stmt->execute([$title, $periodStart ?: null, $periodEnd ?: null, $locationName, $respPerson, $respEmail, $status, $notes, (int)$ex['id']]);
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO gms_workplan_registers (grant_id, register_code, title, period_start, period_end, location_name, responsible_person, responsible_email, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
                                $stmt->execute([$grantId, $registerCode, $title, $periodStart ?: null, $periodEnd ?: null, $locationName, $respPerson, $respEmail, $status, $notes]);
                            }
                            $imported++;
                            break;

case 'workplans':
                if ($grantId <= 0) { throw new Exception("workplans export requires grant_id"); }
                $headers = ['ID','Activity Code','Activity Name','Description','Start Date','End Date','Status','Progress %','Register','Location','Responsible Person','Responsible Email','Result/Output','Indicator','Target Qty','Target Unit','MoV','Dependencies','Budget Amount','Budget Currency','WBS Code','Sequence','Notes'];
                $items = $fetchAll("SELECT wp.*, wr.register_code, wr.title AS register_title FROM gms_work_plans wp LEFT JOIN gms_workplan_registers wr ON wp.workplan_register_id = wr.id WHERE wp.grant_id = ? ORDER BY wp.start_date, wp.id", [$grantId]);
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['activity_code'] ?? '', $it['activity_name'] ?? '',
                        $it['description'] ?? '',
                        $it['start_date'] ?? '', $it['end_date'] ?? '', $it['status'] ?? '', $it['progress_percentage'] ?? '',
                        trim(($it['register_code'] ?? '') . ' ' . ($it['register_title'] ?? '')),
                        $it['location_name'] ?? '', $it['responsible_person'] ?? '', $it['responsible_email'] ?? '',
                        $it['result_output'] ?? '', $it['indicator_text'] ?? '', $it['target_quantity'] ?? '', $it['target_unit'] ?? '',
                        $it['means_of_verification'] ?? '', $it['dependencies'] ?? '',
                        $it['budget_amount'] ?? '', $it['budget_currency'] ?? '', $it['wbs_code'] ?? '', $it['activity_sequence'] ?? '',
                        $it['notes'] ?? ''
                    ];
                }
                break;

            case 'partners':
                $headers = ['ID','Partner Code','Name','Type','Email','Phone','Country','Active','Notes'];
                $items = $fetchAll("SELECT * FROM gms_partners ORDER BY name");
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['partner_code'] ?? '', $it['name'] ?? '', $it['partner_type'] ?? '',
                        $it['contact_email'] ?? '', $it['contact_phone'] ?? '', $it['country'] ?? '', $it['is_active'] ?? 1, $it['notes'] ?? ''
                    ];
                }
                break;

            case 'budgets':
                if ($grantId <= 0) { throw new Exception("budgets export requires grant_id"); }
                $headers = ['ID','Budget Line Code','Category','Description','Budget Amount','Committed','Spent','Currency','Notes'];
                $items = $fetchAll("SELECT * FROM gms_grant_budgets WHERE grant_id = ? ORDER BY budget_category, id", [$grantId]);
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['budget_line_code'] ?? '', $it['budget_category'] ?? '', $it['description'] ?? '',
                        $it['budget_amount'] ?? '', $it['committed_amount'] ?? '', $it['spent_amount'] ?? '', $it['currency'] ?? '', $it['notes'] ?? ''
                    ];
                }
                break;

            case 'risks':
                if ($grantId <= 0) { throw new Exception("risks export requires grant_id"); }
                $headers = ['ID','Risk Code','Category','Description','Likelihood','Impact','Risk Level','Residual Risk','Due Date','Owner Name','Owner Email','Responsible Person','Mitigation','Contingency','Status','Review Date','Notes'];
                $items = $fetchAll("SELECT * FROM gms_risk_register WHERE grant_id = ? ORDER BY created_at DESC, id DESC", [$grantId]);
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['risk_code'] ?? '', $it['risk_category'] ?? '', $it['risk_description'] ?? '',
                        $it['likelihood'] ?? '', $it['impact'] ?? '', $it['risk_level'] ?? '',
                        $it['residual_risk_level'] ?? '', $it['due_date'] ?? '', $it['risk_owner_name'] ?? '', $it['risk_owner_email'] ?? '',
                        $it['responsible_person'] ?? '', $it['mitigation_measures'] ?? '', $it['contingency_plan'] ?? '',
                        $it['status'] ?? '', $it['review_date'] ?? '', $it['notes'] ?? ''
                    ];
                }
                break;

            case 'compliance':
                if ($grantId <= 0) { throw new Exception("compliance export requires grant_id"); }
                $headers = ['ID','Issue Code','Type','Description','Severity','Compliance Area','Requirement Ref','Evidence Required','Identified Date','Target Resolution','Actual Resolution','Follow-up Date','Responsible Name','Responsible Email','Responsible Person','Corrective Action','Status','Root Cause','Notes'];
                $items = $fetchAll("SELECT * FROM gms_compliance_issues WHERE grant_id = ? ORDER BY identified_date DESC, id DESC", [$grantId]);
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['issue_code'] ?? '', $it['issue_type'] ?? '', $it['description'] ?? '',
                        $it['severity'] ?? '', $it['compliance_area'] ?? '', $it['requirement_reference'] ?? '', $it['evidence_required'] ?? '',
                        $it['identified_date'] ?? '', $it['target_resolution_date'] ?? '', $it['actual_resolution_date'] ?? '',
                        $it['follow_up_date'] ?? '', $it['responsible_person_name'] ?? '', $it['responsible_person_email'] ?? '',
                        $it['responsible_person'] ?? '', $it['corrective_action'] ?? '', $it['status'] ?? '', $it['root_cause'] ?? '', $it['notes'] ?? ''
                    ];
                }
                break;

            case 'grants':
            default:
                $headers = ['ID','Grant Code','Title','Donor','Status','Start Date','End Date','Total Budget','Currency','Risk Level','Reporting Frequency'];
                $items = $fetchAll("SELECT g.*, d.name AS donor_name FROM gms_grants g LEFT JOIN gms_donors d ON g.donor_id = d.id ORDER BY g.created_at DESC, g.id DESC");
                foreach ($items as $it) {
                    $rows[] = [
                        $it['id'] ?? '', $it['grant_code'] ?? '', $it['title'] ?? '', $it['donor_name'] ?? '',
                        $it['status'] ?? '', $it['start_date'] ?? '', $it['end_date'] ?? '',
                        $it['total_budget'] ?? '', $it['currency'] ?? '', $it['risk_level'] ?? '', $it['reporting_frequency'] ?? ''
                    ];
                }
                break;
        }

        $filenameBase = 'gms_' . $module . '_' . date('Y-m-d');

        if ($format === 'pdf') {
            // Real PDF download (server-side) if Dompdf is installed; otherwise fallback to print-friendly HTML.
            $title = strtoupper($module) . ' Export';
            $html  = $buildHtml($title, $headers, $rows, false);
            hrs_export_pdf_from_html($html, $filenameBase . '.pdf', 'A4', 'landscape');
        }

        if ($format === 'print') {
            $htmlOut(strtoupper($module) . ' Export', $headers, $rows);
        }

        // Default: CSV (Excel button can still open CSV reliably)
        $csvOut($headers, $rows, $filenameBase . '.csv');
    } elseif ($export_action === 'export_template') {
        // Generate and download template
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $module . '_template.csv"');
        
        $templates = [
            // CSV headers (lowercase-insensitive on import). Donor-standard fields included.
            'grants' => ['Grant Code', 'Title', 'Donor ID', 'Status', 'Start Date', 'End Date', 'Total Budget', 'Currency', 'Risk Level', 'Reporting Frequency'],
            'donors' => ['Name', 'Short Name', 'Donor Type', 'Contact Name', 'Contact Email', 'Contact Phone', 'Website', 'Country', 'Notes'],
            'opportunities' => ['Opportunity Code', 'Title', 'Donor ID', 'Submission Deadline', 'Budget Min', 'Budget Max', 'Currency', 'Website', 'Eligibility', 'Notes'],
            'workplan_registers' => ['Grant Code', 'Register Code', 'Title', 'Period Start', 'Period End', 'Location Name', 'Responsible Person', 'Responsible Email', 'Status', 'Notes'],
            'workplans' => ['Grant Code', 'Register Code', 'Activity Code', 'Activity Name', 'Description', 'Start Date', 'End Date', 'Status', 'Progress Percentage', 'Location Name', 'Responsible Person', 'Responsible Email', 'Result Output', 'Indicator Text', 'Target Quantity', 'Target Unit', 'Means of Verification', 'Dependencies', 'Budget Amount', 'Budget Currency', 'WBS Code', 'Activity Sequence', 'Notes'],
            'partners' => ['Partner Code', 'Name', 'Partner Type', 'Contact Email', 'Contact Phone', 'Country', 'Notes'],
            'budgets' => ['Grant Code', 'Budget Line Code', 'Category', 'Description', 'Budget Amount', 'Currency', 'Committed Amount', 'Spent Amount', 'Notes'],
            'risks' => ['Grant Code', 'Risk Code', 'Risk Category', 'Risk Description', 'Likelihood', 'Impact', 'Risk Level', 'Residual Risk Level', 'Due Date', 'Risk Owner Name', 'Risk Owner Email', 'Responsible Person', 'Mitigation Measures', 'Contingency Plan', 'Status', 'Review Date', 'Notes'],
            'compliance' => ['Grant Code', 'Issue Code', 'Issue Type', 'Description', 'Severity', 'Compliance Area', 'Requirement Reference', 'Evidence Required', 'Identified Date', 'Target Resolution Date', 'Actual Resolution Date', 'Follow Up Date', 'Responsible Person Name', 'Responsible Person Email', 'Responsible Person', 'Corrective Action', 'Status', 'Root Cause', 'Notes']
        ];
        
        $headers = $templates[$module] ?? [];
        echo implode(',', $headers) . "\n";
        exit;
    } elseif ($export_action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle file import
        header('Content-Type: application/json');
        $response = ['success' => false, 'message' => '', 'count' => 0];
        
        try {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $errorMsg = 'No file uploaded.';
                if (isset($_FILES['file']['error'])) {
                    $uploadErrors = [
                        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                    ];
                    $errorMsg = $uploadErrors[$_FILES['file']['error']] ?? 'Upload error code: ' . $_FILES['file']['error'];
                }
                throw new Exception($errorMsg);
            }
            
            $file = $_FILES['file'];
            
            // Check file size
            if ($file['size'] == 0) {
                throw new Exception('Uploaded file is empty (0 bytes).');
            }
            
            // Check if file exists
            if (!file_exists($file['tmp_name'])) {
                throw new Exception('Uploaded file could not be found on server.');
            }
            
            $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileType, ['csv', 'xlsx', 'xls'])) {
                throw new Exception('Invalid file type. Please upload CSV or Excel file. Detected: ' . $fileType);
            }
            
            // Get module from POST data (sent from JavaScript)
            $module = $_POST['action'] ?? 'grants';
            $module = str_replace('import_', '', $module);
            
            // Parse file - read ALL rows first, don't filter yet
            $rows = [];
            
            if ($fileType === 'csv') {
                $handle = fopen($file['tmp_name'], 'r');
                if (!$handle) {
                    throw new Exception('Cannot open uploaded CSV file.');
                }
                
                // Try to detect delimiter by reading first line
                $firstLine = fgets($handle);
                rewind($handle);
                
                $delimiter = ',';
                if ($firstLine !== false) {
                    $semicolonCount = substr_count($firstLine, ';');
                    $commaCount = substr_count($firstLine, ',');
                    $tabCount = substr_count($firstLine, "\t");
                    
                    if ($tabCount > $commaCount && $tabCount > $semicolonCount) {
                        $delimiter = "\t";
                    } elseif ($semicolonCount > $commaCount) {
                        $delimiter = ';';
                    }
                }
                
                // Read ALL rows without filtering
                $rowCount = 0;
                while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                    $rowCount++;
                    // Always add the row, even if it appears empty
                    // fgetcsv returns false for empty lines, but we want to keep all valid CSV rows
                    $rows[] = $data;
                }
                fclose($handle);
                
                // If fgetcsv didn't read anything, try reading as plain text
                if (empty($rows) && $rowCount == 0) {
                    $content = file_get_contents($file['tmp_name']);
                    if ($content !== false && !empty(trim($content))) {
                        $lines = explode("\n", $content);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (!empty($line)) {
                                // Try to split by delimiter
                                $data = str_getcsv($line, $delimiter);
                                if (!empty($data)) {
                                    $rows[] = $data;
                                }
                            }
                        }
                    }
                }
                
            } elseif ($fileType === 'xlsx') {
                $rows = parseXLSXToRows($file['tmp_name']);
                if (empty($rows)) {
                    // Try reading as CSV if XLSX parsing fails
                    $handle = fopen($file['tmp_name'], 'r');
                    if ($handle) {
                        while (($data = fgetcsv($handle)) !== false) {
                            $rows[] = $data;
                        }
                        fclose($handle);
                    }
                }
            } else {
                throw new Exception('XLS (old Excel) format is not supported. Please save as CSV or XLSX and import again.');
            }
            
            // Debug: Log what we got
            error_log("GMS Import Debug: File type: $fileType, Total rows parsed: " . count($rows));
            if (!empty($rows)) {
                error_log("GMS Import Debug: First row: " . json_encode($rows[0]));
                error_log("GMS Import Debug: First row count: " . count($rows[0]));
            }
            
            // Don't filter too aggressively - keep rows that have at least one cell
            $filteredRows = [];
            foreach ($rows as $idx => $row) {
                if (!is_array($row)) {
                    // Convert non-array to array
                    $row = [$row];
                }
                
                // Check if row has any content (even whitespace)
                $hasData = false;
                $nonEmptyCells = 0;
                foreach ($row as $cell) {
                    $cellStr = is_string($cell) ? $cell : (string)$cell;
                    $trimmed = trim($cellStr);
                    if ($trimmed !== '' && $trimmed !== null) {
                        $hasData = true;
                        $nonEmptyCells++;
                    }
                }
                
                // Keep row if it has at least one non-empty cell OR if it's the first row (header)
                if ($hasData || $idx === 0) {
                    $filteredRows[] = $row;
                }
            }
            $rows = $filteredRows;
            
            // Final check - if still empty, provide detailed error
            if (empty($rows)) {
                $fileSize = filesize($file['tmp_name']);
                $fileContent = file_get_contents($file['tmp_name']);
                $contentLength = strlen($fileContent);
                $firstChars = substr($fileContent, 0, 200);
                
                throw new Exception("File appears to be empty or could not be parsed. File size: {$fileSize} bytes, Content length: {$contentLength} chars. First 200 chars: " . htmlspecialchars($firstChars));
            }
            
            if (count($rows) < 2) {
                $rowCount = count($rows);
                $debugInfo = "Found {$rowCount} row(s) after parsing. ";
                if ($rowCount > 0 && isset($rows[0])) {
                    $firstRow = is_array($rows[0]) ? $rows[0] : [$rows[0]];
                    $debugInfo .= "Header row has " . count($firstRow) . " columns. ";
                    $sampleHeaders = array_slice(array_map(function($h) {
                        $hStr = trim((string)$h);
                        return !empty($hStr) ? substr($hStr, 0, 30) : '(empty)';
                    }, $firstRow), 0, 8);
                    $debugInfo .= "Sample headers: " . implode(' | ', $sampleHeaders);
        } else {
                    $debugInfo .= "No header row found.";
                }
                throw new Exception('File must have a header row and at least one data row. ' . $debugInfo);
            }
            
            // Normalize header row - handle various column name formats
            $header = array_map('trim', $rows[0]);
            $header = array_map('strtolower', $header);
            // Remove empty headers
            $header = array_filter($header, function($h) { return !empty($h); });
            $header = array_values($header);
            
            // Helper function to find column value with multiple possible names
            $getColumnValue = function($rowData, $possibleNames) {
                foreach ($possibleNames as $name) {
                    if (isset($rowData[$name])) {
                        $val = trim($rowData[$name] ?? '');
                        if (!empty($val)) return $val;
                    }
                }
                return '';
            };
            
            $imported = 0;
            $errors = [];
            $user = current_user();
            $userId = $user['id'] ?? null;
            
            // Process each row based on module
            foreach (array_slice($rows, 1) as $rowIndex => $dataRow) {
                if (!is_array($dataRow)) continue;
                
                // Normalize row length
                if (count($dataRow) < count($header)) {
                    $dataRow = array_pad($dataRow, count($header), '');
                } elseif (count($dataRow) > count($header)) {
                    $dataRow = array_slice($dataRow, 0, count($header));
                }
                
                $rowData = @array_combine($header, $dataRow);
                if ($rowData === false) {
                    $errors[] = "Row " . ($rowIndex + 2) . ": Header/data mismatch";
                    continue;
                }
                
                // Skip completely empty rows
                if (empty(array_filter($rowData, function($v) { return trim($v) !== ''; }))) {
                    continue;
                }
                
                try {
                    switch ($module) {
                        case 'donors':
                            $name = trim($rowData['name'] ?? '');
                            if (empty($name)) continue 2;
                            
                            // Check if donor exists
                            $checkStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ?");
                            $checkStmt->execute([$name]);
                            $existing = $checkStmt->fetch();
                            
                            if ($existing) {
                                $stmt = $pdo->prepare("
                                    UPDATE gms_donors SET
                                        short_name = ?, donor_type = ?, contact_name = ?,
                                        contact_email = ?, contact_phone = ?, website = ?,
                                        address = ?, country = ?, notes = ?, is_active = 1
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $rowData['short name'] ?? null,
                                    $rowData['donor type'] ?? 'Other',
                                    $rowData['contact name'] ?? null,
                                    $rowData['contact email'] ?? null,
                                    $rowData['contact phone'] ?? null,
                                    $rowData['website'] ?? null,
                                    $rowData['address'] ?? null,
                                    $rowData['country'] ?? null,
                                    $rowData['notes'] ?? null,
                                    $existing['id']
                                ]);
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO gms_donors (name, short_name, donor_type, contact_name, contact_email, contact_phone, website, address, country, notes, is_active)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                                ");
                                $stmt->execute([
                                    $name,
                                    $rowData['short name'] ?? null,
                                    $rowData['donor type'] ?? 'Other',
                                    $rowData['contact name'] ?? null,
                                    $rowData['contact email'] ?? null,
                                    $rowData['contact phone'] ?? null,
                                    $rowData['website'] ?? null,
                                    $rowData['address'] ?? null,
                                    $rowData['country'] ?? null,
                                    $rowData['notes'] ?? null
                                ]);
                            }
                            $imported++;
                            break;
                            
                        case 'grants':
                            // Try multiple possible column names for grant code
                            $grantCode = $getColumnValue($rowData, [
                                'grant code', 'grantcode', 'grant_code', 'code', 'grant cod title'
                            ]);
                            
                            // Try multiple possible column names for title
                            $title = $getColumnValue($rowData, [
                                'title', 'grant title', 'name', 'grant name', 'grant cod title'
                            ]);
                            
                            // If we got "grant cod title" as a single field, try to split it
                            if (empty($grantCode) && empty($title)) {
                                $grantCodTitle = $getColumnValue($rowData, ['grant cod title', 'grantcodtitle']);
                                if (!empty($grantCodTitle)) {
                                    // Try to split - assume first part is code, rest is title
                                    $parts = preg_split('/\s+/', trim($grantCodTitle), 2);
                                    $grantCode = $parts[0] ?? '';
                                    $title = $parts[1] ?? $grantCodTitle;
                                }
                            }
                            
                            if (empty($grantCode) && empty($title)) continue 2;
                            
                            if (empty($grantCode)) {
                                $grantCode = 'GRANT-' . time() . '-' . $rowIndex;
                            }
                            if (empty($title)) {
                                $title = $grantCode; // Use code as title if title is missing
                            }
                            
                            // Get donor ID - try multiple column name variations
                            $donorId = 0;
                            $donorValue = $getColumnValue($rowData, [
                                'donor id', 'donorid', 'donor_id', 'donor', 'donor name', 'donorname'
                            ]);
                            
                            if (!empty($donorValue)) {
                                // Try as numeric ID first
                                if (is_numeric($donorValue)) {
                                    $donorId = (int)$donorValue;
                                    // Verify it exists
                                    $checkDonor = $pdo->prepare("SELECT id FROM gms_donors WHERE id = ? AND is_active = 1");
                                    $checkDonor->execute([$donorId]);
                                    if (!$checkDonor->fetch()) {
                                        $donorId = 0;
                                    }
                                } else {
                                    $donorValueTrimmed = trim($donorValue);
                                    
                                    // Try exact match first (case-insensitive)
                                    $donorStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE (LOWER(name) = LOWER(?) OR LOWER(short_name) = LOWER(?)) AND is_active = 1 LIMIT 1");
                                    $donorStmt->execute([$donorValueTrimmed, $donorValueTrimmed]);
                                    $donor = $donorStmt->fetch();
                                    
                                    if ($donor) {
                                        $donorId = (int)$donor['id'];
                                    } else {
                                        // Try partial match (contains)
                                        $donorStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE (LOWER(name) LIKE LOWER(?) OR LOWER(short_name) LIKE LOWER(?)) AND is_active = 1 LIMIT 1");
                                        $searchTerm = '%' . $donorValueTrimmed . '%';
                                        $donorStmt->execute([$searchTerm, $searchTerm]);
                                        $donor = $donorStmt->fetch();
                                        
                                        if ($donor) {
                                            $donorId = (int)$donor['id'];
                                        } else {
                                            // Try reverse match (donor name contains search term)
                                            $donorStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE (LOWER(?) LIKE CONCAT('%', LOWER(name), '%') OR LOWER(?) LIKE CONCAT('%', LOWER(short_name), '%')) AND is_active = 1 LIMIT 1");
                                            $donorStmt->execute([$donorValueTrimmed, $donorValueTrimmed]);
                                            $donor = $donorStmt->fetch();
                                            
                                            if ($donor) {
                                                $donorId = (int)$donor['id'];
                                            }
                                        }
                                    }
                                    
                                    // If still not found, auto-create the donor
                                    if ($donorId <= 0) {
                                        try {
                                            // Determine donor type from name (heuristic)
                                            $donorType = 'Other';
                                            $donorNameLower = strtolower($donorValueTrimmed);
                                            if (strpos($donorNameLower, 'un') !== false || strpos($donorNameLower, 'ocha') !== false) {
                                                $donorType = 'UN Agency';
                                            } elseif (strpos($donorNameLower, 'ehf') !== false || strpos($donorNameLower, 'humanitarian') !== false) {
                                                $donorType = 'Pooled Fund';
                                            } elseif (strpos($donorNameLower, 'foundation') !== false) {
                                                $donorType = 'Foundation';
                                            } elseif (strpos($donorNameLower, 'government') !== false || strpos($donorNameLower, 'gov') !== false) {
                                                $donorType = 'Government';
                                            }
                                            
                                            $insertDonor = $pdo->prepare("
                                                INSERT INTO gms_donors (name, short_name, donor_type, is_active)
                                                VALUES (?, ?, ?, 1)
                                            ");
                                            // Use the value as both name and short_name if no separator
                                            $shortName = $donorValueTrimmed;
                                            if (strlen($donorValueTrimmed) > 50) {
                                                $shortName = substr($donorValueTrimmed, 0, 50);
                                            }
                                            $insertDonor->execute([$donorValueTrimmed, $shortName, $donorType]);
                                            $donorId = (int)$pdo->lastInsertId();
                                        } catch (PDOException $e) {
                                            // If insert fails (maybe duplicate), try to find it again
                                            $donorStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE LOWER(name) = LOWER(?) LIMIT 1");
                                            $donorStmt->execute([$donorValueTrimmed]);
                                            $donor = $donorStmt->fetch();
                                            if ($donor) {
                                                $donorId = (int)$donor['id'];
                                            }
                                        }
                                    }
                                }
                            }
                            
                            if ($donorId <= 0) {
                                $errors[] = "Row " . ($rowIndex + 2) . ": Could not find or create donor '" . htmlspecialchars($donorValue) . "'. Please ensure the donor exists in the system or check the donor name spelling.";
                                continue 2;
                            }
                            
                            // Get project ID if provided
                            $projectId = null;
                            $projectValue = $getColumnValue($rowData, ['project id', 'projectid', 'project_id', 'project']);
                            if (!empty($projectValue)) {
                                if (is_numeric($projectValue)) {
                                    $projectId = (int)$projectValue;
                                } else {
                                    // Try to find project by name
                                    $projectStmt = $pdo->prepare("SELECT id FROM projects WHERE title LIKE ? LIMIT 1");
                                    $projectStmt->execute(['%' . trim($projectValue) . '%']);
                                    $project = $projectStmt->fetch();
                                    if ($project) $projectId = (int)$project['id'];
                                }
                            }
                            
                            // Check if grant exists
                            $checkStmt = $pdo->prepare("SELECT id FROM gms_grants WHERE grant_code = ?");
                            $checkStmt->execute([$grantCode]);
                            $existing = $checkStmt->fetch();
                            
                            // Get dates - try multiple formats
                            $startDate = null;
                            $startDateValue = $getColumnValue($rowData, ['start date', 'startdate', 'start_date', 'start']);
                            if (!empty($startDateValue)) {
                                $startDate = date('Y-m-d', strtotime($startDateValue));
                                if ($startDate === '1970-01-01') $startDate = null; // Invalid date
                            }
                            
                            $endDate = null;
                            $endDateValue = $getColumnValue($rowData, ['end date', 'enddate', 'end_date', 'end']);
                            if (!empty($endDateValue)) {
                                $endDate = date('Y-m-d', strtotime($endDateValue));
                                if ($endDate === '1970-01-01') $endDate = null; // Invalid date
                            }
                            
                            // Get budget - try multiple column names
                            $totalBudget = (float)($getColumnValue($rowData, [
                                'total budget', 'totalbudget', 'total_budget', 'budget', 'amount'
                            ]) ?: 0);
                            
                            // Get currency
                            $currency = $getColumnValue($rowData, ['currency', 'curr']) ?: 'USD';
                            
                            // Get status
                            $status = $getColumnValue($rowData, ['status', 'grant status']) ?: 'Pipeline';
                            
                            if ($existing) {
                                $stmt = $pdo->prepare("
                                    UPDATE gms_grants SET
                                        title = ?, donor_id = ?, project_id = ?, status = ?, start_date = ?, end_date = ?,
                                        total_budget = ?, currency = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $title,
                                    $donorId,
                                    $projectId,
                                    $status,
                                    $startDate,
                                    $endDate,
                                    $totalBudget,
                                    $currency,
                                    $existing['id']
                                ]);
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO gms_grants (grant_code, title, donor_id, project_id, status, start_date, end_date, total_budget, currency, created_by_user_id)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $grantCode,
                                    $title,
                                    $donorId,
                                    $projectId,
                                    $status,
                                    $startDate,
                                    $endDate,
                                    $totalBudget,
                                    $currency,
                                    $userId
                                ]);
                            }
                            $imported++;
                            break;
                            
                        case 'opportunities':
                            $oppCode = trim($rowData['opportunity code'] ?? '');
                            $title = trim($rowData['title'] ?? '');
                            if (empty($title)) continue 2;
                            
                            if (empty($oppCode)) {
                                $oppCode = 'OPP-' . time() . '-' . $rowIndex;
                            }
                            
                            // Get donor ID
                            $donorId = 0;
                            if (!empty($rowData['donor id'])) {
                                $donorId = (int)$rowData['donor id'];
                            } elseif (!empty($rowData['donor name'])) {
                                $donorStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ? LIMIT 1");
                                $donorStmt->execute([trim($rowData['donor name'])]);
                                $donor = $donorStmt->fetch();
                                if ($donor) $donorId = (int)$donor['id'];
                            }
                            
                            if ($donorId <= 0) {
                                $errors[] = "Row " . ($rowIndex + 2) . ": Invalid donor ID or name";
                                continue 2;
                            }
                            
                            $deadline = !empty($rowData['deadline']) ? date('Y-m-d H:i:s', strtotime($rowData['deadline'])) : null;
                            
                            $checkStmt = $pdo->prepare("SELECT id FROM gms_funding_opportunities WHERE opportunity_code = ?");
                            $checkStmt->execute([$oppCode]);
                            $existing = $checkStmt->fetch();
                            
                            if ($existing) {
                                $stmt = $pdo->prepare("
                                    UPDATE gms_funding_opportunities SET
                                        title = ?, donor_id = ?, submission_deadline = ?,
                                        estimated_budget_min = ?, estimated_budget_max = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $title,
                                    $donorId,
                                    $deadline,
                                    !empty($rowData['budget min']) ? (float)$rowData['budget min'] : null,
                                    !empty($rowData['budget max']) ? (float)$rowData['budget max'] : null,
                                    $existing['id']
                                ]);
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO gms_funding_opportunities (opportunity_code, title, donor_id, submission_deadline, estimated_budget_min, estimated_budget_max, status)
                                    VALUES (?, ?, ?, ?, ?, ?, 'Open')
                                ");
                                $stmt->execute([
                                    $oppCode,
                                    $title,
                                    $donorId,
                                    $deadline,
                                    !empty($rowData['budget min']) ? (float)$rowData['budget min'] : null,
                                    !empty($rowData['budget max']) ? (float)$rowData['budget max'] : null
                                ]);
                            }
                            $imported++;
                            break;
                            
                        case 'partners':
                            $partnerCode = trim($rowData['partner code'] ?? '');
                            $name = trim($rowData['name'] ?? '');
                            if (empty($name)) continue 2;
                            
                            if (empty($partnerCode)) {
                                $partnerCode = 'PARTNER-' . time() . '-' . $rowIndex;
                            }
                            
                            $checkStmt = $pdo->prepare("SELECT id FROM gms_partners WHERE partner_code = ?");
                            $checkStmt->execute([$partnerCode]);
                            $existing = $checkStmt->fetch();
                            
                            if ($existing) {
                                $stmt = $pdo->prepare("
                                    UPDATE gms_partners SET
                                        name = ?, partner_type = ?, contact_email = ?, contact_phone = ?,
                                        address = ?, country = ?, is_active = 1
                                    WHERE id = ?
                                ");
                                $stmt->execute([
                                    $name,
                                    $rowData['partner type'] ?? 'NGO',
                                    $rowData['contact email'] ?? null,
                                    $rowData['contact phone'] ?? null,
                                    $rowData['address'] ?? null,
                                    $rowData['country'] ?? null,
                                    $existing['id']
                                ]);
                            } else {
                                $stmt = $pdo->prepare("
                                    INSERT INTO gms_partners (partner_code, name, partner_type, contact_email, contact_phone, address, country, is_active)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                                ");
                                $stmt->execute([
                                    $partnerCode,
                                    $name,
                                    $rowData['partner type'] ?? 'NGO',
                                    $rowData['contact email'] ?? null,
                                    $rowData['contact phone'] ?? null,
                                    $rowData['address'] ?? null,
                                    $rowData['country'] ?? null
                                ]);
                            }
                            $imported++;
                            break;
                            
                        case 'reporting':
                            $reportType = trim($rowData['report type'] ?? '');
                            $reportNumber = trim($rowData['report number'] ?? '');
                            if (empty($reportType) || empty($reportNumber)) continue 2;
                            
                            // Get grant ID if provided
                            $grantId = null;
                            if (!empty($rowData['grant id'])) {
                                $grantId = (int)$rowData['grant id'];
                            } elseif (!empty($rowData['grant code'])) {
                                $grantStmt = $pdo->prepare("SELECT id FROM gms_grants WHERE grant_code = ? LIMIT 1");
                                $grantStmt->execute([trim($rowData['grant code'])]);
                                $grant = $grantStmt->fetch();
                                if ($grant) $grantId = (int)$grant['id'];
                            }
                            
                            if (!$grantId) {
                                $errors[] = "Row " . ($rowIndex + 2) . ": Grant ID or code required";
                                continue 2;
                            }
                            
                            $dueDate = !empty($rowData['due date']) ? date('Y-m-d', strtotime($rowData['due date'])) : null;
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO gms_reporting_schedule (grant_id, report_type, report_number, due_date, status)
                                VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    due_date = VALUES(due_date),
                                    status = VALUES(status)
                            ");
                            $stmt->execute([
                                $grantId,
                                $reportType,
                                $reportNumber,
                                $dueDate,
                                $rowData['status'] ?? 'Pending'
                            ]);
                            $imported++;
                            break;
                            

                        case 'workplans':
                            // Expect Grant Code (preferred) or Grant ID
                            $grantCode = trim($rowData['grant code'] ?? $rowData['grant_code'] ?? '');
                            $grantId = (int)($rowData['grant id'] ?? $rowData['grant_id'] ?? 0);
                            if ($grantId <= 0 && $grantCode !== '') {
                                $gs = $pdo->prepare("SELECT id FROM gms_grants WHERE grant_code = ? LIMIT 1");
                                $gs->execute([$grantCode]);
                                $g = $gs->fetch(PDO::FETCH_ASSOC);
                                $grantId = (int)($g['id'] ?? 0);
                            }
                            if ($grantId <= 0) {
                                throw new Exception("Workplan import requires Grant Code or Grant ID.");
                            }

                            $activityName = trim($rowData['activity name'] ?? $rowData['activity_name'] ?? '');
                            if ($activityName === '') continue 2;

                            $activityCode = trim($rowData['activity code'] ?? $rowData['activity_code'] ?? '');
                            if ($activityCode === '') $activityCode = 'ACT-' . date('YmdHis');

                            $startDate = $rowData['start date'] ?? $rowData['start_date'] ?? null;
                            $endDate = $rowData['end date'] ?? $rowData['end_date'] ?? null;

                            $status = $rowData['status'] ?? 'Planned';
                            $progress = $rowData['progress'] ?? $rowData['progress percentage'] ?? $rowData['progress_percentage'] ?? 0;

                            $responsiblePerson = $rowData['responsible person'] ?? $rowData['responsible_person'] ?? null;
                            $locationName = $rowData['location name'] ?? $rowData['location_name'] ?? null;

                            $stmt = $pdo->prepare("
                                INSERT INTO gms_work_plans
                                    (grant_id, workplan_register_id, activity_code, activity_name, start_date, end_date, status, progress_percentage, responsible_person, responsible_email, location_name, result_output, indicator_text, target_quantity, target_unit, means_of_verification, dependencies, budget_amount, budget_currency, wbs_code, activity_sequence, notes)
                                VALUES
                                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $grantId,
                                $registerId,
                                $activityCode,
                                $activityName,
                                $startDate ?: null,
                                $endDate ?: null,
                                $status,
                                (float)$progress,
                                $responsiblePerson,
                                $responsibleEmail,
                                $locationName,
                                $resultOutput,
                                $indicatorText,
                                $targetQty,
                                $targetUnit,
                                $mov,
                                $dependencies,
                                $budgetAmount,
                                $budgetCurrency,
                                $wbsCode,
                                $activitySeq,
                                $notes
                            ]);
                            $imported++;
                            break;

                        case 'budgets':
                            $grantCode = trim($rowData['grant code'] ?? $rowData['grant_code'] ?? '');
                            $grantId = (int)($rowData['grant id'] ?? $rowData['grant_id'] ?? 0);
                            if ($grantId <= 0 && $grantCode !== '') {
                                $gs = $pdo->prepare("SELECT id FROM gms_grants WHERE grant_code = ? LIMIT 1");
                                $gs->execute([$grantCode]);
                                $g = $gs->fetch(PDO::FETCH_ASSOC);
                                $grantId = (int)($g['id'] ?? 0);
                            }
                            if ($grantId <= 0) {
                                throw new Exception("Budget import requires Grant Code or Grant ID.");
                            }

                            $category = $rowData['category'] ?? $rowData['budget category'] ?? $rowData['budget_category'] ?? 'Other';
                            $amount = $rowData['budget amount'] ?? $rowData['budget_amount'] ?? 0;

                            $stmt = $pdo->prepare("
                                INSERT INTO gms_grant_budgets
                                    (grant_id, budget_line_code, budget_category, description, budget_amount, currency)
                                VALUES
                                    (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $grantId,
                                $rowData['budget line code'] ?? $rowData['budget_line_code'] ?? null,
                                $category,
                                $rowData['description'] ?? null,
                                (float)$amount,
                                $rowData['currency'] ?? 'USD'
                            ]);
                            $imported++;
                            break;

                        case 'risks':
                            $grantCode = trim($rowData['grant code'] ?? $rowData['grant_code'] ?? '');
                            $grantId = (int)($rowData['grant id'] ?? $rowData['grant_id'] ?? 0);
                            if ($grantId <= 0 && $grantCode !== '') {
                                $gs = $pdo->prepare("SELECT id FROM gms_grants WHERE grant_code = ? LIMIT 1");
                                $gs->execute([$grantCode]);
                                $g = $gs->fetch(PDO::FETCH_ASSOC);
                                $grantId = (int)($g['id'] ?? 0);
                            }
                            if ($grantId <= 0) {
                                throw new Exception("Risk import requires Grant Code or Grant ID.");
                            }

                            $desc = trim($rowData['risk description'] ?? $rowData['risk_description'] ?? '');
                            if ($desc === '') continue 2;

                            $stmt = $pdo->prepare("
                                INSERT INTO gms_risk_register
                                    (grant_id, risk_code, risk_category, risk_description, likelihood, impact, risk_level, mitigation_measures, status, review_date, responsible_person)
                                VALUES
                                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $grantId,
                                $rowData['risk code'] ?? $rowData['risk_code'] ?? null,
                                $rowData['risk category'] ?? $rowData['risk_category'] ?? 'Operational',
                                $desc,
                                $rowData['likelihood'] ?? 'Medium',
                                $rowData['impact'] ?? 'Medium',
                                $rowData['risk level'] ?? $rowData['risk_level'] ?? 'Medium',
                                $rowData['mitigation measures'] ?? $rowData['mitigation_measures'] ?? null,
                                $rowData['status'] ?? 'Open',
                                $rowData['review date'] ?? $rowData['review_date'] ?? null,
                                $rowData['responsible person'] ?? $rowData['responsible_person'] ?? null
                            ]);
                            $imported++;
                            break;

                        case 'compliance':
                            $grantCode = trim($rowData['grant code'] ?? $rowData['grant_code'] ?? '');
                            $grantId = (int)($rowData['grant id'] ?? $rowData['grant_id'] ?? 0);
                            if ($grantId <= 0 && $grantCode !== '') {
                                $gs = $pdo->prepare("SELECT id FROM gms_grants WHERE grant_code = ? LIMIT 1");
                                $gs->execute([$grantCode]);
                                $g = $gs->fetch(PDO::FETCH_ASSOC);
                                $grantId = (int)($g['id'] ?? 0);
                            }
                            if ($grantId <= 0) {
                                throw new Exception("Compliance import requires Grant Code or Grant ID.");
                            }

                            $desc = trim($rowData['description'] ?? '');
                            if ($desc === '') continue 2;

                            $stmt = $pdo->prepare("
                                INSERT INTO gms_compliance_issues
                                    (grant_id, issue_code, issue_type, description, severity, identified_date, corrective_action, target_resolution_date, actual_resolution_date, status, notes, responsible_person)
                                VALUES
                                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $grantId,
                                $rowData['issue code'] ?? $rowData['issue_code'] ?? null,
                                $rowData['issue type'] ?? $rowData['issue_type'] ?? 'Other',
                                $desc,
                                $rowData['severity'] ?? 'Medium',
                                $rowData['identified date'] ?? $rowData['identified_date'] ?? date('Y-m-d'),
                                $rowData['corrective action'] ?? $rowData['corrective_action'] ?? null,
                                $rowData['target resolution date'] ?? $rowData['target_resolution_date'] ?? null,
                                $rowData['actual resolution date'] ?? $rowData['actual_resolution_date'] ?? null,
                                $rowData['status'] ?? 'Open',
                                $rowData['notes'] ?? null,
                                $rowData['responsible person'] ?? $rowData['responsible_person'] ?? null
                            ]);
                            $imported++;
                            break;


                        default:
                            throw new Exception("Unknown module: $module");
                    }
                } catch (PDOException $e) {
                    $errors[] = "Row " . ($rowIndex + 2) . ": " . $e->getMessage();
                    error_log("Import error on row " . ($rowIndex + 2) . ": " . $e->getMessage());
                } catch (Exception $e) {
                    $errors[] = "Row " . ($rowIndex + 2) . ": " . $e->getMessage();
                }
            }
            
            if ($imported > 0) {
                $response['success'] = true;
                $response['count'] = $imported;
                $response['message'] = "Successfully imported {$imported} record(s).";
                if (!empty($errors)) {
                    $response['message'] .= " " . count($errors) . " error(s) occurred.";
                    $response['errors'] = array_slice($errors, 0, 10); // Limit to first 10 errors
                }
            } else {
                $response['message'] = 'No records were imported. ' . (!empty($errors) ? implode('; ', array_slice($errors, 0, 5)) : 'Please check your file format.');
            }
            
        } catch (Exception $e) {
            $response['message'] = 'Import failed: ' . $e->getMessage();
            error_log("GMS Import Error: " . $e->getMessage());
        }
        
        echo json_encode($response);
        exit;
    }
}

// Handle form submissions - MUST be before header.php to allow redirects
$message = '';
$errorMsg = '';
$redirectAfterSave = false;
$redirectUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'save_donor':
                $donor_id = !empty($_POST['donor_id']) ? (int)$_POST['donor_id'] : null;
                $donor_other = trim($_POST['donor_other'] ?? '');
                
                // Handle donor selection - if "Other" is selected, create new donor
                $donor_name = '';
                $short_name = '';
                $donor_type = $_POST['donor_type'] ?? 'Other';
                
                if ($donor_id && $donor_id > 0 && $_POST['donor_id'] !== 'OTHER') {
                    // Existing donor selected - update it
                    try {
                        // Get existing donor info
                        $getStmt = $pdo->prepare("SELECT name, short_name, donor_type FROM gms_donors WHERE id = ?");
                        $getStmt->execute([$donor_id]);
                        $existing = $getStmt->fetch(PDO::FETCH_ASSOC);
                        if ($existing) {
                            $donor_name = $existing['name'];
                            $short_name = $existing['short_name'] ?? '';
                            $donor_type = $_POST['donor_type'] ?? $existing['donor_type'] ?? 'Other';
                        }
                    } catch (Exception $e) {
                        error_log("Error fetching donor: " . $e->getMessage());
                    }
                    
                    // Update existing donor
                    $stmt = $pdo->prepare("
                        UPDATE gms_donors SET
                            name = ?, short_name = ?, donor_type = ?, contact_name = ?,
                            contact_email = ?, contact_phone = ?, website = ?, address = ?,
                            country = ?, notes = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $donor_name ?: ($_POST['name'] ?? ''),
                        $_POST['short_name'] ?? $short_name,
                        $donor_type,
                        $_POST['contact_name'] ?? null,
                        $_POST['contact_email'] ?? null,
                        $_POST['contact_phone'] ?? null,
                        $_POST['website'] ?? null,
                        $_POST['address'] ?? null,
                        $_POST['country'] ?? null,
                        $_POST['notes'] ?? null,
                        isset($_POST['is_active']) ? 1 : 1,
                        $donor_id
                    ]);
                } elseif (!empty($donor_other) && ($_POST['donor_id'] === 'OTHER' || empty($donor_id))) {
                    // New donor from "Other" option
                    $donor_name = $donor_other;
                    $short_name = $_POST['short_name'] ?? $donor_other;
                    
                    // Check if donor already exists
                    try {
                        $checkStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ? LIMIT 1");
                        $checkStmt->execute([$donor_name]);
                        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            // Update existing
                            $stmt = $pdo->prepare("
                                UPDATE gms_donors SET
                                    short_name = ?, donor_type = ?, contact_name = ?,
                                    contact_email = ?, contact_phone = ?, website = ?, address = ?,
                                    country = ?, notes = ?, is_active = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([
                                $short_name,
                                $donor_type,
                                $_POST['contact_name'] ?? null,
                                $_POST['contact_email'] ?? null,
                                $_POST['contact_phone'] ?? null,
                                $_POST['website'] ?? null,
                                $_POST['address'] ?? null,
                                $_POST['country'] ?? null,
                                $_POST['notes'] ?? null,
                                isset($_POST['is_active']) ? 1 : 1,
                                $existing['id']
                            ]);
                        } else {
                            // Insert new donor
                            $stmt = $pdo->prepare("
                                INSERT INTO gms_donors (name, short_name, donor_type, contact_name, contact_email, contact_phone, website, address, country, notes, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $donor_name,
                                $short_name,
                                $donor_type,
                                $_POST['contact_name'] ?? null,
                                $_POST['contact_email'] ?? null,
                                $_POST['contact_phone'] ?? null,
                                $_POST['website'] ?? null,
                                $_POST['address'] ?? null,
                                $_POST['country'] ?? null,
                                $_POST['notes'] ?? null,
                                isset($_POST['is_active']) ? 1 : 1
                            ]);
                        }
                    } catch (Exception $e) {
                        error_log("Error saving donor: " . $e->getMessage());
                    }
                } else {
                    // Fallback to old method if donor_id is provided directly
                    $donor_name = $_POST['name'] ?? '';
                    $short_name = $_POST['short_name'] ?? '';
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO gms_donors (name, short_name, donor_type, contact_name, contact_email, contact_phone, website, address, country, notes, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $donor_name,
                        $short_name,
                        $donor_type,
                        $_POST['contact_name'] ?? null,
                        $_POST['contact_email'] ?? null,
                        $_POST['contact_phone'] ?? null,
                        $_POST['website'] ?? null,
                        $_POST['address'] ?? null,
                        $_POST['country'] ?? null,
                        $_POST['notes'] ?? null,
                        isset($_POST['is_active']) ? 1 : 1
                    ]);
                }
                $message = "Donor saved successfully!";
                $redirectAfterSave = true;
                $redirectUrl = "?view=donors&message=" . urlencode($message);
                break;
                
            case 'delete_grant':
                $grant_id = (int)($_POST['grant_id'] ?? 0);
                if ($grant_id > 0) {
                    try {
                    $stmt = $pdo->prepare("DELETE FROM gms_grants WHERE id = ?");
                    $stmt->execute([$grant_id]);
                    $message = "Grant deleted successfully!";
                        $redirectAfterSave = true;
                        $redirectUrl = "?view=grants&message=" . urlencode($message);
                    } catch (PDOException $e) {
                        $errorMsg = "Error deleting grant: " . $e->getMessage();
                        error_log("Delete grant error: " . $e->getMessage());
                    }
                } else {
                    $errorMsg = "Invalid grant ID.";
                }
                break;
                
            case 'delete_donor':
                $donor_id = (int)($_POST['donor_id'] ?? 0);
                if ($donor_id > 0) {
                    $stmt = $pdo->prepare("UPDATE gms_donors SET is_active = 0 WHERE id = ?");
                    $stmt->execute([$donor_id]);
                    $message = "Donor deactivated successfully!";
                }
                break;
                
            case 'save_grant':
                $grant_code = $_POST['grant_code'] ?? 'GRANT-' . time();
                $grant_id = !empty($_POST['grant_id']) ? (int)$_POST['grant_id'] : null;
                
                // Handle "Other" donor option - create new donor if needed
                $donor_id = (int)($_POST['donor_id'] ?? 0);
                $donor_other = trim($_POST['donor_other'] ?? '');
                
                if ($donor_id === 0 || (!empty($donor_other) && $_POST['donor_id'] === 'OTHER')) {
                    // Create new donor from "Other" option
                    if (!empty($donor_other)) {
                        try {
                            // Check if donor already exists
                            $checkStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ? LIMIT 1");
                            $checkStmt->execute([$donor_other]);
                            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($existing) {
                                $donor_id = (int)$existing['id'];
                            } else {
                                // Create new donor
                                $insertStmt = $pdo->prepare("INSERT INTO gms_donors (name, short_name, donor_type, is_active) VALUES (?, ?, 'Other', 1)");
                                $insertStmt->execute([$donor_other, $donor_other]);
                                $donor_id = (int)$pdo->lastInsertId();
                                error_log("Grants: Auto-created new donor from grant form: " . $donor_other);
                            }
                        } catch (Exception $e) {
                            error_log("Error creating donor in save_grant: " . $e->getMessage());
                        }
                    }
                }
                
                if ($grant_id) {
                    // Update existing grant
                    $stmt = $pdo->prepare("
                        UPDATE gms_grants SET
                            grant_number = ?, title = ?, donor_id = ?, opportunity_id = ?, project_id = ?,
                            agreement_type = ?, status = ?, start_date = ?, end_date = ?, total_budget = ?,
                            currency = ?, exchange_rate = ?, co_funding_required = ?, reporting_frequency = ?,
                            special_conditions = ?, risk_level = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['grant_number'] ?? null,
                        $_POST['title'] ?? '',
                        $donor_id,
                        !empty($_POST['opportunity_id']) ? (int)$_POST['opportunity_id'] : null,
                        !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null,
                        $_POST['agreement_type'] ?? 'Grant',
                        $_POST['status'] ?? 'Pipeline',
                        !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                        !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                        (float)($_POST['total_budget'] ?? 0),
                        $_POST['currency'] ?? 'USD',
                        (float)($_POST['exchange_rate'] ?? 1.0),
                        (float)($_POST['co_funding_required'] ?? 0),
                        $_POST['reporting_frequency'] ?? 'Quarterly',
                        $_POST['special_conditions'] ?? null,
                        $_POST['risk_level'] ?? 'Medium',
                        $grant_id
                    ]);
                } else {
                    // Insert new grant
                    $stmt = $pdo->prepare("
                        INSERT INTO gms_grants (
                            grant_code, grant_number, title, donor_id, opportunity_id, project_id,
                            agreement_type, status, start_date, end_date, total_budget, currency,
                            exchange_rate, co_funding_required, reporting_frequency, special_conditions,
                            risk_level, created_by_user_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $grant_code,
                        $_POST['grant_number'] ?? null,
                        $_POST['title'] ?? '',
                        $donor_id,
                        !empty($_POST['opportunity_id']) ? (int)$_POST['opportunity_id'] : null,
                        !empty($_POST['project_id']) ? (int)$_POST['project_id'] : null,
                        $_POST['agreement_type'] ?? 'Grant',
                        $_POST['status'] ?? 'Pipeline',
                        !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                        !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                        (float)($_POST['total_budget'] ?? 0),
                        $_POST['currency'] ?? 'USD',
                        (float)($_POST['exchange_rate'] ?? 1.0),
                        (float)($_POST['co_funding_required'] ?? 0),
                        $_POST['reporting_frequency'] ?? 'Quarterly',
                        $_POST['special_conditions'] ?? null,
                        $_POST['risk_level'] ?? 'Medium',
                        current_user()['id'] ?? null
                    ]);
                    $grant_id = $pdo->lastInsertId();
                }
                
                // Auto-generate reporting schedule if grant is active
                if (($_POST['status'] ?? '') === 'Active' && !empty($_POST['start_date']) && !empty($_POST['end_date'])) {
                    $frequency = $_POST['reporting_frequency'] ?? 'Quarterly';
                    $startDate = new DateTime($_POST['start_date']);
                    $endDate = new DateTime($_POST['end_date']);
                    $reportNumber = 1;
                    
                    // Generate reports based on frequency
                    $currentDate = clone $startDate;
                    while ($currentDate < $endDate) {
                        $dueDate = clone $currentDate;
                        switch ($frequency) {
                            case 'Monthly':
                                $dueDate->modify('+1 month');
                                break;
                            case 'Quarterly':
                                $dueDate->modify('+3 months');
                                break;
                            case 'Semi-Annual':
                                $dueDate->modify('+6 months');
                                break;
                            case 'Annual':
                                $dueDate->modify('+1 year');
                                break;
                            default:
                                $dueDate->modify('+3 months');
                        }
                        
                        if ($dueDate <= $endDate) {
                            $reportStmt = $pdo->prepare("
                                INSERT INTO gms_reporting_schedule (grant_id, report_type, report_number, due_date, status)
                                VALUES (?, 'Combined', ?, ?, 'Pending')
                            ");
                            $reportStmt->execute([$grant_id, $reportNumber, $dueDate->format('Y-m-d')]);
                            $reportNumber++;
                        }
                        $currentDate = $dueDate;
                    }
                }
                
                $message = "Grant saved successfully!";
                $redirectAfterSave = true;
                $redirectUrl = "?view=grants&message=" . urlencode($message);
                break;
                
            case 'save_opportunity':
                $opp_id = !empty($_POST['opportunity_id']) ? (int)$_POST['opportunity_id'] : null;
                
                // Handle donor - if it's a predefined donor code, create or find the donor
                $donor_id = 0;
                $donor_value = $_POST['donor_id'] ?? '';
                if ($donor_value === 'OTHER') {
                    $donor_other = trim($_POST['donor_other'] ?? '');
                    if (!empty($donor_other)) {
                        // Check if donor exists
                        $donorStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name = ? LIMIT 1");
                        $donorStmt->execute([$donor_other]);
                        $existingDonor = $donorStmt->fetch();
                        if ($existingDonor) {
                            $donor_id = (int)$existingDonor['id'];
                        } else {
                            // Create new donor
                            $insertDonor = $pdo->prepare("INSERT INTO gms_donors (name, donor_type, is_active) VALUES (?, 'Other', 1)");
                            $insertDonor->execute([$donor_other]);
                            $donor_id = (int)$pdo->lastInsertId();
                        }
                    }
                } elseif (!empty($donor_value) && !is_numeric($donor_value)) {
                    // Predefined donor code (UN_UNDP, USAID, etc.)
                    $donorNameMap = [
                        'UN_UNDP' => 'UNDP', 'UN_UNICEF' => 'UNICEF', 'UN_UNHCR' => 'UNHCR', 'UN_WFP' => 'WFP',
                        'UN_WHO' => 'WHO', 'UN_UNFPA' => 'UNFPA', 'UN_UNOCHA' => 'UNOCHA', 'UN_UNESCO' => 'UNESCO',
                        'UN_FAO' => 'FAO', 'UN_ILO' => 'ILO', 'UN_UNWomen' => 'UN Women', 'UN_UNEP' => 'UNEP',
                        'UN_UNIDO' => 'UNIDO', 'UN_UNHABITAT' => 'UN-Habitat',
                        'USAID' => 'USAID', 'DFID' => 'DFID/FCDO', 'EU' => 'European Union', 'ECHO' => 'ECHO',
                        'GIZ' => 'GIZ', 'SIDA' => 'SIDA', 'NORAD' => 'NORAD', 'DANIDA' => 'DANIDA',
                        'CIDA' => 'CIDA', 'AUSAID' => 'AUSAID', 'JICA' => 'JICA', 'AFD' => 'AFD', 'SDC' => 'SDC',
                        'EHF' => 'EHF', 'CERF' => 'CERF', 'CBPF' => 'CBPF', 'START' => 'START Network', 'GFF' => 'GFF',
                        'GATES' => 'Bill & Melinda Gates Foundation', 'FORD' => 'Ford Foundation',
                        'ROCKEFELLER' => 'Rockefeller Foundation', 'OPEN_SOCIETY' => 'Open Society Foundations',
                        'HEWLETT' => 'Hewlett Foundation', 'MACARTHUR' => 'MacArthur Foundation'
                    ];
                    $donorName = $donorNameMap[$donor_value] ?? $donor_value;
                    $donorStmt = $pdo->prepare("SELECT id FROM gms_donors WHERE name LIKE ? LIMIT 1");
                    $donorStmt->execute(['%' . $donorName . '%']);
                    $existingDonor = $donorStmt->fetch();
                    if ($existingDonor) {
                        $donor_id = (int)$existingDonor['id'];
                    } else {
                        $donorType = (strpos($donor_value, 'UN_') === 0) ? 'UN Agency' : 
                                     (in_array($donor_value, ['EHF', 'CERF', 'CBPF', 'START', 'GFF']) ? 'Pooled Fund' :
                                     (in_array($donor_value, ['GATES', 'FORD', 'ROCKEFELLER', 'OPEN_SOCIETY', 'HEWLETT', 'MACARTHUR']) ? 'Foundation' : 'Bilateral Donor'));
                        $insertDonor = $pdo->prepare("INSERT INTO gms_donors (name, donor_type, is_active) VALUES (?, ?, 1)");
                        $insertDonor->execute([$donorName, $donorType]);
                        $donor_id = (int)$pdo->lastInsertId();
                    }
                } else {
                    $donor_id = (int)$donor_value;
                }
                
                // Calculate days remaining
                $announcementDate = $_POST['announcement_date'] ?? null;
                $submissionDeadline = $_POST['submission_deadline'] ?? null;
                $daysRemaining = null;
                $deadlinePassed = 0;
                
                if ($announcementDate && $submissionDeadline) {
                    $announcement = new DateTime($announcementDate);
                    $deadline = new DateTime($submissionDeadline);
                    $now = new DateTime();
                    $diffTime = $deadline->getTimestamp() - $now->getTimestamp();
                    $daysRemaining = (int)ceil($diffTime / (60 * 60 * 24));
                    if ($daysRemaining < 0) {
                        $deadlinePassed = 1;
                        $daysRemaining = 0;
                    }
                }
                
                // Determine status based on deadline
                $status = $_POST['status'] ?? 'Open';
                if ($deadlinePassed && $status === 'Open') {
                    $status = 'Closed';
                }
                
                // Handle currency
                $currency = $_POST['currency'] ?? 'USD';
                $currencyOther = null;
                if ($currency === 'OTHER') {
                    $currencyOther = trim($_POST['currency_other'] ?? '');
                    $currency = !empty($currencyOther) ? $currencyOther : 'USD';
                }
                
                if ($opp_id) {
                    $stmt = $pdo->prepare("
                        UPDATE gms_funding_opportunities SET
                            donor_id = ?, opportunity_code = ?, title = ?, description = ?,
                            announcement_date = ?, funding_window_start = ?, funding_window_end = ?, 
                            submission_deadline = ?, opportunity_website = ?, call_summary = ?,
                            estimated_budget_min = ?, estimated_budget_max = ?, currency = ?, currency_other = ?,
                            status = ?, go_no_go_decision = ?, go_no_go_date = ?, go_no_go_notes = ?,
                            responsible_person_name = ?, responsible_person_position = ?, responsible_person_email = ?,
                            proposal_status = ?, ai_summary = ?, days_remaining_calculated = ?, deadline_passed = ?,
                            donor_other = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $donor_id,
                        $_POST['opportunity_code'] ?? null,
                        $_POST['title'] ?? '',
                        $_POST['description'] ?? null,
                        !empty($_POST['announcement_date']) ? $_POST['announcement_date'] : null,
                        !empty($_POST['funding_window_start']) ? $_POST['funding_window_start'] : null,
                        !empty($_POST['funding_window_end']) ? $_POST['funding_window_end'] : null,
                        !empty($_POST['submission_deadline']) ? $_POST['submission_deadline'] : null,
                        $_POST['opportunity_website'] ?? null,
                        $_POST['call_summary'] ?? null,
                        !empty($_POST['estimated_budget_min']) ? (float)$_POST['estimated_budget_min'] : null,
                        !empty($_POST['estimated_budget_max']) ? (float)$_POST['estimated_budget_max'] : null,
                        $currency,
                        $currencyOther,
                        $status,
                        $_POST['go_no_go_decision'] ?? 'Pending',
                        !empty($_POST['go_no_go_date']) ? $_POST['go_no_go_date'] : null,
                        $_POST['go_no_go_notes'] ?? null,
                        $_POST['responsible_person_name'] ?? null,
                        $_POST['responsible_person_position'] ?? null,
                        $_POST['responsible_person_email'] ?? null,
                        $_POST['proposal_status'] ?? 'Not Started',
                        $_POST['ai_summary'] ?? null,
                        $daysRemaining,
                        $deadlinePassed,
                        ($_POST['donor_id'] === 'OTHER') ? $_POST['donor_other'] : null,
                        $opp_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO gms_funding_opportunities (
                            donor_id, opportunity_code, title, description, announcement_date,
                            funding_window_start, funding_window_end, submission_deadline,
                            opportunity_website, call_summary, estimated_budget_min,
                            estimated_budget_max, currency, currency_other, status,
                            go_no_go_decision, go_no_go_date, go_no_go_notes,
                            responsible_person_name, responsible_person_position, responsible_person_email,
                            proposal_status, ai_summary, days_remaining_calculated, deadline_passed, donor_other
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $donor_id,
                        $_POST['opportunity_code'] ?? null,
                        $_POST['title'] ?? '',
                        $_POST['description'] ?? null,
                        !empty($_POST['announcement_date']) ? $_POST['announcement_date'] : null,
                        !empty($_POST['funding_window_start']) ? $_POST['funding_window_start'] : null,
                        !empty($_POST['funding_window_end']) ? $_POST['funding_window_end'] : null,
                        !empty($_POST['submission_deadline']) ? $_POST['submission_deadline'] : null,
                        $_POST['opportunity_website'] ?? null,
                        $_POST['call_summary'] ?? null,
                        !empty($_POST['estimated_budget_min']) ? (float)$_POST['estimated_budget_min'] : null,
                        !empty($_POST['estimated_budget_max']) ? (float)$_POST['estimated_budget_max'] : null,
                        $currency,
                        $currencyOther,
                        $status,
                        $_POST['go_no_go_decision'] ?? 'Pending',
                        !empty($_POST['go_no_go_date']) ? $_POST['go_no_go_date'] : null,
                        $_POST['go_no_go_notes'] ?? null,
                        $_POST['responsible_person_name'] ?? null,
                        $_POST['responsible_person_position'] ?? null,
                        $_POST['responsible_person_email'] ?? null,
                        $_POST['proposal_status'] ?? 'Not Started',
                        $_POST['ai_summary'] ?? null,
                        $daysRemaining,
                        $deadlinePassed,
                        ($_POST['donor_id'] === 'OTHER') ? $_POST['donor_other'] : null
                    ]);
                    $opp_id = (int)$pdo->lastInsertId();
                }
                
                // Handle file uploads for opportunity documents
                if (!empty($_FILES['opportunity_files']['name'][0]) && $opp_id) {
                    $uploadDir = __DIR__ . '/../uploads/opportunities/';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    
                    foreach ($_FILES['opportunity_files']['name'] as $key => $filename) {
                        if ($_FILES['opportunity_files']['error'][$key] === UPLOAD_ERR_OK) {
                            $fileSize = $_FILES['opportunity_files']['size'][$key];
                            if ($fileSize > 10 * 1024 * 1024) continue; // Skip files > 10MB
                            
                            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                            $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                            if (!in_array($ext, $allowedExts)) continue;
                            
                            $newFilename = 'opp_' . $opp_id . '_' . time() . '_' . $key . '.' . $ext;
                            $destPath = $uploadDir . $newFilename;
                            
                            if (move_uploaded_file($_FILES['opportunity_files']['tmp_name'][$key], $destPath)) {
                                $fileStmt = $pdo->prepare("
                                    INSERT INTO gms_opportunity_files (opportunity_id, file_type, file_name, file_path, file_size, mime_type, uploaded_by_user_id)
                                    VALUES (?, 'opportunity_document', ?, ?, ?, ?, ?)
                                ");
                                $fileStmt->execute([
                                    $opp_id,
                                    $filename,
                                    'uploads/opportunities/' . $newFilename,
                                    $fileSize,
                                    $_FILES['opportunity_files']['type'][$key],
                                    $user['id'] ?? null
                                ]);
                            }
                        }
                    }
                }
                
                // Handle file uploads for proposal documents
                if (!empty($_FILES['proposal_files']['name'][0]) && $opp_id) {
                    $uploadDir = __DIR__ . '/../uploads/proposals/';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0755, true);
                    }
                    
                    foreach ($_FILES['proposal_files']['name'] as $key => $filename) {
                        if ($_FILES['proposal_files']['error'][$key] === UPLOAD_ERR_OK) {
                            $fileSize = $_FILES['proposal_files']['size'][$key];
                            if ($fileSize > 10 * 1024 * 1024) continue;
                            
                            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                            $allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
                            if (!in_array($ext, $allowedExts)) continue;
                            
                            $newFilename = 'proposal_' . $opp_id . '_' . time() . '_' . $key . '.' . $ext;
                            $destPath = $uploadDir . $newFilename;
                            
                            if (move_uploaded_file($_FILES['proposal_files']['tmp_name'][$key], $destPath)) {
                                $fileStmt = $pdo->prepare("
                                    INSERT INTO gms_opportunity_files (opportunity_id, file_type, file_name, file_path, file_size, mime_type, uploaded_by_user_id)
                                    VALUES (?, 'proposal_document', ?, ?, ?, ?, ?)
                                ");
                                $fileStmt->execute([
                                    $opp_id,
                                    $filename,
                                    'uploads/proposals/' . $newFilename,
                                    $fileSize,
                                    $_FILES['proposal_files']['type'][$key],
                                    $user['id'] ?? null
                                ]);
                            }
                        }
                    }
                }
                
                // Send notification to responsible person if Go decision and email provided
                if ($_POST['go_no_go_decision'] === 'Go' && !empty($_POST['responsible_person_email'])) {
                    $email = $_POST['responsible_person_email'];
                    $name = $_POST['responsible_person_name'] ?? 'Team Member';
                    $title = $_POST['title'] ?? 'Funding Opportunity';
                    $deadline = $_POST['submission_deadline'] ?? 'TBD';
                    $opportunityCode = $_POST['opportunity_code'] ?? 'N/A';
                    $donorName = '';
                    
                    // Get donor name
                    if ($donor_id > 0) {
                        $donorStmt = $pdo->prepare("SELECT name FROM gms_donors WHERE id = ?");
                        $donorStmt->execute([$donor_id]);
                        $donor = $donorStmt->fetch(PDO::FETCH_ASSOC);
                        $donorName = $donor['name'] ?? ($_POST['donor_other'] ?? 'Unknown Donor');
                    } else {
                        $donorName = $_POST['donor_other'] ?? 'Unknown Donor';
                    }
                    
                    // Send email notification
                    $subject = "🎯 New Proposal Assignment: " . $title;
                    $htmlMessage = "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #667eea; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                        .content { background: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
                        .info-box { background: white; padding: 15px; margin: 15px 0; border-left: 4px solid #667eea; border-radius: 4px; }
                        .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; border-radius: 0 0 5px 5px; }
                        .btn { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 15px 0; }
                    </style></head><body>";
                    $htmlMessage .= "<div class='container'>";
                    $htmlMessage .= "<div class='header'><h2>🏦 Grant Management System</h2><h3>" . htmlspecialchars($subject) . "</h3></div>";
                    $htmlMessage .= "<div class='content'>";
                    $htmlMessage .= "<p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>";
                    $htmlMessage .= "<p>You have been assigned as the <strong>responsible person</strong> for preparing a proposal for the following funding opportunity:</p>";
                    $htmlMessage .= "<div class='info-box'>";
                    $htmlMessage .= "<p><strong>Opportunity Code:</strong> " . htmlspecialchars($opportunityCode) . "</p>";
                    $htmlMessage .= "<p><strong>Title:</strong> " . htmlspecialchars($title) . "</p>";
                    $htmlMessage .= "<p><strong>Donor:</strong> " . htmlspecialchars($donorName) . "</p>";
                    $htmlMessage .= "<p><strong>Submission Deadline:</strong> " . htmlspecialchars($deadline) . "</p>";
                    if (!empty($_POST['estimated_budget_min']) || !empty($_POST['estimated_budget_max'])) {
                        $budget = '';
                        if (!empty($_POST['estimated_budget_min']) && !empty($_POST['estimated_budget_max'])) {
                            $budget = number_format($_POST['estimated_budget_min'], 2) . " - " . number_format($_POST['estimated_budget_max'], 2);
                        } elseif (!empty($_POST['estimated_budget_max'])) {
                            $budget = "Up to " . number_format($_POST['estimated_budget_max'], 2);
                        } elseif (!empty($_POST['estimated_budget_min'])) {
                            $budget = "From " . number_format($_POST['estimated_budget_min'], 2);
                        }
                        $currency = $_POST['currency'] ?? 'USD';
                        $htmlMessage .= "<p><strong>Estimated Budget:</strong> " . $budget . " " . htmlspecialchars($currency) . "</p>";
                    }
                    $htmlMessage .= "</div>";
                    $htmlMessage .= "<p>Please log in to the <strong>Grant Management System</strong> to view full details and start working on the proposal.</p>";
                    $htmlMessage .= "<p><a href='" . (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/pages/grants.php?view=opportunities&opportunity_id=" . $opp_id . "' class='btn'>View Opportunity Details</a></p>";
                    $htmlMessage .= "<p><strong>Your Role:</strong> " . htmlspecialchars($_POST['responsible_person_position'] ?? 'Proposal Writer') . "</p>";
                    $htmlMessage .= "</div>";
                    $htmlMessage .= "<div class='footer'><p><em>This is an automated notification from the Grant Management System.</em></p></div>";
                    $htmlMessage .= "</div></body></html>";
                    
                    // Send email using PHP mail function
                    $currentUser = current_user();
                    $fromEmail = $currentUser['email'] ?? 'noreply@system.local';
                    $fromName = $currentUser['name'] ?? $currentUser['full_name'] ?? 'Grant Management System';
                    
                    $headers = [];
                    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
                    $headers[] = 'Reply-To: ' . $fromEmail;
                    $headers[] = 'MIME-Version: 1.0';
                    $headers[] = 'Content-Type: text/html; charset=UTF-8';
                    
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $emailSent = @mail($email, $subject, $htmlMessage, implode("\r\n", $headers));
                        if ($emailSent) {
                            error_log("Email notification sent successfully to: " . $email . " - Subject: " . $subject);
                        } else {
                            error_log("Email notification attempted but may have failed to: " . $email . " - Subject: " . $subject);
                        }
                    } else {
                        error_log("Invalid email address for notification: " . $email);
                    }
                }
                
                // If proposal status is "Succeeded", automatically register project
                $message = "Funding opportunity saved successfully!";
                if (isset($_POST['proposal_status']) && $_POST['proposal_status'] === 'Succeeded' && $opp_id) {
                    try {
                        // Get opportunity details
                        $oppStmt = $pdo->prepare("SELECT * FROM gms_funding_opportunities WHERE id = ?");
                        $oppStmt->execute([$opp_id]);
                        $opportunity = $oppStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($opportunity) {
                            // Check if project already exists for this opportunity
                            $checkStmt = $pdo->prepare("SELECT id FROM projects WHERE code = ? OR title = ? LIMIT 1");
                            $checkStmt->execute([
                                $opportunity['opportunity_code'] ?? 'PROJ-' . $opp_id,
                                $opportunity['title']
                            ]);
                            $existingProject = $checkStmt->fetch();
                            
                            if (!$existingProject) {
                                // Get donor name
                                $donorStmt = $pdo->prepare("SELECT name FROM gms_donors WHERE id = ?");
                                $donorStmt->execute([$opportunity['donor_id']]);
                                $donor = $donorStmt->fetch(PDO::FETCH_ASSOC);
                                $donorName = $donor['name'] ?? ($opportunity['donor_other'] ?? 'Unknown Donor');
                                
                                // Prepare project data
                                $projectData = [
                                    'title' => $opportunity['title'],
                                    'description' => $opportunity['description'] ?? $opportunity['call_summary'] ?? '',
                                    'code' => $opportunity['opportunity_code'] ?? 'PROJ-' . $opp_id,
                                    'location' => '',
                                    'start_date' => $opportunity['funding_window_start'] ?? null,
                                    'end_date' => $opportunity['funding_window_end'] ?? null,
                                    'budget' => $opportunity['estimated_budget_max'] ?? $opportunity['estimated_budget_min'] ?? 0,
                                    'status' => 'active',
                                    'donor' => $donorName,
                                    'donor_ref' => $opportunity['opportunity_code'] ?? '',
                                    'project_type' => 'Grant',
                                    'ip_name' => 'Internal',
                                    'owner_name' => $opportunity['responsible_person_name'] ?? '',
                                    'owner_email' => $opportunity['responsible_person_email'] ?? ''
                                ];
                                
                                // Register project using helper function if available
                                $projectId = null;
                                if (function_exists('register_project_across_apps')) {
                                    $result = register_project_across_apps($projectData);
                                    if ($result['success']) {
                                        $projectId = $result['projectId'];
                                    }
                                }
                                
                                // Fallback: Direct project insertion if helper function doesn't exist or failed
                                if (!$projectId) {
                                    try {
                                        $projStmt = $pdo->prepare("
                                            INSERT INTO projects (code, title, project_type, donor, donor_ref, status, total_fund_usd, start_date, end_date, owner_name, owner_email, created_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                        ");
                                        $projStmt->execute([
                                            $projectData['code'],
                                            $projectData['title'],
                                            $projectData['project_type'],
                                            $projectData['donor'],
                                            $projectData['donor_ref'],
                                            $projectData['status'],
                                            $projectData['budget'],
                                            $projectData['start_date'],
                                            $projectData['end_date'],
                                            $projectData['owner_name'],
                                            $projectData['owner_email']
                                        ]);
                                        $projectId = $pdo->lastInsertId();
                                    } catch (Exception $e) {
                                        error_log("Error creating project: " . $e->getMessage());
                                    }
                                }
                                
                                // Link opportunity to project if project was created
                                if ($projectId) {
                                    try {
                                        // Add project_id column if it doesn't exist
                                        $pdo->exec("ALTER TABLE gms_funding_opportunities ADD COLUMN IF NOT EXISTS project_id INT NULL");
                                    } catch (Exception $e) {
                                        // Column may already exist
                                    }
                                    
                                    $linkStmt = $pdo->prepare("UPDATE gms_funding_opportunities SET project_id = ? WHERE id = ?");
                                    $linkStmt->execute([$projectId, $opp_id]);
                                    
                                    // Link to planning module - create initial planning entry if planning table exists
                                    try {
                                        $planningCheck = $pdo->query("SHOW TABLES LIKE 'planning%' OR SHOW TABLES LIKE 'project_plans%'");
                                        if ($planningCheck) {
                                            // Check if planning table exists
                                            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                                            $hasPlanning = false;
                                            foreach ($tables as $table) {
                                                if (stripos($table, 'planning') !== false || stripos($table, 'project_plan') !== false) {
                                                    $hasPlanning = true;
                                                    break;
                                                }
                                            }
                                            
                                            if ($hasPlanning) {
                                                // Try to create planning entry
                                                try {
                                                    $planStmt = $pdo->prepare("
                                                        INSERT INTO planning (project_id, title, description, status, created_at)
                                                        VALUES (?, ?, ?, 'draft', NOW())
                                                        ON DUPLICATE KEY UPDATE updated_at = NOW()
                                                    ");
                                                    $planStmt->execute([
                                                        $projectId,
                                                        $projectData['title'],
                                                        $projectData['description']
                                                    ]);
                                                } catch (Exception $e) {
                                                    // Planning table might have different structure, try alternative
                                                    try {
                                                        $planStmt = $pdo->prepare("
                                                            INSERT INTO project_plans (project_id, plan_title, plan_description, status, created_at)
                                                            VALUES (?, ?, ?, 'draft', NOW())
                                                            ON DUPLICATE KEY UPDATE updated_at = NOW()
                                                        ");
                                                        $planStmt->execute([
                                                            $projectId,
                                                            $projectData['title'],
                                                            $projectData['description']
                                                        ]);
                                                    } catch (Exception $e2) {
                                                        // Planning module might not be available
                                                        error_log("Planning module integration: " . $e2->getMessage());
                                                    }
                                                }
                                            }
                                        }
                                    } catch (Exception $e) {
                                        error_log("Planning module check: " . $e->getMessage());
                                    }
                                    
                                    $message = "Funding opportunity saved successfully! ✅ Project automatically registered in project system (ID: " . $projectId . ") and linked to planning module. <a href='../projects.php?project_id=" . $projectId . "'>View Project</a> | <a href='../planning.php?project_id=" . $projectId . "'>Go to Planning</a>";
                                } else {
                                    $message = "Funding opportunity saved successfully! ⚠️ Project registration attempted but encountered an error. Please register the project manually.";
                                }
                            } else {
                                $message = "Funding opportunity saved successfully! Project already exists for this opportunity (ID: " . $existingProject['id'] . ").";
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error registering project from succeeded opportunity: " . $e->getMessage());
                        $message = "Funding opportunity saved successfully! Note: Project registration encountered an error and may need manual review.";
                    }
                }
                
                if ($opp_id && $_POST['go_no_go_decision'] === 'Go' && !empty($_POST['responsible_person_email'])) {
                    $message .= " Notification sent to responsible person.";
                }
                $redirectAfterSave = true;
                $redirectUrl = "?view=opportunities&message=" . urlencode($message);
                break;
                
            
            case 'save_workplan_register':
                $reg_id = !empty($_POST['workplan_register_id']) ? (int)$_POST['workplan_register_id'] : null;
                $gid = (int)($_POST['grant_id'] ?? 0);
                if ($gid <= 0) { throw new Exception('Grant is required.'); }
                $code = trim($_POST['register_code'] ?? '');
                if ($code === '') { $code = 'WPREG-' . date('YmdHis'); }
                $title = trim($_POST['title'] ?? '');
                if ($title === '') { $title = 'Workplan Register'; }

                if ($reg_id) {
                    $stmt = $pdo->prepare("UPDATE gms_workplan_registers SET register_code=?, title=?, period_start=?, period_end=?, location_name=?, responsible_person=?, responsible_email=?, status=?, notes=?, is_active=1 WHERE id=? AND grant_id=?");
                    $stmt->execute([
                        $code,
                        $title,
                        !empty($_POST['period_start']) ? $_POST['period_start'] : null,
                        !empty($_POST['period_end']) ? $_POST['period_end'] : null,
                        $_POST['location_name'] ?? null,
                        $_POST['responsible_person'] ?? null,
                        $_POST['responsible_email'] ?? null,
                        $_POST['status'] ?? 'Active',
                        $_POST['notes'] ?? null,
                        $reg_id,
                        $gid
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO gms_workplan_registers (grant_id, register_code, title, period_start, period_end, location_name, responsible_person, responsible_email, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $gid,
                        $code,
                        $title,
                        !empty($_POST['period_start']) ? $_POST['period_start'] : null,
                        !empty($_POST['period_end']) ? $_POST['period_end'] : null,
                        $_POST['location_name'] ?? null,
                        $_POST['responsible_person'] ?? null,
                        $_POST['responsible_email'] ?? null,
                        $_POST['status'] ?? 'Active',
                        $_POST['notes'] ?? null
                    ]);
                }
                $message = "Workplan register saved successfully!";
                $redirectAfterSave = true;
                $redirectUrl = "?view=workplans&grant_id=" . $gid . "&message=" . urlencode($message);
                break;

            case 'delete_workplan_register':
                $gid = (int)($_POST['grant_id'] ?? 0);
                $reg_id = (int)($_POST['workplan_register_id'] ?? 0);
                if ($gid > 0 && $reg_id > 0) {
                    $stmt = $pdo->prepare("UPDATE gms_workplan_registers SET is_active=0 WHERE id=? AND grant_id=?");
                    $stmt->execute([$reg_id, $gid]);
                }
                $message = "Workplan register deleted successfully!";
                $redirectAfterSave = true;
                $redirectUrl = "?view=workplans&grant_id=" . $gid . "&message=" . urlencode($message);
                break;

case 'save_workplan':
                $wp_id = !empty($_POST['workplan_id']) ? (int)$_POST['workplan_id'] : null;
                if ($wp_id) {
                    $stmt = $pdo->prepare("
                        UPDATE gms_work_plans SET
                            activity_code = ?, activity_name = ?, description = ?, start_date = ?,
                            end_date = ?, status = ?, progress_percentage = ?, budget_line_id = ?,
                            workplan_register_id = ?, responsible_person = ?, responsible_email = ?,
                            location_name = ?, result_output = ?, indicator_text = ?, target_quantity = ?,
                            target_unit = ?, means_of_verification = ?, dependencies = ?, budget_amount = ?,
                            budget_currency = ?, wbs_code = ?, activity_sequence = ?, notes = ?
                        WHERE id = ?");
                    $stmt->execute([
                        $_POST['activity_code'] ?? null,
                        $_POST['activity_name'] ?? '',
                        $_POST['description'] ?? null,
                        !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                        !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                        $_POST['status'] ?? 'Planned',
                        !empty($_POST['progress_percentage']) ? (float)$_POST['progress_percentage'] : 0,
                        !empty($_POST['budget_line_id']) ? (int)$_POST['budget_line_id'] : null,
                        !empty($_POST['workplan_register_id']) ? (int)$_POST['workplan_register_id'] : null,
                        $_POST['responsible_person'] ?? null,
                        $_POST['responsible_email'] ?? null,
                        $_POST['location_name'] ?? null,
                        $_POST['result_output'] ?? null,
                        $_POST['indicator_text'] ?? null,
                        $_POST['target_quantity'] ?? null,
                        $_POST['target_unit'] ?? null,
                        $_POST['means_of_verification'] ?? null,
                        $_POST['dependencies'] ?? null,
                        $_POST['budget_amount'] ?? null,
                        $_POST['budget_currency'] ?? null,
                        $_POST['wbs_code'] ?? null,
                        $_POST['activity_sequence'] ?? null,
                        $_POST['notes'] ?? null,
                        $wp_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO gms_work_plans (
                            grant_id, activity_code, activity_name, description, start_date,
                            end_date, status, progress_percentage, budget_line_id,
                            workplan_register_id, responsible_person, responsible_email,
                            location_name, result_output, indicator_text, target_quantity, target_unit,
                            means_of_verification, dependencies, budget_amount, budget_currency,
                            wbs_code, activity_sequence, notes
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        (int)($_POST['grant_id'] ?? 0),
                        $_POST['issue_code'] ?? null,
                        $_POST['issue_type'] ?? 'Other',
                        $_POST['description'] ?? '',
                        $_POST['severity'] ?? 'Medium',
                        $_POST['compliance_area'] ?? null,
                        $_POST['requirement_reference'] ?? null,
                        $_POST['evidence_required'] ?? null,
                        !empty($_POST['identified_date']) ? $_POST['identified_date'] : date('Y-m-d'),
                        !empty($_POST['responsible_user_id']) ? (int)$_POST['responsible_user_id'] : null,
                        $_POST['responsible_person_name'] ?? null,
                        $_POST['responsible_person_email'] ?? null,
                        $_POST['responsible_person'] ?? null,
                        $_POST['corrective_action'] ?? null,
                        !empty($_POST['target_resolution_date']) ? $_POST['target_resolution_date'] : null,
                        !empty($_POST['actual_resolution_date']) ? $_POST['actual_resolution_date'] : null,
                        !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null,
                        $_POST['status'] ?? 'Open',
                        $_POST['root_cause'] ?? null,
                        $_POST['notes'] ?? null
                    ]);
                }
                $message = "Work plan saved successfully!";
                $redirectAfterSave = true;
                $grant_id_for_redirect = (int)($_POST['grant_id'] ?? 0);
                $redirectUrl = "?view=risks&grant_id=" . $grant_id_for_redirect . "&message=" . urlencode($message);
                break;
                
            case 'save_compliance_issue':
                $issue_id = !empty($_POST['issue_id']) ? (int)$_POST['issue_id'] : null;
                if ($issue_id) {
                    $stmt = $pdo->prepare("
                        UPDATE gms_compliance_issues SET
                            issue_code = ?, issue_type = ?, description = ?,
                            severity = ?, compliance_area = ?, requirement_reference = ?,
                            evidence_required = ?,
                            identified_date = ?, responsible_user_id = ?,
                            responsible_person_name = ?, responsible_person_email = ?, responsible_person = ?,
                            corrective_action = ?, target_resolution_date = ?,
                            actual_resolution_date = ?, follow_up_date = ?,
                            status = ?, root_cause = ?, notes = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['issue_code'] ?? null,
                        $_POST['issue_type'] ?? 'Other',
                        $_POST['description'] ?? '',
                        $_POST['severity'] ?? 'Medium',
                        $_POST['compliance_area'] ?? null,
                        $_POST['requirement_reference'] ?? null,
                        $_POST['evidence_required'] ?? null,
                        !empty($_POST['identified_date']) ? $_POST['identified_date'] : date('Y-m-d'),
                        !empty($_POST['responsible_user_id']) ? (int)$_POST['responsible_user_id'] : null,
                        $_POST['responsible_person_name'] ?? null,
                        $_POST['responsible_person_email'] ?? null,
                        $_POST['responsible_person'] ?? null,
                        $_POST['corrective_action'] ?? null,
                        !empty($_POST['target_resolution_date']) ? $_POST['target_resolution_date'] : null,
                        !empty($_POST['actual_resolution_date']) ? $_POST['actual_resolution_date'] : null,
                        !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null,
                        $_POST['status'] ?? 'Open',
                        $_POST['root_cause'] ?? null,
                        $_POST['notes'] ?? null,
                        $issue_id
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO gms_compliance_issues (
                            grant_id, issue_code, issue_type, description,
                            severity, identified_date, responsible_user_id, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        (int)($_POST['grant_id'] ?? 0),
                        $_POST['issue_code'] ?? null,
                        $_POST['issue_type'] ?? 'Other',
                        $_POST['description'] ?? '',
                        $_POST['severity'] ?? 'Medium',
                        !empty($_POST['identified_date']) ? $_POST['identified_date'] : date('Y-m-d'),
                        !empty($_POST['responsible_user_id']) ? (int)$_POST['responsible_user_id'] : null,
                        $_POST['status'] ?? 'Open'
                    ]);
                }
                $message = "Compliance issue saved successfully!";
                $redirectAfterSave = true;
                $grant_id_for_redirect = (int)($_POST['grant_id'] ?? 0);
                $redirectUrl = "?view=risks&grant_id=" . $grant_id_for_redirect . "&message=" . urlencode($message);
                break;
                
            case 'delete_budget':
                $budget_id = (int)($_POST['budget_id'] ?? 0);
                if ($budget_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM gms_grant_budgets WHERE id = ?");
                    $stmt->execute([$budget_id]);
                    $message = "Budget line deleted successfully!";
                    $redirectAfterSave = true;
                    $grant_id_for_redirect = (int)($_POST['grant_id'] ?? $_GET['grant_id'] ?? 0);
                    $redirectUrl = "?view=budgets&grant_id=" . $grant_id_for_redirect . "&message=" . urlencode($message);
                }
                break;
                
            case 'delete_report':
                $report_id = (int)($_POST['report_id'] ?? 0);
                if ($report_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM gms_reporting_schedule WHERE id = ?");
                    $stmt->execute([$report_id]);
                    $message = "Report schedule deleted successfully!";
                    $redirectAfterSave = true;
                    $grant_id_for_redirect = (int)($_POST['grant_id'] ?? $_GET['grant_id'] ?? 0);
                    $redirectUrl = "?view=reporting" . ($grant_id_for_redirect > 0 ? "&grant_id=" . $grant_id_for_redirect : "") . "&message=" . urlencode($message);
                }
                break;
                
            case 'delete_risk':
                $risk_id = (int)($_POST['risk_id'] ?? 0);
                if ($risk_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM gms_risk_register WHERE id = ?");
                    $stmt->execute([$risk_id]);
                    $message = "Risk deleted successfully!";
                    $redirectAfterSave = true;
                    $grant_id_for_redirect = (int)($_POST['grant_id'] ?? $_GET['grant_id'] ?? 0);
                    $redirectUrl = "?view=risks&grant_id=" . $grant_id_for_redirect . "&message=" . urlencode($message);
                }
                break;
                
            case 'delete_compliance':
                $issue_id = (int)($_POST['issue_id'] ?? 0);
                if ($issue_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM gms_compliance_issues WHERE id = ?");
                    $stmt->execute([$issue_id]);
                    $message = "Compliance issue deleted successfully!";
                    $redirectAfterSave = true;
                    $grant_id_for_redirect = (int)($_POST['grant_id'] ?? $_GET['grant_id'] ?? 0);
                    $redirectUrl = "?view=risks&grant_id=" . $grant_id_for_redirect . "&message=" . urlencode($message);
                }
                break;
                
            case 'delete_opportunity':
                $opp_id = (int)($_POST['opportunity_id'] ?? 0);
                if ($opp_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM gms_funding_opportunities WHERE id = ?");
                    $stmt->execute([$opp_id]);
                    $message = "Opportunity deleted successfully!";
                    $redirectAfterSave = true;
                    $redirectUrl = "?view=opportunities&message=" . urlencode($message);
                }
                break;
                
            case 'add_opportunity_comment':
                $opp_id = (int)($_POST['opportunity_id'] ?? 0);
                $commentText = trim($_POST['comment_text'] ?? '');
                $parentCommentId = !empty($_POST['parent_comment_id']) ? (int)$_POST['parent_comment_id'] : null;
                
                if ($opp_id > 0 && !empty($commentText)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO gms_opportunity_comments 
                        (opportunity_id, parent_comment_id, commenter_user_id, commenter_name, commenter_email, commenter_position, comment_text)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $opp_id,
                        $parentCommentId,
                        $user['id'] ?? null,
                        $user['full_name'] ?? 'System User',
                        $user['email'] ?? null,
                        $user['position'] ?? null,
                        $commentText
                    ]);
                    
                    if ($parentCommentId) {
                        $message = "Reply added successfully!";
                    } else {
                        $message = "Comment added successfully!";
                    }
                } else {
                    $errorMsg = "Invalid comment data";
                }
                break;
                
            case 'update_opportunity_comment':
                $commentId = (int)($_POST['comment_id'] ?? 0);
                $commentText = trim($_POST['comment_text'] ?? '');
                
                if ($commentId > 0 && !empty($commentText)) {
                    // Verify ownership
                    $checkStmt = $pdo->prepare("SELECT commenter_user_id FROM gms_opportunity_comments WHERE id = ?");
                    $checkStmt->execute([$commentId]);
                    $comment = $checkStmt->fetch();
                    
                    if ($comment && ($comment['commenter_user_id'] == ($user['id'] ?? null) || empty($user['id']))) {
                        $stmt = $pdo->prepare("UPDATE gms_opportunity_comments SET comment_text = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$commentText, $commentId]);
                        $message = "Comment updated successfully!";
                    } else {
                        $errorMsg = "You don't have permission to edit this comment";
                    }
                } else {
                    $errorMsg = "Invalid comment data";
                }
                break;
                
            case 'delete_opportunity_comment':
                $commentId = (int)($_POST['comment_id'] ?? 0);
                
                if ($commentId > 0) {
                    // Verify ownership
                    $checkStmt = $pdo->prepare("SELECT commenter_user_id FROM gms_opportunity_comments WHERE id = ?");
                    $checkStmt->execute([$commentId]);
                    $comment = $checkStmt->fetch();
                    
                    if ($comment && ($comment['commenter_user_id'] == ($user['id'] ?? null) || empty($user['id']))) {
                        $stmt = $pdo->prepare("DELETE FROM gms_opportunity_comments WHERE id = ? OR parent_comment_id = ?");
                        $stmt->execute([$commentId, $commentId]);
                        $message = "Comment deleted successfully!";
                    } else {
                        $errorMsg = "You don't have permission to delete this comment";
                    }
                }
                break;
                
            case 'ai_summarize_website':
                $url = $_GET['url'] ?? '';
                if (!empty($url)) {
                    // Validate URL
                    if (!filter_var($url, FILTER_VALIDATE_URL)) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Invalid URL format']);
                        exit;
                    }
                    
                    try {
                        // Fetch website content
                        $context = stream_context_create([
                            'http' => [
                                'timeout' => 10,
                                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                                'follow_location' => true,
                                'max_redirects' => 5
                            ]
                        ]);
                        
                        $html = @file_get_contents($url, false, $context);
                        
                        if ($html === false) {
                            throw new Exception('Could not fetch website content. Please check the URL and try again.');
                        }
                        
                        // Extract text content from HTML
                        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
                        $dom = new DOMDocument();
                        @$dom->loadHTML($html);
                        
                        // Remove script and style elements
                        $scripts = $dom->getElementsByTagName('script');
                        foreach ($scripts as $script) {
                            $script->parentNode->removeChild($script);
                        }
                        $styles = $dom->getElementsByTagName('style');
                        foreach ($styles as $style) {
                            $style->parentNode->removeChild($style);
                        }
                        
                        // Extract text
                        $xpath = new DOMXPath($dom);
                        $textNodes = $xpath->query('//text()');
                        $text = '';
                        foreach ($textNodes as $node) {
                            $text .= $node->nodeValue . ' ';
                        }
                        
                        // Clean up text
                        $text = preg_replace('/\s+/', ' ', $text);
                        $text = trim($text);
                        $text = substr($text, 0, 5000); // Limit to 5000 characters
                        
                        // Generate AI summary (enhanced)
                        $summary = "🤖 AI-Generated Summary from: " . htmlspecialchars($url) . "\n\n";
                        $summary .= "📋 KEY INFORMATION EXTRACTED:\n\n";
                        
                        // Extract key information patterns
                        $patterns = [
                            'deadline' => '/deadline|due date|submission date|closing date/i',
                            'budget' => '/budget|funding|amount|grant size|allocation/i',
                            'eligibility' => '/eligibility|eligible|qualification|criteria|requirements/i',
                            'objective' => '/objective|goal|purpose|aim|target/i',
                            'sector' => '/sector|theme|area|focus|domain/i'
                        ];
                        
                        $foundSections = [];
                        $lines = explode('.', $text);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (strlen($line) > 50) {
                                foreach ($patterns as $key => $pattern) {
                                    if (preg_match($pattern, $line) && !isset($foundSections[$key])) {
                                        $foundSections[$key] = substr($line, 0, 200);
                                    }
                                }
                            }
                        }
                        
                        if (!empty($foundSections)) {
                            foreach ($foundSections as $key => $content) {
                                $summary .= "• " . ucfirst($key) . ": " . $content . "\n\n";
                            }
                        }
                        
                        // Add general summary
                        $summary .= "📄 GENERAL SUMMARY:\n\n";
                        $summary .= substr($text, 0, 1000) . "...\n\n";
                        $summary .= "💡 NOTE: This is an automated summary. Please review the original website for complete details and verify all information before proceeding.";
                        
                        // If text is too short, provide guidance
                        if (strlen($text) < 200) {
                            $summary = "🤖 AI Summary Attempt\n\n";
                            $summary .= "I accessed the website at " . htmlspecialchars($url) . " but found limited text content.\n\n";
                            $summary .= "This could mean:\n";
                            $summary .= "• The page requires JavaScript to load content\n";
                            $summary .= "• The page is behind authentication\n";
                            $summary .= "• The content is in images or PDFs\n\n";
                            $summary .= "Please review the website manually and enter the key information in the form fields.";
                        }
                        
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'summary' => $summary]);
                        exit;
                    } catch (Exception $e) {
                        header('Content-Type: application/json');
                        echo json_encode([
                            'success' => false, 
                            'message' => 'Error fetching website: ' . $e->getMessage() . '. Please enter the summary manually.'
                        ]);
                        exit;
                    }
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'URL is required']);
                    exit;
                }
                break;
                
            case 'get_current_user_info':
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'full_name' => $user['full_name'] ?? '',
                    'email' => $user['email'] ?? '',
                    'position' => $user['position'] ?? ''
                ]);
                exit;
                break;
                
            case 'delete_workplan':
                $wp_id = (int)($_POST['workplan_id'] ?? 0);
                if ($wp_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM gms_work_plans WHERE id = ?");
                    $stmt->execute([$wp_id]);
                    $message = "Work plan activity deleted successfully!";
                    $redirectAfterSave = true;
                    $grant_id_for_redirect = (int)($_POST['grant_id'] ?? $_GET['grant_id'] ?? 0);
                    $redirectUrl = "?view=workplans&grant_id=" . $grant_id_for_redirect . "&message=" . urlencode($message);
                }
                break;
                
            case 'delete_partner':
                $partner_id = (int)($_POST['partner_id'] ?? 0);
                if ($partner_id > 0) {
                    $stmt = $pdo->prepare("UPDATE gms_partners SET is_active = 0 WHERE id = ?");
                    $stmt->execute([$partner_id]);
                    $message = "Partner deactivated successfully!";
                    $redirectAfterSave = true;
                    $redirectUrl = "?view=partners&message=" . urlencode($message);
                }
                break;
        }
    } catch (Exception $e) {
        $errorMsg = "Error: " . $e->getMessage();
        error_log("GMS Save Error: " . $e->getMessage());
    }
    
    // Redirect after successful save to prevent resubmission
    if ($redirectAfterSave && !empty($redirectUrl)) {
        header("Location: " . $redirectUrl);
        exit;
    }
}

// Get message from URL if redirected
if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
}

// Get current view and grant_id BEFORE loading data
$view = $_GET['view'] ?? 'dashboard';
$grant_id = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;

// Get data for display
$donors = [];
$grants = [];
$projects = [];
$opportunities = [];
$workplans = [];
$partners = [];
$budgets = [];
$reports = [];
$risks = [];
$compliance_issues = [];

try {
    $stmt = $pdo->query("SELECT id, name, short_name, donor_type FROM gms_donors WHERE is_active = 1 ORDER BY name");
    $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("
        SELECT g.*, d.name AS donor_name, p.title AS project_title
        FROM gms_grants g
        LEFT JOIN gms_donors d ON g.donor_id = d.id
        LEFT JOIN projects p ON g.project_id = p.id
        ORDER BY g.created_at DESC, g.id DESC
        LIMIT 100
    ");
    $grants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure grants is always an array
    if (!is_array($grants)) {
        $grants = [];
    }
    
    if (function_exists('get_projects')) {
        $projects = get_projects();
    } else {
        $stmt = $pdo->query("SELECT id, title, name FROM projects ORDER BY title");
        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Load data for current view
    if ($view === 'opportunities') {
        $stmt = $pdo->query("
            SELECT o.*, d.name AS donor_name 
            FROM gms_funding_opportunities o 
            LEFT JOIN gms_donors d ON o.donor_id = d.id 
            ORDER BY o.submission_deadline DESC 
            LIMIT 50
        ");
        $opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($view === 'workplans' && $grant_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM gms_work_plans WHERE grant_id = ? ORDER BY start_date");
        $stmt->execute([$grant_id]);
        $workplans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($view === 'partners') {
        $stmt = $pdo->query("SELECT * FROM gms_partners WHERE is_active = 1 ORDER BY name");
        $partners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($view === 'budgets' && $grant_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM gms_grant_budgets WHERE grant_id = ? ORDER BY budget_category, budget_line_code");
        $stmt->execute([$grant_id]);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($view === 'reporting') {
        if ($grant_id > 0) {
            $stmt = $pdo->prepare("
                SELECT rs.*, g.grant_code, g.title AS grant_title, d.name AS donor_name
                FROM gms_reporting_schedule rs
                JOIN gms_grants g ON rs.grant_id = g.id
                LEFT JOIN gms_donors d ON g.donor_id = d.id
                WHERE rs.grant_id = ?
                ORDER BY rs.due_date
            ");
            $stmt->execute([$grant_id]);
        } else {
            $stmt = $pdo->query("
                SELECT rs.*, g.grant_code, g.title AS grant_title, d.name AS donor_name
                FROM gms_reporting_schedule rs
                JOIN gms_grants g ON rs.grant_id = g.id
                LEFT JOIN gms_donors d ON g.donor_id = d.id
                WHERE rs.status IN ('Pending', 'Draft', 'Overdue')
                ORDER BY rs.due_date
                LIMIT 50
            ");
        }
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if ($view === 'risks' && $grant_id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM gms_risk_register WHERE grant_id = ? ORDER BY risk_level DESC, created_at DESC");
        $stmt->execute([$grant_id]);
        $risks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT * FROM gms_compliance_issues WHERE grant_id = ? ORDER BY severity DESC, identified_date DESC");
        $stmt->execute([$grant_id]);
        $compliance_issues = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("GMS Data Load Error: " . $e->getMessage());
}

// Initialize dashboard variables to prevent undefined variable errors
$total_grants = 0;
$active_grants = 0;
$pipeline_grants = 0;
$closed_grants = 0;
$suspended_grants = 0;
$total_donors = 0;
$total_opportunities = 0;
$total_partners = 0;
$total_reports = 0;
$pending_reports = 0;
$overdue_reports = 0;
$submitted_reports = 0;
$on_time_reports = 0;
$late_reports = 0;
$early_reports = 0;
$total_budgeted = 0;
$total_spent = 0;

require_once __DIR__ . '/../header.php';
?>

<style>
/* Hide Actions column in print and export */
@media print {
    .no-print, .no-export, th.no-print, td.no-print, th.no-export, td.no-export {
        display: none !important;
    }
    .gms-table {
        width: 100%;
        border-collapse: collapse;
    }
    .gms-table th, .gms-table td {
        border: 1px solid #ddd;
        padding: 8px;
    }
    .gms-table th {
        background-color: #f0f0f0;
        font-weight: bold;
    }
    .btn, button, .no-print {
        display: none !important;
    }
}

.gms-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.gms-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 8px 24px rgba(102, 126, 234, 0.3);
}

.gms-header h1 {
    margin: 0 0 10px 0;
    font-size: 32px;
    font-weight: 700;
}

.gms-header p {
    margin: 0;
    opacity: 0.95;
    font-size: 16px;
}

.gms-nav {
    display: flex;
    gap: 10px;
    margin-bottom: 30px;
    flex-wrap: wrap;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 15px;
}

.gms-nav a {
    padding: 12px 24px;
    background: #f5f5f5;
    border-radius: 8px;
    text-decoration: none;
    color: #333;
    font-weight: 500;
    transition: all 0.3s;
    border: 2px solid transparent;
}

.gms-nav a:hover {
    background: #667eea;
    color: white;
    transform: translateY(-2px);
}

.gms-nav a.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
}

.gms-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border: 1px solid #e0e0e0;
}

.gms-card h2 {
    margin: 0 0 20px 0;
    color: #333;
    font-size: 24px;
    border-bottom: 2px solid #667eea;
    padding-bottom: 10px;
}

.gms-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.gms-stat-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border-left: 4px solid #667eea;
}

.gms-stat-card h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #666;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.gms-stat-card .stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #667eea;
    margin: 0;
}

.gms-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.gms-table th,
.gms-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e0e0e0;
}

.gms-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
    text-transform: uppercase;
    font-size: 12px;
    letter-spacing: 0.5px;
}

.gms-table tr:hover {
    background: #f8f9fa;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

.status-pipeline { background: #fff3cd; color: #856404; }
.status-awarded { background: #d1ecf1; color: #0c5460; }
.status-active { background: #d4edda; color: #155724; }
.status-suspended { background: #f8d7da; color: #721c24; }
.status-closed { background: #e2e3e5; color: #383d41; }
.status-pending { background: #fff3cd; color: #856404; }
.status-draft { background: #d1ecf1; color: #0c5460; }
.status-submitted { background: #d4edda; color: #155724; }
.status-overdue { background: #f8d7da; color: #721c24; }
.status-open { background: #d4edda; color: #155724; }
.status-mitigated { background: #d1ecf1; color: #0c5460; }
.status-low { background: #d4edda; color: #155724; }
.status-medium { background: #fff3cd; color: #856404; }
.status-high { background: #f8d7da; color: #721c24; }
.status-critical { background: #721c24; color: #fff; }

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}
</style>

<div class="gms-container">
    <div class="gms-header">
        <h1>🏦 Grant Management System (GMS)</h1>
        <p>Comprehensive grant lifecycle management aligned with UN agencies, INGOs, and major donors</p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <nav class="gms-nav">
        <a href="?view=dashboard" class="<?php echo $view === 'dashboard' ? 'active' : ''; ?>">📊 Dashboard</a>
        <a href="?view=grants" class="<?php echo $view === 'grants' ? 'active' : ''; ?>">📋 Grants</a>
        <a href="?view=donors" class="<?php echo $view === 'donors' ? 'active' : ''; ?>">🏢 Donors</a>
        <a href="?view=opportunities" class="<?php echo $view === 'opportunities' ? 'active' : ''; ?>">💡 Opportunities</a>
        <a href="?view=workplans" class="<?php echo $view === 'workplans' ? 'active' : ''; ?>">📅 Work Plans</a>
        <a href="?view=partners" class="<?php echo $view === 'partners' ? 'active' : ''; ?>">🤝 Partners</a>
        <a href="?view=budgets" class="<?php echo $view === 'budgets' ? 'active' : ''; ?>">💰 Budgets</a>
        <a href="?view=reporting" class="<?php echo $view === 'reporting' ? 'active' : ''; ?>">📊 Reporting</a>
        <a href="?view=risks" class="<?php echo $view === 'risks' ? 'active' : ''; ?>">⚠️ Risks & Compliance</a>
    </nav>

    <?php if ($view === 'dashboard'): ?>
        <?php
        // Enhanced Dashboard Analytics
        $total_grants = count($grants);
        $active_grants = count(array_filter($grants, fn($g) => $g['status'] === 'Active'));
        $pipeline_grants = count(array_filter($grants, fn($g) => $g['status'] === 'Pipeline'));
        $closed_grants = count(array_filter($grants, fn($g) => $g['status'] === 'Closed'));
        $suspended_grants = count(array_filter($grants, fn($g) => $g['status'] === 'Suspended'));
        $total_donors = count($donors);
        $total_opportunities = count($opportunities);
        $total_partners = count($partners);
        
        // Reporting Analytics
        $total_reports = count($reports);
        $pending_reports = count(array_filter($reports, fn($r) => $r['status'] === 'Pending'));
        $overdue_reports = count(array_filter($reports, fn($r) => $r['status'] === 'Overdue'));
        $submitted_reports = count(array_filter($reports, fn($r) => $r['status'] === 'Submitted'));
        
        // Calculate report timeliness
        $today = date('Y-m-d');
        $on_time_reports = 0;
        $late_reports = 0;
        $early_reports = 0;
        foreach ($reports as $report) {
            if ($report['submission_date'] && $report['due_date']) {
                $days_diff = (strtotime($report['submission_date']) - strtotime($report['due_date'])) / (60 * 60 * 24);
                if ($days_diff <= 0) {
                    $on_time_reports++;
                } elseif ($days_diff > 0) {
                    $late_reports++;
                }
            } elseif ($report['due_date'] < $today && $report['status'] !== 'Submitted') {
                $late_reports++;
            }
        }
        
        // Budget Analytics
        $total_budget = array_sum(array_column($grants, 'total_budget'));
        $total_budgeted = 0;
        $total_spent = 0;
        if ($grant_id > 0) {
            $total_budgeted = array_sum(array_column($budgets, 'budget_amount'));
            $total_spent = array_sum(array_column($budgets, 'spent_amount'));
        } else {
            foreach ($grants as $g) {
                $stmt = $pdo->prepare("SELECT SUM(budget_amount) as total, SUM(spent_amount) as spent FROM gms_grant_budgets WHERE grant_id = ?");
                $stmt->execute([$g['id']]);
                $budget_data = $stmt->fetch(PDO::FETCH_ASSOC);
                $total_budgeted += $budget_data['total'] ?? 0;
                $total_spent += $budget_data['spent'] ?? 0;
            }
        }
        
        // Risk Analytics
        $total_risks = 0;
        $high_risks = 0;
        $total_compliance_issues = 0;
        foreach ($grants as $g) {
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM gms_risk_register WHERE grant_id = ?");
            $stmt->execute([$g['id']]);
            $risk_count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            $total_risks += $risk_count;
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM gms_risk_register WHERE grant_id = ? AND risk_level IN ('High', 'Critical')");
            $stmt->execute([$g['id']]);
            $high_risk_count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            $high_risks += $high_risk_count;
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM gms_compliance_issues WHERE grant_id = ?");
            $stmt->execute([$g['id']]);
            $compliance_count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
            $total_compliance_issues += $compliance_count;
        }
        
        // Opportunity Analytics - Comprehensive
        try {
            $oppStmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_opportunities,
                    SUM(CASE WHEN go_no_go_decision = 'Go' THEN 1 ELSE 0 END) as opportunities_applied,
                    SUM(CASE WHEN proposal_status = 'Succeeded' THEN 1 ELSE 0 END) as opportunities_succeeded,
                    SUM(CASE WHEN proposal_status IN ('Declined', 'Failed', 'Failed to Submit') THEN 1 ELSE 0 END) as opportunities_failed,
                    SUM(CASE WHEN status = 'Open' AND (submission_deadline >= CURDATE() OR submission_deadline IS NULL) THEN 1 ELSE 0 END) as open_opportunities,
                    SUM(CASE WHEN status = 'Closed' OR deadline_passed = 1 THEN 1 ELSE 0 END) as closed_opportunities,
                    SUM(CASE WHEN status = 'Under Review' THEN 1 ELSE 0 END) as under_review_opportunities,
                    SUM(CASE WHEN proposal_status = 'Submitted' THEN 1 ELSE 0 END) as submitted_opportunities,
                    SUM(CASE WHEN proposal_status = 'Declined' THEN 1 ELSE 0 END) as declined_opportunities,
                    SUM(CASE WHEN proposal_status = 'In Progress' THEN 1 ELSE 0 END) as in_progress_opportunities
                FROM gms_funding_opportunities
            ");
            $oppStats = $oppStmt->fetch(PDO::FETCH_ASSOC);
            
            $opportunities_applied = (int)($oppStats['opportunities_applied'] ?? 0);
            $opportunities_succeeded = (int)($oppStats['opportunities_succeeded'] ?? 0);
            $opportunities_failed = (int)($oppStats['opportunities_failed'] ?? 0);
            $open_opportunities = (int)($oppStats['open_opportunities'] ?? 0);
            $closed_opportunities = (int)($oppStats['closed_opportunities'] ?? 0);
            $under_review_opportunities = (int)($oppStats['under_review_opportunities'] ?? 0);
            $submitted_opportunities = (int)($oppStats['submitted_opportunities'] ?? 0);
            $declined_opportunities = (int)($oppStats['declined_opportunities'] ?? 0);
            $in_progress_opportunities = (int)($oppStats['in_progress_opportunities'] ?? 0);
            
            // Calculate success rate
            $total_processed = $opportunities_succeeded + $opportunities_failed;
            $success_rate = $total_processed > 0 ? round(($opportunities_succeeded / $total_processed) * 100, 1) : 0;
            
            // Opportunities by donor
            $donorOppStmt = $pdo->query("
                SELECT d.name as donor_name, COUNT(o.id) as opp_count
                FROM gms_funding_opportunities o
                LEFT JOIN gms_donors d ON o.donor_id = d.id
                GROUP BY d.id, d.name
                ORDER BY opp_count DESC
                LIMIT 10
            ");
            $opportunities_by_donor = $donorOppStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Opportunities by status breakdown
            $statusOppStmt = $pdo->query("
                SELECT 
                    status,
                    COUNT(*) as count
                FROM gms_funding_opportunities
                GROUP BY status
                ORDER BY count DESC
            ");
            $opportunities_by_status = $statusOppStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Proposal status breakdown
            $proposalStatusStmt = $pdo->query("
                SELECT 
                    proposal_status,
                    COUNT(*) as count
                FROM gms_funding_opportunities
                WHERE proposal_status IS NOT NULL AND proposal_status != 'Not Started'
                GROUP BY proposal_status
                ORDER BY count DESC
            ");
            $proposal_status_breakdown = $proposalStatusStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Budget analysis
            $budgetStmt = $pdo->query("
                SELECT 
                    SUM(estimated_budget_max) as total_budget_max,
                    SUM(estimated_budget_min) as total_budget_min,
                    AVG(estimated_budget_max) as avg_budget_max,
                    COUNT(CASE WHEN estimated_budget_max IS NOT NULL THEN 1 END) as opportunities_with_budget
                FROM gms_funding_opportunities
            ");
            $budget_stats = $budgetStmt->fetch(PDO::FETCH_ASSOC);
            $total_opportunity_budget = (float)($budget_stats['total_budget_max'] ?? 0);
            $avg_opportunity_budget = (float)($budget_stats['avg_budget_max'] ?? 0);
        } catch (Exception $e) {
            $opportunities_applied = 0;
            $opportunities_succeeded = 0;
            $opportunities_failed = 0;
            $open_opportunities = 0;
            $closed_opportunities = 0;
            $under_review_opportunities = 0;
            $submitted_opportunities = 0;
            $declined_opportunities = 0;
            $in_progress_opportunities = 0;
            $success_rate = 0;
            $opportunities_by_donor = [];
            $opportunities_by_status = [];
            $proposal_status_breakdown = [];
            $total_opportunity_budget = 0;
            $avg_opportunity_budget = 0;
        }
        ?>
        
        <div class="gms-stats">
            <div class="gms-stat-card">
                <h3>Total Grants</h3>
                <p class="stat-value"><?php echo number_format($total_grants); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Active Grants</h3>
                <p class="stat-value"><?php echo number_format($active_grants); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Total Donors</h3>
                <p class="stat-value"><?php echo number_format($total_donors); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Pipeline Grants</h3>
                <p class="stat-value"><?php echo number_format($pipeline_grants); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Total Reports</h3>
                <p class="stat-value"><?php echo number_format($total_reports); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Overdue Reports</h3>
                <p class="stat-value" style="color: #dc3545;"><?php echo number_format($overdue_reports); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>On-Time Reports</h3>
                <p class="stat-value" style="color: #28a745;"><?php echo number_format($on_time_reports); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Total Budget</h3>
                <p class="stat-value">$<?php echo number_format($total_budget, 0); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Total Spent</h3>
                <p class="stat-value">$<?php echo number_format($total_spent, 0); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>High Risks</h3>
                <p class="stat-value" style="color: #dc3545;"><?php echo number_format($high_risks); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>New Opportunities Applied</h3>
                <p class="stat-value" style="color: #17a2b8;"><?php echo number_format($opportunities_applied); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Opportunities Succeeded</h3>
                <p class="stat-value" style="color: #28a745;"><?php echo number_format($opportunities_succeeded); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Opportunities Failed</h3>
                <p class="stat-value" style="color: #dc3545;"><?php echo number_format($opportunities_failed); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Success Rate</h3>
                <p class="stat-value" style="color: <?php echo $success_rate >= 50 ? '#28a745' : ($success_rate >= 30 ? '#ffc107' : '#dc3545'); ?>;">
                    <?php echo $success_rate; ?>%
                </p>
            </div>
            <div class="gms-stat-card">
                <h3>Open Opportunities</h3>
                <p class="stat-value" style="color: #17a2b8;"><?php echo number_format($open_opportunities); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Under Review</h3>
                <p class="stat-value" style="color: #ff9800;"><?php echo number_format($under_review_opportunities); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Submitted</h3>
                <p class="stat-value" style="color: #17a2b8;"><?php echo number_format($submitted_opportunities); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>In Progress</h3>
                <p class="stat-value" style="color: #667eea;"><?php echo number_format($in_progress_opportunities); ?></p>
            </div>
            <div class="gms-stat-card">
                <h3>Total Opportunity Budget</h3>
                <p class="stat-value" style="color: #28a745;">$<?php echo number_format($total_opportunity_budget, 0); ?></p>
            </div>
        </div>
        
        <!-- Detailed Opportunity Analytics -->
        <div class="gms-card" style="margin-top: 30px;">
            <h2>📊 Detailed Opportunity Analytics</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                <!-- Opportunities by Status -->
                <div>
                    <h3>Opportunities by Status</h3>
                    <table class="gms-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($opportunities_by_status)): ?>
                                <?php foreach ($opportunities_by_status as $stat): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($stat['status'] ?? 'N/A'); ?></td>
                                        <td><strong><?php echo number_format($stat['count']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2" style="text-align: center; color: #999;">No data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Opportunities by Donor -->
                <div>
                    <h3>Top Donors (by Opportunities)</h3>
                    <table class="gms-table">
                        <thead>
                            <tr>
                                <th>Donor</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($opportunities_by_donor)): ?>
                                <?php foreach ($opportunities_by_donor as $donor): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($donor['donor_name'] ?? 'Unknown'); ?></td>
                                        <td><strong><?php echo number_format($donor['opp_count']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2" style="text-align: center; color: #999;">No data available</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Proposal Status Breakdown -->
                <div>
                    <h3>Proposal Status Breakdown</h3>
                    <table class="gms-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($proposal_status_breakdown)): ?>
                                <?php foreach ($proposal_status_breakdown as $pstat): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pstat['proposal_status'] ?? 'N/A'); ?></td>
                                        <td><strong><?php echo number_format($pstat['count']); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="2" style="text-align: center; color: #999;">No proposals submitted yet</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Analytics Charts Section -->
        <div class="gms-card" style="margin-top: 30px;">
            <h2>📊 Analytics & Insights</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-top: 20px;">
                <!-- Grant Status Chart -->
                <div>
                    <h3>Grant Status Distribution</h3>
                    <canvas id="grantStatusChart" width="400" height="200"></canvas>
                </div>
                <!-- Report Status Chart -->
                <div>
                    <h3>Report Status Distribution</h3>
                    <canvas id="reportStatusChart" width="400" height="200"></canvas>
                </div>
                <!-- Budget Utilization Chart -->
                <div>
                    <h3>Budget Utilization</h3>
                    <canvas id="budgetChart" width="400" height="200"></canvas>
                </div>
                <!-- Report Timeliness Chart -->
                <div>
                    <h3>Report Timeliness</h3>
                    <canvas id="timelinessChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Detailed Analytics Tables -->
        <div class="gms-card" style="margin-top: 30px;">
            <h2>📋 Reporting Analytics</h2>
            <table class="gms-table">
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Count</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total Reports</td>
                        <td><?php echo number_format($total_reports); ?></td>
                        <td>100%</td>
                    </tr>
                    <tr>
                        <td>Pending Reports</td>
                        <td><?php echo number_format($pending_reports); ?></td>
                        <td><?php echo $total_reports > 0 ? number_format(($pending_reports / $total_reports) * 100, 1) : 0; ?>%</td>
                    </tr>
                    <tr>
                        <td>Overdue Reports</td>
                        <td style="color: #dc3545; font-weight: bold;"><?php echo number_format($overdue_reports); ?></td>
                        <td><?php echo $total_reports > 0 ? number_format(($overdue_reports / $total_reports) * 100, 1) : 0; ?>%</td>
                    </tr>
                    <tr>
                        <td>Submitted Reports</td>
                        <td style="color: #28a745; font-weight: bold;"><?php echo number_format($submitted_reports); ?></td>
                        <td><?php echo $total_reports > 0 ? number_format(($submitted_reports / $total_reports) * 100, 1) : 0; ?>%</td>
                    </tr>
                    <tr>
                        <td>On-Time Reports</td>
                        <td style="color: #28a745;"><?php echo number_format($on_time_reports); ?></td>
                        <td><?php echo $submitted_reports > 0 ? number_format(($on_time_reports / $submitted_reports) * 100, 1) : 0; ?>%</td>
                    </tr>
                    <tr>
                        <td>Late Reports</td>
                        <td style="color: #dc3545;"><?php echo number_format($late_reports); ?></td>
                        <td><?php echo $submitted_reports > 0 ? number_format(($late_reports / $submitted_reports) * 100, 1) : 0; ?>%</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <!-- Risk & Compliance Summary -->
        <div class="gms-card" style="margin-top: 30px;">
            <h2>⚠️ Risk & Compliance Summary</h2>
            <table class="gms-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Count</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total Risks</td>
                        <td><?php echo number_format($total_risks); ?></td>
                    </tr>
                    <tr>
                        <td>High/Critical Risks</td>
                        <td style="color: #dc3545; font-weight: bold;"><?php echo number_format($high_risks); ?></td>
                    </tr>
                    <tr>
                        <td>Compliance Issues</td>
                        <td><?php echo number_format($total_compliance_issues); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="gms-card" style="margin-top: 30px;">
            <h2>Recent Grants</h2>
            <div style="margin-bottom: 15px;">
                <button onclick="exportDashboard('excel')" class="btn btn-primary btn-sm">📥 Export Excel</button>
                <button onclick="exportDashboard('pdf')" class="btn btn-primary btn-sm">📄 Export PDF</button>
                <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Print</button>
            </div>
            <table class="gms-table" id="grantsTable">
                <thead>
                    <tr>
                        <th>Grant Code</th>
                        <th>Title</th>
                        <th>Donor</th>
                        <th>Status</th>
                        <th>Budget</th>
                        <th>Reports</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($grants, 0, 10) as $grant): ?>
                        <?php
                        // Get report count for this grant
                        $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM gms_reporting_schedule WHERE grant_id = ?");
                        $stmt->execute([$grant['id']]);
                        $report_count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0;
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($grant['grant_code']); ?></td>
                            <td><?php echo htmlspecialchars($grant['title']); ?></td>
                            <td><?php echo htmlspecialchars($grant['donor_name'] ?? 'N/A'); ?></td>
                            <td><span class="status-badge status-<?php echo strtolower($grant['status']); ?>"><?php echo htmlspecialchars($grant['status']); ?></span></td>
                            <td><?php echo number_format($grant['total_budget'], 2); ?> <?php echo htmlspecialchars($grant['currency']); ?></td>
                            <td><?php echo $report_count; ?> reports</td>
                            <td>
                                <a href="?view=grants&grant_id=<?php echo $grant['id']; ?>" class="btn btn-primary btn-sm">👁️ View</a>
                                <a href="?view=grants&grant_id=<?php echo $grant['id']; ?>&action=edit" class="btn btn-primary btn-sm">✏️ Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($view === 'grants'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $edit_grant = null;
        if ($grant_id > 0 && $action !== 'new') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_grants WHERE id = ?");
                $stmt->execute([$grant_id]);
                $edit_grant = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading grant: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($action === 'new' || ($edit_grant && $action === 'edit')): ?>
            <div style="margin-bottom: 15px;">
                <button onclick="window.history.back()" class="btn" style="background: #6c757d; color: white;">← Back to List</button>
            </div>
            <div class="gms-card">
                <h2><?php echo $edit_grant ? 'Edit Grant' : 'Add New Grant'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_grant">
                    <?php if ($edit_grant): ?>
                        <input type="hidden" name="grant_id" value="<?php echo $edit_grant['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Grant Code *</label>
                            <input type="text" name="grant_code" value="<?php echo htmlspecialchars($edit_grant['grant_code'] ?? 'GRANT-' . date('YmdHis')); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Grant Number</label>
                            <input type="text" name="grant_number" value="<?php echo htmlspecialchars($edit_grant['grant_number'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" value="<?php echo htmlspecialchars($edit_grant['title'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Donor *</label>
                            <select name="donor_id" id="grant_donor_select" required onchange="handleGrantDonorSelection(); updateCurrencyFromDonor();">
                                <?php 
                                $currentDonor = ($edit_grant && !empty($edit_grant['donor_other']) && empty($edit_grant['donor_id'])) ? 'OTHER' : ($edit_grant['donor_id'] ?? '');
                                echo donor_dropdown_options($currentDonor, true, false); 
                                ?>
                            </select>
                            <div id="grant_donor_other_section" style="display: <?php echo ($edit_grant && empty($edit_grant['donor_id']) && !empty($edit_grant['donor_other'])) ? 'block' : 'none'; ?>; margin-top: 10px;">
                                <input type="text" name="donor_other" id="grant_donor_other_input" placeholder="Enter donor name" value="<?php echo htmlspecialchars($edit_grant['donor_other'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Project</label>
                            <select name="project_id">
                                <option value="">-- Select Project --</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" <?php echo ($edit_grant && $edit_grant['project_id'] == $project['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['title'] ?? $project['name'] ?? 'Project ' . $project['id']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Agreement Type</label>
                            <select name="agreement_type">
                                <option value="Grant" <?php echo ($edit_grant && $edit_grant['agreement_type'] === 'Grant') ? 'selected' : ''; ?>>Grant</option>
                                <option value="Cooperative Agreement" <?php echo ($edit_grant && $edit_grant['agreement_type'] === 'Cooperative Agreement') ? 'selected' : ''; ?>>Cooperative Agreement</option>
                                <option value="Contract" <?php echo ($edit_grant && $edit_grant['agreement_type'] === 'Contract') ? 'selected' : ''; ?>>Contract</option>
                                <option value="Sub-grant" <?php echo ($edit_grant && $edit_grant['agreement_type'] === 'Sub-grant') ? 'selected' : ''; ?>>Sub-grant</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="Pipeline" <?php echo ($edit_grant && $edit_grant['status'] === 'Pipeline') ? 'selected' : ''; ?>>Pipeline</option>
                                <option value="Awarded" <?php echo ($edit_grant && $edit_grant['status'] === 'Awarded') ? 'selected' : ''; ?>>Awarded</option>
                                <option value="Active" <?php echo ($edit_grant && $edit_grant['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Suspended" <?php echo ($edit_grant && $edit_grant['status'] === 'Suspended') ? 'selected' : ''; ?>>Suspended</option>
                                <option value="Closed" <?php echo ($edit_grant && $edit_grant['status'] === 'Closed') ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $edit_grant && $edit_grant['start_date'] ? date('Y-m-d', strtotime($edit_grant['start_date'])) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?php echo $edit_grant && $edit_grant['end_date'] ? date('Y-m-d', strtotime($edit_grant['end_date'])) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Total Budget *</label>
                            <input type="number" name="total_budget" step="0.01" value="<?php echo $edit_grant ? number_format($edit_grant['total_budget'], 2, '.', '') : '0'; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency">
                                <?php echo currency_dropdown_options($edit_grant ? $edit_grant['currency'] : 'USD', true, true); ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Reporting Frequency</label>
                            <select name="reporting_frequency">
                                <option value="Monthly" <?php echo ($edit_grant && $edit_grant['reporting_frequency'] === 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                                <option value="Quarterly" <?php echo ($edit_grant && ($edit_grant['reporting_frequency'] === 'Quarterly' || !$edit_grant)) ? 'selected' : ''; ?>>Quarterly</option>
                                <option value="Semi-Annual" <?php echo ($edit_grant && $edit_grant['reporting_frequency'] === 'Semi-Annual') ? 'selected' : ''; ?>>Semi-Annual</option>
                                <option value="Annual" <?php echo ($edit_grant && $edit_grant['reporting_frequency'] === 'Annual') ? 'selected' : ''; ?>>Annual</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Risk Level</label>
                            <select name="risk_level">
                                <option value="Low" <?php echo ($edit_grant && $edit_grant['risk_level'] === 'Low') ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo ($edit_grant && ($edit_grant['risk_level'] === 'Medium' || !$edit_grant)) ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo ($edit_grant && $edit_grant['risk_level'] === 'High') ? 'selected' : ''; ?>>High</option>
                                <option value="Critical" <?php echo ($edit_grant && $edit_grant['risk_level'] === 'Critical') ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Special Conditions</label>
                        <textarea name="special_conditions" rows="4"><?php echo htmlspecialchars($edit_grant['special_conditions'] ?? ''); ?></textarea>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Grant</button>
                        <a href="?view=grants" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="gms-card">
                <h2>Grant Management</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                    <a href="?view=grants&action=new" class="btn btn-primary">➕ Add New Grant</a>
                    <button onclick="downloadTemplate('grants')" class="btn" style="background: #17a2b8; color: white;">📥 Download Template</button>
                    <button onclick="importTemplate('grants')" class="btn" style="background: #28a745; color: white;">📤 Import Data</button>
                    <button onclick="exportTable('grantsTable', 'grants_export', 'excel')" class="btn" style="background: #6c757d; color: white;">📊 Export Excel</button>
                    <button onclick="exportTable('grantsTable', 'grants_export', 'pdf')" class="btn" style="background: #dc3545; color: white;">📄 Export PDF</button>
                    <button onclick="printSection('grantsCard')" class="btn" style="background: #ffc107; color: #000;">🖨️ Print</button>
                    <button onclick="openAIAssistant('Grants')" class="btn" style="background: #9c27b0; color: white;">🤖 AI Assistant</button>
                </div>
                
                <div id="grantsCard">
                <table class="gms-table" id="grantsTable">
                <thead>
                    <tr>
                        <th>Grant Code</th>
                        <th>Title</th>
                        <th>Donor</th>
                        <th>Project</th>
                        <th>Status</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Budget</th>
                        <th class="no-export no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grants)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #6c757d;">
                                <p style="font-size: 16px; margin: 0;">No grants found.</p>
                                <p style="font-size: 14px; margin: 10px 0 0 0;">Click "Add New Grant" or "Import Data" to get started.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($grants as $grant): ?>
                        <tr>
                                <td><?php echo htmlspecialchars($grant['grant_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($grant['title'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($grant['donor_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($grant['project_title'] ?? 'N/A'); ?></td>
                                <td><span class="status-badge status-<?php echo strtolower($grant['status'] ?? 'pipeline'); ?>"><?php echo htmlspecialchars($grant['status'] ?? 'Pipeline'); ?></span></td>
                                <td><?php echo !empty($grant['start_date']) ? date('Y-m-d', strtotime($grant['start_date'])) : 'N/A'; ?></td>
                                <td><?php echo !empty($grant['end_date']) ? date('Y-m-d', strtotime($grant['end_date'])) : 'N/A'; ?></td>
                                <td><?php echo number_format((float)($grant['total_budget'] ?? 0), 2); ?> <?php echo htmlspecialchars($grant['currency'] ?? 'USD'); ?></td>
                                <td class="no-export no-print">
                                <a href="?view=grants&grant_id=<?php echo $grant['id']; ?>&action=edit" class="btn btn-primary btn-sm">✏️ Edit</a>
                                <a href="?view=grants&grant_id=<?php echo $grant['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this grant? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_grant">
                                    <input type="hidden" name="grant_id" value="<?php echo $grant['id']; ?>">
                                    <button type="submit" class="btn btn-sm" style="background: #dc3545; color: white; padding: 6px 12px;">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'donors'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $donor_id = isset($_GET['donor_id']) ? (int)$_GET['donor_id'] : 0;
        $edit_donor = null;
        if ($donor_id > 0 && $action !== 'new') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_donors WHERE id = ?");
                $stmt->execute([$donor_id]);
                $edit_donor = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading donor: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($action === 'new' || ($edit_donor && $action === 'edit')): ?>
            <div style="margin-bottom: 15px;">
                <button onclick="window.location.href='?view=donors'" class="btn" style="background: #6c757d; color: white;">← Back to Donor Management</button>
            </div>
            
            <div class="gms-card">
                <h2><?php echo $edit_donor ? 'Edit Donor' : 'Add New Donor Registration'; ?></h2>
                
                <!-- Registration Form -->
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_donor">
                    <?php if ($edit_donor): ?>
                        <input type="hidden" name="donor_id" value="<?php echo $edit_donor['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Donor *</label>
                            <select name="donor_id" id="donor_registration_select" onchange="handleDonorRegistrationSelection();" <?php echo $edit_donor ? '' : 'required'; ?>>
                                <?php 
                                $currentDonorId = $edit_donor ? ($edit_donor['id'] ?? '') : '';
                                echo donor_dropdown_options($currentDonorId, true, false);
                                ?>
                            </select>
                            <div id="donor_registration_other_section" style="display: <?php echo ($edit_donor && empty($edit_donor['id'])) ? 'block' : 'none'; ?>; margin-top: 10px;">
                                <input type="text" name="donor_other" id="donor_registration_other_input" placeholder="Enter donor name" value="<?php echo htmlspecialchars($edit_donor['name'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Short Name</label>
                            <input type="text" name="short_name" id="short_name_input" value="<?php echo htmlspecialchars($edit_donor['short_name'] ?? ''); ?>" placeholder="Auto-filled when donor is selected">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Donor Type *</label>
                            <select name="donor_type" id="donor_type_select" required>
                                <option value="UN Agency" <?php echo ($edit_donor && $edit_donor['donor_type'] === 'UN Agency') ? 'selected' : ''; ?>>UN Agency</option>
                                <option value="Bilateral Donor" <?php echo ($edit_donor && $edit_donor['donor_type'] === 'Bilateral Donor') ? 'selected' : ''; ?>>Bilateral Donor</option>
                                <option value="Pooled Fund" <?php echo ($edit_donor && $edit_donor['donor_type'] === 'Pooled Fund') ? 'selected' : ''; ?>>Pooled Fund</option>
                                <option value="Foundation" <?php echo ($edit_donor && $edit_donor['donor_type'] === 'Foundation') ? 'selected' : ''; ?>>Foundation</option>
                                <option value="Corporate" <?php echo ($edit_donor && $edit_donor['donor_type'] === 'Corporate') ? 'selected' : ''; ?>>Corporate</option>
                                <option value="Government" <?php echo ($edit_donor && $edit_donor['donor_type'] === 'Government') ? 'selected' : ''; ?>>Government</option>
                                <option value="Other" <?php echo ($edit_donor && ($edit_donor['donor_type'] === 'Other' || !$edit_donor)) ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Country</label>
                            <input type="text" name="country" value="<?php echo htmlspecialchars($edit_donor['country'] ?? ''); ?>">
                        </div>
                    </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Contact Name</label>
                                <input type="text" name="contact_name" value="<?php echo htmlspecialchars($edit_donor['contact_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Contact Email</label>
                                <input type="email" name="contact_email" value="<?php echo htmlspecialchars($edit_donor['contact_email'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Contact Phone</label>
                                <input type="text" name="contact_phone" value="<?php echo htmlspecialchars($edit_donor['contact_phone'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Website</label>
                                <input type="url" name="website" value="<?php echo htmlspecialchars($edit_donor['website'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Address</label>
                            <textarea name="address" rows="3"><?php echo htmlspecialchars($edit_donor['address'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" rows="3"><?php echo htmlspecialchars($edit_donor['notes'] ?? ''); ?></textarea>
                        </div>
                        
                        <div style="margin-top: 20px;">
                            <button type="submit" class="btn btn-primary">Save Donor</button>
                            <a href="?view=donors" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="gms-card" id="donorsCard">
                <h2>Donor Management</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                    <a href="?view=donors&action=new" class="btn btn-primary">➕ Add New Donor</a>
                    <button onclick="downloadTemplate('donors')" class="btn" style="background: #17a2b8; color: white;">📥 Download Template</button>
                    <button onclick="importTemplate('donors')" class="btn" style="background: #28a745; color: white;">📤 Import Data</button>
                    <button onclick="openAIAssistant('Donors')" class="btn" style="background: #9c27b0; color: white;">🤖 AI Assistant</button>
                </div>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px;">
                    <p style="color: #6c757d; font-size: 16px; margin: 0;">
                        <strong>📋 Donor Registration</strong><br>
                        Click "<strong>Add New Donor</strong>" above to register a new donor. The complete list of all registered donors will be displayed in the registration form.
                    </p>
                </div>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'opportunities'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $opportunity_id = isset($_GET['opportunity_id']) ? (int)$_GET['opportunity_id'] : 0;
        $edit_opportunity = null;
        if ($opportunity_id > 0 && $action !== 'new') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_funding_opportunities WHERE id = ?");
                $stmt->execute([$opportunity_id]);
                $edit_opportunity = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading opportunity: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($action === 'new' || ($edit_opportunity && $action === 'edit')): ?>
            <div class="gms-card">
                <h2><?php echo $edit_opportunity ? 'Edit Opportunity' : 'Add New Funding Opportunity'; ?></h2>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_opportunity">
                    <?php if ($edit_opportunity): ?>
                        <input type="hidden" name="opportunity_id" value="<?php echo $edit_opportunity['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Opportunity Code</label>
                            <input type="text" name="opportunity_code" value="<?php echo htmlspecialchars($edit_opportunity['opportunity_code'] ?? 'OPP-' . date('YmdHis')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Opportunity Type *</label>
                            <select name="opportunity_type" required>
                                <option value="CFP" <?php echo ($edit_opportunity && ($edit_opportunity['opportunity_type'] === 'CFP' || !$edit_opportunity)) ? 'selected' : ''; ?>>CFP - Call for Proposals</option>
                                <option value="EOI" <?php echo ($edit_opportunity && $edit_opportunity['opportunity_type'] === 'EOI') ? 'selected' : ''; ?>>EOI - Expression of Interest</option>
                                <option value="RfP" <?php echo ($edit_opportunity && $edit_opportunity['opportunity_type'] === 'RfP') ? 'selected' : ''; ?>>RfP - Request for Proposals</option>
                                <option value="RfQ" <?php echo ($edit_opportunity && $edit_opportunity['opportunity_type'] === 'RfQ') ? 'selected' : ''; ?>>RfQ - Request for Quotations</option>
                                <option value="RFA" <?php echo ($edit_opportunity && $edit_opportunity['opportunity_type'] === 'RFA') ? 'selected' : ''; ?>>RFA - Request for Applications</option>
                                <option value="Other" <?php echo ($edit_opportunity && $edit_opportunity['opportunity_type'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Donor *</label>
                            <select name="donor_id" id="donor_select" required onchange="handleDonorSelection(); updateCurrencyFromDonor();">
                                <option value="">-- Select Donor --</option>
                                <?php 
                                $currentDonor = ($edit_opportunity && !empty($edit_opportunity['donor_other']) && empty($edit_opportunity['donor_id'])) ? 'OTHER' : ($edit_opportunity['donor_id'] ?? '');
                                        echo donor_dropdown_options($currentDonor, true, false);
                                ?>
                            </select>
                            <div id="donor_other_section" style="display: <?php echo ($edit_opportunity && empty($edit_opportunity['donor_id']) && !empty($edit_opportunity['donor_other'])) ? 'block' : 'none'; ?>; margin-top: 10px;">
                                <input type="text" name="donor_other" id="donor_other_input" placeholder="Enter donor name" value="<?php echo htmlspecialchars($edit_opportunity['donor_other'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Announcement Date *</label>
                            <input type="date" name="announcement_date" id="announcement_date" value="<?php echo $edit_opportunity && isset($edit_opportunity['announcement_date']) ? date('Y-m-d', strtotime($edit_opportunity['announcement_date'])) : date('Y-m-d'); ?>" required onchange="calculateDaysRemaining()">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" value="<?php echo htmlspecialchars($edit_opportunity['title'] ?? ''); ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                        <label>Opportunity Website URL</label>
                            <input type="url" name="opportunity_website" id="opportunity_website" placeholder="https://..." value="<?php echo htmlspecialchars($edit_opportunity['opportunity_website'] ?? ''); ?>" onblur="calculateDaysRemaining(); fetchAISummary();">
                            <small style="color: #666;">Website where the opportunity is posted. AI will analyze and summarize when you leave this field.</small>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label>Days Information</label>
                            <div id="days_remaining_display" style="padding: 10px; background: #f0f0f0; border-radius: 4px; font-weight: bold; text-align: center;">
                                <div style="margin-bottom: 8px;">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 3px;">Total Days</div>
                                    <div id="total_days_count" style="font-size: 16px; color: #667eea;">
                                        <?php 
                                        if ($edit_opportunity && !empty($edit_opportunity['announcement_date']) && !empty($edit_opportunity['submission_deadline'])) {
                                            $announcement = new DateTime($edit_opportunity['announcement_date']);
                                            $deadline = new DateTime($edit_opportunity['submission_deadline']);
                                            $totalDays = $announcement->diff($deadline)->days;
                                            echo $totalDays . ' days';
                                        } else {
                                            echo '--';
                                        }
                                        ?>
                                    </div>
                                </div>
                                <div style="border-top: 1px solid #ddd; padding-top: 8px;">
                                    <div style="font-size: 11px; color: #666; margin-bottom: 3px;">Days Remaining</div>
                                    <div id="days_count" style="font-size: 18px;">
                                        <?php 
                                        if ($edit_opportunity && !empty($edit_opportunity['announcement_date']) && !empty($edit_opportunity['submission_deadline'])) {
                                            $announcement = new DateTime($edit_opportunity['announcement_date']);
                                            $deadline = new DateTime($edit_opportunity['submission_deadline']);
                                            $now = new DateTime();
                                            $remainingDays = (int)$now->diff($deadline)->days;
                                            if ($deadline < $now) {
                                                $remainingDays = 0;
                                                echo '<span style="color: red; font-weight: bold;">0 (CLOSED)</span>';
                                            } elseif ($remainingDays <= 7) {
                                                echo '<span style="color: orange; font-weight: bold;">' . $remainingDays . ' (URGENT!)</span>';
                                            } elseif ($remainingDays <= 30) {
                                                echo '<span style="color: #ff9800;">' . $remainingDays . '</span>';
                                            } else {
                                                echo $remainingDays;
                                            }
                                        } else {
                                            echo '--';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <small style="color: #666;">Total: from announcement to deadline | Remaining: from today to deadline</small>
                            <div id="deadline_alert" style="margin-top: 5px; padding: 8px; border-radius: 4px; display: none; font-size: 12px;"></div>
                        </div>
                    </div>
                    
                    <div class="form-group" id="ai_summary_section" style="display: <?php echo !empty($edit_opportunity['ai_summary']) ? 'block' : 'none'; ?>; margin-top: 15px; padding: 15px; background: #e8f4f8; border-radius: 8px; border-left: 4px solid #17a2b8;">
                        <label style="font-weight: bold; color: #17a2b8;">🤖 AI-Generated Summary</label>
                        <div id="ai_summary_content" style="margin-top: 10px; padding: 10px; background: white; border-radius: 4px; min-height: 100px;">
                            <?php if (!empty($edit_opportunity['ai_summary'])): ?>
                                <?php echo nl2br(htmlspecialchars($edit_opportunity['ai_summary'])); ?>
                            <?php else: ?>
                                <p style="color: #666; font-style: italic;">Click "Generate AI Summary" or enter website URL and leave the field to auto-generate summary...</p>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="ai_summary" id="ai_summary_input" value="<?php echo htmlspecialchars($edit_opportunity['ai_summary'] ?? ''); ?>">
                        <button type="button" onclick="fetchAISummary()" class="btn" style="background: #17a2b8; color: white; margin-top: 10px;">🤖 Generate AI Summary from Website</button>
                    </div>
                    
                    <div class="form-group">
                        <label>Call Summary / Expression of Interest Summary *</label>
                        <textarea name="call_summary" rows="5" required placeholder="Summary of the call for project proposal or expression of interest..."><?php echo htmlspecialchars($edit_opportunity['call_summary'] ?? ''); ?></textarea>
                        <small style="color: #666;">Provide a summary of the call for proposals or expression of interest</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="4"><?php echo htmlspecialchars($edit_opportunity['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Upload Opportunity Documents</label>
                        <input type="file" name="opportunity_files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx" style="padding: 8px;">
                        <small style="color: #666;">Upload Word, PDF, or Excel files related to this opportunity (max 10MB per file)</small>
                        <?php if ($edit_opportunity && $opportunity_id > 0): ?>
                            <?php
                            try {
                                $fileStmt = $pdo->prepare("SELECT * FROM gms_opportunity_files WHERE opportunity_id = ? AND file_type = 'opportunity_document' ORDER BY uploaded_date DESC");
                                $fileStmt->execute([$opportunity_id]);
                                $existingFiles = $fileStmt->fetchAll(PDO::FETCH_ASSOC);
                                if (!empty($existingFiles)):
                            ?>
                                <div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                    <strong>Existing Files:</strong>
                                    <ul style="margin: 5px 0; padding-left: 20px;">
                                        <?php foreach ($existingFiles as $file): ?>
                                            <li>
                                                <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank"><?php echo htmlspecialchars($file['file_name']); ?></a>
                                                (<?php echo number_format($file['file_size'] / 1024, 2); ?> KB)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php 
                                endif;
                            } catch (Exception $e) {
                                // Table might not exist yet
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Submission Deadline *</label>
                            <input type="datetime-local" name="submission_deadline" id="submission_deadline" value="<?php echo $edit_opportunity && $edit_opportunity['submission_deadline'] ? date('Y-m-d\TH:i', strtotime($edit_opportunity['submission_deadline'])) : ''; ?>" required onchange="calculateDaysRemaining()">
                        </div>
                        <div class="form-group">
                            <label>Opportunity Status</label>
                            <select name="status" id="opportunity_status">
                                <option value="Open" <?php echo ($edit_opportunity && ($edit_opportunity['status'] === 'Open' || !$edit_opportunity)) ? 'selected' : ''; ?>>Open</option>
                                <option value="Under Review" <?php echo ($edit_opportunity && $edit_opportunity['status'] === 'Under Review') ? 'selected' : ''; ?>>Under Review</option>
                                <option value="Closed" <?php echo ($edit_opportunity && $edit_opportunity['status'] === 'Closed') ? 'selected' : ''; ?>>Closed</option>
                                <option value="Awarded" <?php echo ($edit_opportunity && $edit_opportunity['status'] === 'Awarded') ? 'selected' : ''; ?>>Awarded</option>
                                <option value="Cancelled" <?php echo ($edit_opportunity && $edit_opportunity['status'] === 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group" id="proposal_status_section" style="display: <?php echo ($edit_opportunity && $edit_opportunity['go_no_go_decision'] === 'Go') ? 'block' : 'none'; ?>; margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                        <label style="font-weight: bold;">Proposal Status *</label>
                        <select name="proposal_status" id="proposal_status" required>
                            <option value="Not Started" <?php echo ($edit_opportunity && ($edit_opportunity['proposal_status'] === 'Not Started' || !$edit_opportunity)) ? 'selected' : ''; ?>>Not Started</option>
                            <option value="In Progress" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Failed to Submit" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Failed to Submit') ? 'selected' : ''; ?>>Failed to Submit</option>
                            <option value="Deadline Passed" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Deadline Passed') ? 'selected' : ''; ?>>Deadline Passed</option>
                            <option value="Submitted" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Submitted') ? 'selected' : ''; ?>>Submitted</option>
                            <option value="Under Review" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Under Review') ? 'selected' : ''; ?>>Under Review</option>
                            <option value="Waiting for Response" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Waiting for Response') ? 'selected' : ''; ?>>Waiting for Response</option>
                            <option value="Declined" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Declined') ? 'selected' : ''; ?>>Declined</option>
                            <option value="Failed" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Failed') ? 'selected' : ''; ?>>Failed</option>
                            <option value="Succeeded" <?php echo ($edit_opportunity && $edit_opportunity['proposal_status'] === 'Succeeded') ? 'selected' : ''; ?>>Succeeded</option>
                        </select>
                        <small style="color: #666;">Status of the proposal preparation and submission process</small>
                        
                        <div id="proposal_files_section" style="margin-top: 15px; <?php echo ($edit_opportunity && in_array($edit_opportunity['proposal_status'], ['Submitted', 'Under Review', 'Waiting for Response', 'Declined', 'Failed', 'Succeeded'])) ? 'display: block;' : 'display: none;'; ?>">
                            <label>Upload Proposal Documents</label>
                            <input type="file" name="proposal_files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx" style="padding: 8px;">
                            <small style="color: #666;">Upload submitted proposal documents (max 10MB per file)</small>
                            <?php if ($edit_opportunity && $opportunity_id > 0): ?>
                                <?php
                                try {
                                    $propFileStmt = $pdo->prepare("SELECT * FROM gms_opportunity_files WHERE opportunity_id = ? AND file_type = 'proposal_document' ORDER BY uploaded_date DESC");
                                    $propFileStmt->execute([$opportunity_id]);
                                    $existingPropFiles = $propFileStmt->fetchAll(PDO::FETCH_ASSOC);
                                    if (!empty($existingPropFiles)):
                                ?>
                                    <div style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                        <strong>Submitted Proposal Files:</strong>
                                        <ul style="margin: 5px 0; padding-left: 20px;">
                                            <?php foreach ($existingPropFiles as $file): ?>
                                                <li>
                                                    <a href="<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank"><?php echo htmlspecialchars($file['file_name']); ?></a>
                                                    (<?php echo number_format($file['file_size'] / 1024, 2); ?> KB)
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php 
                                    endif;
                                } catch (Exception $e) {
                                    // Table might not exist yet
                                }
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Estimated Budget Min</label>
                            <input type="number" name="estimated_budget_min" step="0.01" value="<?php echo $edit_opportunity ? number_format($edit_opportunity['estimated_budget_min'], 2, '.', '') : '0'; ?>">
                        </div>
                        <div class="form-group">
                            <label>Estimated Budget Max</label>
                            <input type="number" name="estimated_budget_max" step="0.01" value="<?php echo $edit_opportunity ? number_format($edit_opportunity['estimated_budget_max'], 2, '.', '') : '0'; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Currency *</label>
                            <select name="currency" id="currency_select" required onchange="handleCurrencySelection()">
                                <?php 
                                $currentCurrency = ($edit_opportunity && !empty($edit_opportunity['currency_other']) && $edit_opportunity['currency'] === 'OTHER') ? 'OTHER' : ($edit_opportunity['currency'] ?? 'USD');
                                echo currency_dropdown_options($currentCurrency, true, true); 
                                ?>
                            </select>
                            <div id="currency_other_section" style="display: none; margin-top: 10px;">
                                <input type="text" name="currency_other" id="currency_other_input" placeholder="Enter currency code (e.g., XYZ)" value="<?php echo htmlspecialchars($edit_opportunity['currency_other'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Go/No-Go Decision</label>
                            <select name="go_no_go_decision" id="go_no_go_decision" onchange="toggleResponsiblePersonFields()">
                                <option value="Pending" <?php echo ($edit_opportunity && ($edit_opportunity['go_no_go_decision'] === 'Pending' || !$edit_opportunity)) ? 'selected' : ''; ?>>Pending</option>
                                <option value="Go" <?php echo ($edit_opportunity && $edit_opportunity['go_no_go_decision'] === 'Go') ? 'selected' : ''; ?>>Go</option>
                                <option value="No-Go" <?php echo ($edit_opportunity && $edit_opportunity['go_no_go_decision'] === 'No-Go') ? 'selected' : ''; ?>>No-Go</option>
                            </select>
                        </div>
                    </div>
                    
                    <div id="responsible_person_section" style="display: <?php echo ($edit_opportunity && $edit_opportunity['go_no_go_decision'] === 'Go') ? 'block' : 'none'; ?>; margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                        <h3 style="margin-top: 0;">Responsible Person for Proposal Preparation</h3>
                        <div style="margin-bottom: 10px;">
                            <button type="button" onclick="fillFromCurrentUser()" class="btn btn-sm" style="background: #17a2b8; color: white;">👤 Fill from My Profile</button>
                            <small style="color: #666; margin-left: 10px;">Auto-fill from your user profile</small>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Responsible Person Name *</label>
                                <input type="text" name="responsible_person_name" id="responsible_person_name" value="<?php echo htmlspecialchars($edit_opportunity['responsible_person_name'] ?? ''); ?>" placeholder="Full Name" required>
                            </div>
                            <div class="form-group">
                                <label>Position *</label>
                                <input type="text" name="responsible_person_position" id="responsible_person_position" value="<?php echo htmlspecialchars($edit_opportunity['responsible_person_position'] ?? ''); ?>" placeholder="e.g., Program Manager, Proposal Writer" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="responsible_person_email" id="responsible_person_email" value="<?php echo htmlspecialchars($edit_opportunity['responsible_person_email'] ?? ''); ?>" placeholder="email@example.com" required>
                            <small style="color: #666;">Email notifications will be sent to this address when task is assigned</small>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Go/No-Go Date</label>
                            <input type="date" name="go_no_go_date" value="<?php echo $edit_opportunity && isset($edit_opportunity['go_no_go_date']) ? date('Y-m-d', strtotime($edit_opportunity['go_no_go_date'])) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>Go/No-Go Notes</label>
                            <textarea name="go_no_go_notes" rows="3"><?php echo htmlspecialchars($edit_opportunity['go_no_go_notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <?php if ($edit_opportunity && $opportunity_id > 0): ?>
                        <div class="form-group" style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                            <h3 style="margin-top: 0;">💬 Comments & Communication</h3>
                            <div id="comments_section">
                                <?php
                                try {
                                    $commentStmt = $pdo->prepare("
                                        SELECT c.*, u.full_name as user_name, u.email as user_email
                                        FROM gms_opportunity_comments c
                                        LEFT JOIN users u ON c.commenter_user_id = u.id
                                        WHERE c.opportunity_id = ? AND c.parent_comment_id IS NULL
                                        ORDER BY c.created_at DESC
                                    ");
                                    $commentStmt->execute([$opportunity_id]);
                                    $comments = $commentStmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($comments as $comment):
                                        $replyStmt = $pdo->prepare("
                                            SELECT r.*, u.full_name as user_name
                                            FROM gms_opportunity_comments r
                                            LEFT JOIN users u ON r.commenter_user_id = u.id
                                            WHERE r.parent_comment_id = ?
                                            ORDER BY r.created_at ASC
                                        ");
                                        $replyStmt->execute([$comment['id']]);
                                        $replies = $replyStmt->fetchAll(PDO::FETCH_ASSOC);
                                ?>
                                    <div class="comment-item" id="comment_<?php echo $comment['id']; ?>" style="margin-bottom: 15px; padding: 15px; background: white; border-radius: 6px; border-left: 3px solid #667eea;">
                                        <div style="display: flex; justify-content: space-between; align-items: start;">
                                            <div>
                                                <strong><?php echo htmlspecialchars($comment['commenter_name']); ?></strong>
                                                <?php if ($comment['commenter_position']): ?>
                                                    <span style="color: #666; font-size: 12px;"> - <?php echo htmlspecialchars($comment['commenter_position']); ?></span>
                                                <?php endif; ?>
                                                <span style="color: #999; font-size: 11px; margin-left: 10px;"><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></span>
                                            </div>
                                            <?php if ($comment['commenter_user_id'] == ($user['id'] ?? 0)): ?>
                                                <div>
                                                    <button type="button" onclick="editComment(<?php echo $comment['id']; ?>)" class="btn btn-sm" style="background: #ffc107; color: #000; padding: 4px 8px;">✏️ Edit</button>
                                                    <button type="button" onclick="deleteComment(<?php echo $comment['id']; ?>)" class="btn btn-sm" style="background: #dc3545; color: white; padding: 4px 8px;">🗑️ Delete</button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div id="comment_text_<?php echo $comment['id']; ?>" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 4px;">
                                            <?php echo nl2br(htmlspecialchars($comment['comment_text'])); ?>
                                        </div>
                                        
                                        <?php if (!empty($replies)): ?>
                                            <div style="margin-top: 10px; margin-left: 20px; padding-left: 15px; border-left: 2px solid #ddd;">
                                                <?php foreach ($replies as $reply): ?>
                                                    <div id="reply_<?php echo $reply['id']; ?>" style="margin-bottom: 10px; padding: 10px; background: #f0f0f0; border-radius: 4px;">
                                                        <div style="display: flex; justify-content: space-between;">
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($reply['commenter_name']); ?></strong>
                                                                <span style="color: #999; font-size: 11px; margin-left: 10px;"><?php echo date('Y-m-d H:i', strtotime($reply['created_at'])); ?></span>
                                                            </div>
                                                            <?php if ($reply['commenter_user_id'] == ($user['id'] ?? 0)): ?>
                                                                <div>
                                                                    <button type="button" onclick="editReply(<?php echo $reply['id']; ?>)" class="btn btn-sm" style="background: #ffc107; color: #000; padding: 2px 6px; font-size: 11px;">Edit</button>
                                                                    <button type="button" onclick="deleteComment(<?php echo $reply['id']; ?>)" class="btn btn-sm" style="background: #dc3545; color: white; padding: 2px 6px; font-size: 11px;">Delete</button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div id="reply_text_<?php echo $reply['id']; ?>" style="margin-top: 5px;">
                                                            <?php echo nl2br(htmlspecialchars($reply['comment_text'])); ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div style="margin-top: 10px;">
                                            <button type="button" onclick="showReplyForm(<?php echo $comment['id']; ?>)" class="btn btn-sm" style="background: #17a2b8; color: white; padding: 4px 10px;">💬 Reply</button>
                                        </div>
                                        <div id="reply_form_<?php echo $comment['id']; ?>" style="display: none; margin-top: 10px; padding: 10px; background: #e8f4f8; border-radius: 4px;">
                                            <textarea id="reply_textarea_<?php echo $comment['id']; ?>" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Write your reply..."></textarea>
                                            <div style="margin-top: 5px;">
                                                <button type="button" onclick="submitReply(<?php echo $comment['id']; ?>)" class="btn btn-sm" style="background: #28a745; color: white; padding: 4px 10px;">Submit Reply</button>
                                                <button type="button" onclick="hideReplyForm(<?php echo $comment['id']; ?>)" class="btn btn-sm" style="background: #6c757d; color: white; padding: 4px 10px; margin-left: 5px;">Cancel</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach;
                                } catch (Exception $e) {
                                    // Comments table might not exist yet
                                }
                                ?>
                            </div>
                            
                            <div style="margin-top: 20px; padding: 15px; background: white; border-radius: 6px;">
                                <h4 style="margin-top: 0;">Add New Comment</h4>
                                <textarea name="new_comment" id="new_comment_text" rows="4" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Write your comment..."></textarea>
                                <div style="margin-top: 10px;">
                                    <button type="button" onclick="submitComment()" class="btn" style="background: #28a745; color: white;">💬 Add Comment</button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Opportunity</button>
                        <a href="?view=opportunities" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="gms-card" id="opportunitiesCard">
                <h2>Funding Opportunities</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                    <a href="?view=opportunities&action=new" class="btn btn-primary">➕ Add New Opportunity</a>
                    <button onclick="downloadTemplate('opportunities')" class="btn" style="background: #17a2b8; color: white;">📥 Download Template</button>
                    <button onclick="importTemplate('opportunities')" class="btn" style="background: #28a745; color: white;">📤 Import Data</button>
                    <button onclick="exportTable('opportunitiesTable', 'opportunities_export_' + new Date().toISOString().split('T')[0], 'excel')" class="btn" style="background: #6c757d; color: white;">📊 Export Excel</button>
                    <button onclick="exportTable('opportunitiesTable', 'opportunities_export_' + new Date().toISOString().split('T')[0], 'pdf')" class="btn" style="background: #dc3545; color: white;">📄 Export PDF</button>
                    <button onclick="printSection('opportunitiesCard')" class="btn" style="background: #ffc107; color: #000;">🖨️ Print</button>
                    <button onclick="openAIAssistant('Opportunities')" class="btn" style="background: #9c27b0; color: white;">🤖 AI Assistant</button>
                </div>
                
                <table class="gms-table" id="opportunitiesTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Donor</th>
                        <th>Deadline</th>
                        <th>Budget Range</th>
                        <th>Status</th>
                        <th>Go/No-Go</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($opportunities as $opp): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($opp['opportunity_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($opp['title']); ?></td>
                            <td><?php echo htmlspecialchars($opp['donor_name'] ?? 'N/A'); ?></td>
                            <td><?php echo $opp['submission_deadline'] ? date('Y-m-d', strtotime($opp['submission_deadline'])) : 'N/A'; ?></td>
                            <td>
                                <?php if ($opp['estimated_budget_min'] || $opp['estimated_budget_max']): ?>
                                    <?php echo number_format($opp['estimated_budget_min'] ?? 0, 2); ?> - 
                                    <?php echo number_format($opp['estimated_budget_max'] ?? 0, 2); ?> 
                                    <?php echo htmlspecialchars($opp['currency'] ?? 'USD'); ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td><span class="status-badge status-<?php echo strtolower($opp['status']); ?>"><?php echo htmlspecialchars($opp['status']); ?></span></td>
                            <td><span class="status-badge"><?php echo htmlspecialchars($opp['go_no_go_decision'] ?? 'Pending'); ?></span></td>
                            <td>
                                <a href="?view=opportunities&opportunity_id=<?php echo $opp['id']; ?>&action=edit" class="btn btn-primary btn-sm">✏️ Edit</a>
                                <a href="?view=opportunities&opportunity_id=<?php echo $opp['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'workplans'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $workplan_id = isset($_GET['workplan_id']) ? (int)$_GET['workplan_id'] : 0;
        $edit_workplan = null;
        if ($workplan_id > 0 && $action !== 'new') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_work_plans WHERE id = ?");
                $stmt->execute([$workplan_id]);
                $edit_workplan = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading workplan: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($grant_id > 0 && ($action === 'new' || ($edit_workplan && $action === 'edit'))): ?>
            <div class="gms-card">
                <h2><?php echo $edit_workplan ? 'Edit Work Plan Activity' : 'Add Work Plan Activity'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_workplan">
                    <input type="hidden" name="grant_id" value="<?php echo $grant_id; ?>">
                    <?php if ($edit_workplan): ?>
                        <input type="hidden" name="workplan_id" value="<?php echo $edit_workplan['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Activity Code</label>
                            <input type="text" name="activity_code" value="<?php echo htmlspecialchars($edit_workplan['activity_code'] ?? 'ACT-' . date('YmdHis')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Activity Name *</label>
                            <input type="text" name="activity_name" value="<?php echo htmlspecialchars($edit_workplan['activity_name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"><?php echo htmlspecialchars($edit_workplan['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $edit_workplan && $edit_workplan['start_date'] ? date('Y-m-d', strtotime($edit_workplan['start_date'])) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?php echo $edit_workplan && $edit_workplan['end_date'] ? date('Y-m-d', strtotime($edit_workplan['end_date'])) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="Planned" <?php echo ($edit_workplan && ($edit_workplan['status'] === 'Planned' || !$edit_workplan)) ? 'selected' : ''; ?>>Planned</option>
                                <option value="In Progress" <?php echo ($edit_workplan && $edit_workplan['status'] === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo ($edit_workplan && $edit_workplan['status'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Delayed" <?php echo ($edit_workplan && $edit_workplan['status'] === 'Delayed') ? 'selected' : ''; ?>>Delayed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Progress (%)</label>
                            <input type="number" name="progress_percentage" min="0" max="100" step="0.1" value="<?php echo $edit_workplan ? number_format($edit_workplan['progress_percentage'], 1, '.', '') : '0'; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3"><?php echo htmlspecialchars($edit_workplan['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    
                    <?php
                    // Load Workplan Registers for dropdown (optional)
                    $wp_registers = [];
                    try {
                        $st = $pdo->prepare("SELECT id, register_code, title FROM gms_workplan_registers WHERE grant_id = ? AND is_active = 1 ORDER BY period_start DESC, id DESC");
                        $st->execute([$grant_id]);
                        $wp_registers = $st->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) { $wp_registers = []; }
                    ?>
                    <div style="border:1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-top: 12px; background: #fafafa;">
                        <h3 style="margin:0 0 10px 0; font-size: 14px;">UN/INGO Donor-Standard Fields (optional)</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Workplan Register</label>
                                <select name="workplan_register_id">
                                    <option value="">(Optional) Select register</option>
                                    <?php foreach ($wp_registers as $wr): ?>
                                        <option value="<?php echo (int)$wr['id']; ?>" <?php echo ($edit_workplan && (int)($edit_workplan['workplan_register_id'] ?? 0) === (int)$wr['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(($wr['register_code'] ?? '') . ' - ' . ($wr['title'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Location Name</label>
                                <input type="text" name="location_name" value="<?php echo htmlspecialchars($edit_workplan['location_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Responsible Person</label>
                                <input type="text" name="responsible_person" value="<?php echo htmlspecialchars($edit_workplan['responsible_person'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Responsible Email</label>
                                <input type="email" name="responsible_email" value="<?php echo htmlspecialchars($edit_workplan['responsible_email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Result/Output Link (Logframe)</label>
                            <textarea name="result_output" rows="2"><?php echo htmlspecialchars($edit_workplan['result_output'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Indicator Text</label>
                            <textarea name="indicator_text" rows="2"><?php echo htmlspecialchars($edit_workplan['indicator_text'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Target Quantity</label>
                                <input type="number" step="0.01" name="target_quantity" value="<?php echo htmlspecialchars($edit_workplan['target_quantity'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Target Unit</label>
                                <input type="text" name="target_unit" value="<?php echo htmlspecialchars($edit_workplan['target_unit'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Means of Verification</label>
                            <textarea name="means_of_verification" rows="2"><?php echo htmlspecialchars($edit_workplan['means_of_verification'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Dependencies</label>
                            <textarea name="dependencies" rows="2"><?php echo htmlspecialchars($edit_workplan['dependencies'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Budget Amount (optional)</label>
                                <input type="number" step="0.01" name="budget_amount" value="<?php echo htmlspecialchars($edit_workplan['budget_amount'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Budget Currency</label>
                                <input type="text" name="budget_currency" value="<?php echo htmlspecialchars($edit_workplan['budget_currency'] ?? 'USD'); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>WBS / Budget Line Code</label>
                                <input type="text" name="wbs_code" value="<?php echo htmlspecialchars($edit_workplan['wbs_code'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Activity Sequence</label>
                                <input type="number" name="activity_sequence" value="<?php echo htmlspecialchars($edit_workplan['activity_sequence'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

<div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Activity</button>
                        <a href="?view=workplans&grant_id=<?php echo $grant_id; ?>" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif ($grant_id > 0): ?>
            <div class="gms-card">
                <h2>Work Plans</h2>

                <?php
                // Workplan Register management (UN/INGO standard)
                $wpreg_action = $_GET['wpreg_action'] ?? '';
                $wpreg_id = isset($_GET['wpreg_id']) ? (int)$_GET['wpreg_id'] : 0;
                $edit_wpreg = null;
                if ($wpreg_id > 0 && $wpreg_action === 'edit') {
                    try {
                        $st = $pdo->prepare("SELECT * FROM gms_workplan_registers WHERE id = ? AND grant_id = ? LIMIT 1");
                        $st->execute([$wpreg_id, $grant_id]);
                        $edit_wpreg = $st->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) { $edit_wpreg = null; }
                }
                $wp_registers = [];
                try {
                    $st = $pdo->prepare("SELECT * FROM gms_workplan_registers WHERE grant_id = ? AND is_active = 1 ORDER BY period_start DESC, id DESC");
                    $st->execute([$grant_id]);
                    $wp_registers = $st->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) { $wp_registers = []; }
                ?>

                <div style="border:1px solid #e5e7eb; border-radius: 10px; padding: 12px; margin: 12px 0; background: #fafafa;">
                    <div style="display:flex; justify-content: space-between; align-items:center; gap: 10px; flex-wrap: wrap;">
                        <div>
                            <h3 style="margin:0; font-size: 14px;">📋 Workplan Register</h3>
                            <div style="font-size:12px; color:#666;">Group activities by phase/period/workpackage (common in UN agencies & big donor workplans).</div>
                        </div>
                        <div style="display:flex; gap: 8px; flex-wrap: wrap;">
                            <a class="btn btn-primary btn-sm" href="?view=workplans&grant_id=<?php echo $grant_id; ?>&wpreg_action=new">➕ Add Register</a>
                            <a class="btn btn-sm" style="background:#6c757d;color:#fff;" href="?action=export&module=workplan_registers&format=excel&grant_id=<?php echo $grant_id; ?>">📊 Export Excel</a>
                            <a class="btn btn-sm" style="background:#dc3545;color:#fff;" href="?action=export&module=workplan_registers&format=pdf&grant_id=<?php echo $grant_id; ?>">📄 Export PDF</a>
                            <button class="btn btn-sm" style="background:#17a2b8;color:#fff;" onclick="downloadTemplate('workplan_registers')">📥 Download Template</button>
                            <button class="btn btn-sm" style="background:#28a745;color:#fff;" onclick="importTemplate('workplan_registers')">📤 Import Data</button>
                        </div>
                    </div>

                    <?php if ($wpreg_action === 'new' || $edit_wpreg): ?>
                        <form method="POST" style="margin-top:12px;">
                            <input type="hidden" name="action" value="save_workplan_register">
                            <input type="hidden" name="grant_id" value="<?php echo $grant_id; ?>">
                            <?php if ($edit_wpreg): ?>
                                <input type="hidden" name="workplan_register_id" value="<?php echo (int)$edit_wpreg['id']; ?>">
                            <?php endif; ?>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Register Code</label>
                                    <input type="text" name="register_code" value="<?php echo htmlspecialchars($edit_wpreg['register_code'] ?? ('WPREG-' . date('Ymd'))); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Title *</label>
                                    <input type="text" name="title" required value="<?php echo htmlspecialchars($edit_wpreg['title'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Period Start</label>
                                    <input type="date" name="period_start" value="<?php echo !empty($edit_wpreg['period_start']) ? date('Y-m-d', strtotime($edit_wpreg['period_start'])) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Period End</label>
                                    <input type="date" name="period_end" value="<?php echo !empty($edit_wpreg['period_end']) ? date('Y-m-d', strtotime($edit_wpreg['period_end'])) : ''; ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Location Name</label>
                                    <input type="text" name="location_name" value="<?php echo htmlspecialchars($edit_wpreg['location_name'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <?php $stx = $edit_wpreg['status'] ?? 'Active'; ?>
                                        <option value="Active" <?php echo ($stx==='Active')?'selected':''; ?>>Active</option>
                                        <option value="Planned" <?php echo ($stx==='Planned')?'selected':''; ?>>Planned</option>
                                        <option value="Completed" <?php echo ($stx==='Completed')?'selected':''; ?>>Completed</option>
                                        <option value="Closed" <?php echo ($stx==='Closed')?'selected':''; ?>>Closed</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Responsible Person</label>
                                    <input type="text" name="responsible_person" value="<?php echo htmlspecialchars($edit_wpreg['responsible_person'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Responsible Email</label>
                                    <input type="email" name="responsible_email" value="<?php echo htmlspecialchars($edit_wpreg['responsible_email'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" rows="2"><?php echo htmlspecialchars($edit_wpreg['notes'] ?? ''); ?></textarea>
                            </div>

                            <div style="display:flex; gap: 10px; flex-wrap: wrap; margin-top: 8px;">
                                <button type="submit" class="btn btn-primary"><?php echo $edit_wpreg ? 'Update Register' : 'Save Register'; ?></button>
                                <a class="btn" style="background:#6c757d;color:#fff;" href="?view=workplans&grant_id=<?php echo $grant_id; ?>">Cancel</a>
                            </div>
                        </form>
                    <?php endif; ?>

                    <div style="overflow:auto; margin-top: 10px;">
                        <table class="gms-table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Title</th>
                                    <th>Period</th>
                                    <th>Location</th>
                                    <th>Responsible</th>
                                    <th>Status</th>
                                    <th class="no-export">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($wp_registers)): ?>
                                    <tr><td colspan="7" style="text-align:center; color:#666;">No registers yet. Add one to organize activities.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($wp_registers as $r): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($r['register_code'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($r['title'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars(($r['period_start'] ?? '') . ' → ' . ($r['period_end'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars($r['location_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($r['responsible_person'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($r['status'] ?? ''); ?></td>
                                            <td class="no-export" style="white-space:nowrap;">
                                                <a class="btn btn-primary btn-sm" href="?view=workplans&grant_id=<?php echo $grant_id; ?>&wpreg_action=edit&wpreg_id=<?php echo (int)$r['id']; ?>">✏️ Edit</a>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this register? (Activities will remain; register link becomes empty)');">
                                                    <input type="hidden" name="action" value="delete_workplan_register">
                                                    <input type="hidden" name="grant_id" value="<?php echo $grant_id; ?>">
                                                    <input type="hidden" name="workplan_register_id" value="<?php echo (int)$r['id']; ?>">
                                                    <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;padding:6px 12px;">🗑️ Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>



                <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                    <a href="?view=workplans&grant_id=<?php echo $grant_id; ?>&action=new" class="btn btn-primary">➕ Add Activity</a>
                    <button onclick="downloadTemplate('workplans')" class="btn" style="background: #17a2b8; color: white;">📥 Download Template</button>
                    <button onclick="importTemplate('workplans')" class="btn" style="background: #28a745; color: white;">📤 Import Data</button>
                    <button onclick="exportTable('workplansTable','workplans','excel')" class="btn" style="background: #6c757d; color: white;">📊 Export Excel</button>
                    <button onclick="exportTable('workplansTable','workplans','pdf')" class="btn" style="background: #dc3545; color: white;">📄 Export PDF</button>
                    <button onclick="printSection('workplansSection')" class="btn" style="background: #ffc107; color: #000;">🖨️ Print</button>
                    <button onclick="openAIAssistant('Work Plans')" class="btn" style="background: #9c27b0; color: white;">🤖 AI Assistant</button>
                </div>

                <table class="gms-table" id="workplansTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Activity Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($workplans as $wp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($wp['activity_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($wp['activity_name']); ?></td>
                                <td><?php echo $wp['start_date'] ? date('Y-m-d', strtotime($wp['start_date'])) : 'N/A'; ?></td>
                                <td><?php echo $wp['end_date'] ? date('Y-m-d', strtotime($wp['end_date'])) : 'N/A'; ?></td>
                                <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $wp['status'])); ?>"><?php echo htmlspecialchars($wp['status']); ?></span></td>
                                <td><?php echo number_format($wp['progress_percentage'], 1); ?>%</td>
                                <td>
                                    <a href="?view=workplans&grant_id=<?php echo $grant_id; ?>&workplan_id=<?php echo $wp['id']; ?>&action=edit" class="btn btn-primary btn-sm">✏️ Edit</a>
                                    <a href="?view=workplans&grant_id=<?php echo $grant_id; ?>&workplan_id=<?php echo $wp['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="gms-card" id="workplansSection">
                <h2>Work Plans</h2>
                <p>Select a grant to manage work plans and registers.</p>
                <form method="GET" action="">
                    <input type="hidden" name="view" value="workplans">
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label>Select Grant *</label>
                            <select name="grant_id" required>
                                <option value="">-- Select Grant --</option>
                                <?php foreach ($grants as $g): ?>
                                    <option value="<?php echo (int)$g['id']; ?>">
                                        <?php echo htmlspecialchars(($g['grant_code'] ?? 'GRANT') . ' - ' . ($g['title'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="align-self: flex-end;">
                            <button type="submit" class="btn btn-primary">Open Work Plans</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'partners'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0;
        $edit_partner = null;
        if ($partner_id > 0 && $action !== 'new') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_partners WHERE id = ?");
                $stmt->execute([$partner_id]);
                $edit_partner = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading partner: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($action === 'new' || ($edit_partner && $action === 'edit')): ?>
            <div class="gms-card">
                <h2><?php echo $edit_partner ? 'Edit Partner' : 'Add New Partner'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_partner">
                    <?php if ($edit_partner): ?>
                        <input type="hidden" name="partner_id" value="<?php echo $edit_partner['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Partner Code</label>
                            <input type="text" name="partner_code" value="<?php echo htmlspecialchars($edit_partner['partner_code'] ?? 'PART-' . date('YmdHis')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Partner Name *</label>
                            <input type="text" name="name" value="<?php echo htmlspecialchars($edit_partner['name'] ?? ''); ?>" required>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Partner Type *</label>
                            <select name="partner_type" required>
                                <option value="NGO" <?php echo ($edit_partner && ($edit_partner['partner_type'] === 'NGO' || !$edit_partner)) ? 'selected' : ''; ?>>NGO</option>
                                <option value="CBO" <?php echo ($edit_partner && $edit_partner['partner_type'] === 'CBO') ? 'selected' : ''; ?>>CBO</option>
                                <option value="Government" <?php echo ($edit_partner && $edit_partner['partner_type'] === 'Government') ? 'selected' : ''; ?>>Government</option>
                                <option value="Private Sector" <?php echo ($edit_partner && $edit_partner['partner_type'] === 'Private Sector') ? 'selected' : ''; ?>>Private Sector</option>
                                <option value="Other" <?php echo ($edit_partner && $edit_partner['partner_type'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Contact Email</label>
                            <input type="email" name="contact_email" value="<?php echo htmlspecialchars($edit_partner['contact_email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Due Diligence Status</label>
                            <select name="due_diligence_status">
                                <option value="Pending" <?php echo ($edit_partner && ($edit_partner['due_diligence_status'] === 'Pending' || !$edit_partner)) ? 'selected' : ''; ?>>Pending</option>
                                <option value="In Progress" <?php echo ($edit_partner && $edit_partner['due_diligence_status'] === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo ($edit_partner && $edit_partner['due_diligence_status'] === 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                <option value="Failed" <?php echo ($edit_partner && $edit_partner['due_diligence_status'] === 'Failed') ? 'selected' : ''; ?>>Failed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Risk Rating</label>
                            <select name="risk_rating">
                                <option value="Low" <?php echo ($edit_partner && ($edit_partner['risk_rating'] === 'Low' || !$edit_partner)) ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo ($edit_partner && $edit_partner['risk_rating'] === 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo ($edit_partner && $edit_partner['risk_rating'] === 'High') ? 'selected' : ''; ?>>High</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3"><?php echo htmlspecialchars($edit_partner['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Partner</button>
                        <a href="?view=partners" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="gms-card" id="partnersCard">
                <h2>Partners & Sub-grants</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                    <a href="?view=partners&action=new" class="btn btn-primary">➕ Add New Partner</a>
                    <button onclick="downloadTemplate('partners')" class="btn" style="background: #17a2b8; color: white;">📥 Download Template</button>
                    <button onclick="importTemplate('partners')" class="btn" style="background: #28a745; color: white;">📤 Import Data</button>
                    <button onclick="exportTable('partnersTable', 'partners_export_' + new Date().toISOString().split('T')[0], 'excel')" class="btn" style="background: #6c757d; color: white;">📊 Export Excel</button>
                    <button onclick="exportTable('partnersTable', 'partners_export_' + new Date().toISOString().split('T')[0], 'pdf')" class="btn" style="background: #dc3545; color: white;">📄 Export PDF</button>
                    <button onclick="printSection('partnersCard')" class="btn" style="background: #ffc107; color: #000;">🖨️ Print</button>
                    <button onclick="openAIAssistant('Partners')" class="btn" style="background: #9c27b0; color: white;">🤖 AI Assistant</button>
                </div>
                
                <table class="gms-table" id="partnersTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Contact Email</th>
                        <th>Due Diligence</th>
                        <th>Risk Rating</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($partners as $partner): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($partner['partner_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($partner['name']); ?></td>
                            <td><?php echo htmlspecialchars($partner['partner_type']); ?></td>
                            <td><?php echo htmlspecialchars($partner['contact_email'] ?? 'N/A'); ?></td>
                            <td><span class="status-badge"><?php echo htmlspecialchars($partner['due_diligence_status']); ?></span></td>
                            <td><span class="status-badge"><?php echo htmlspecialchars($partner['risk_rating']); ?></span></td>
                            <td>
                                <a href="?view=partners&partner_id=<?php echo $partner['id']; ?>&action=edit" class="btn btn-primary btn-sm">✏️ Edit</a>
                                <a href="?view=partners&partner_id=<?php echo $partner['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'budgets'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $budget_id = isset($_GET['budget_id']) ? (int)$_GET['budget_id'] : 0;
        $edit_budget = null;
        if ($budget_id > 0 && $action !== 'new') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_grant_budgets WHERE id = ?");
                $stmt->execute([$budget_id]);
                $edit_budget = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading budget: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($grant_id > 0 && ($action === 'new' || ($edit_budget && $action === 'edit'))): ?>
            <div class="gms-card">
                <h2><?php echo $edit_budget ? 'Edit Budget Line' : 'Add Budget Line'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_budget">
                    <input type="hidden" name="grant_id" value="<?php echo $grant_id; ?>">
                    <?php if ($edit_budget): ?>
                        <input type="hidden" name="budget_id" value="<?php echo $edit_budget['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Budget Line Code</label>
                            <input type="text" name="budget_line_code" value="<?php echo htmlspecialchars($edit_budget['budget_line_code'] ?? 'BL-' . date('YmdHis')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Budget Category *</label>
                            <select name="budget_category" required>
                                <option value="Personnel" <?php echo ($edit_budget && ($edit_budget['budget_category'] === 'Personnel' || !$edit_budget)) ? 'selected' : ''; ?>>Personnel</option>
                                <option value="Travel" <?php echo ($edit_budget && $edit_budget['budget_category'] === 'Travel') ? 'selected' : ''; ?>>Travel</option>
                                <option value="Equipment" <?php echo ($edit_budget && $edit_budget['budget_category'] === 'Equipment') ? 'selected' : ''; ?>>Equipment</option>
                                <option value="Supplies" <?php echo ($edit_budget && $edit_budget['budget_category'] === 'Supplies') ? 'selected' : ''; ?>>Supplies</option>
                                <option value="Training" <?php echo ($edit_budget && $edit_budget['budget_category'] === 'Training') ? 'selected' : ''; ?>>Training</option>
                                <option value="Other" <?php echo ($edit_budget && $edit_budget['budget_category'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" rows="3"><?php echo htmlspecialchars($edit_budget['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Budget Amount *</label>
                            <input type="number" name="budget_amount" step="0.01" value="<?php echo $edit_budget ? number_format($edit_budget['budget_amount'], 2, '.', '') : '0'; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency">
                                <option value="USD" <?php echo ($edit_budget && ($edit_budget['currency'] === 'USD' || !$edit_budget)) ? 'selected' : ''; ?>>USD</option>
                                <option value="ETB" <?php echo ($edit_budget && $edit_budget['currency'] === 'ETB') ? 'selected' : ''; ?>>ETB</option>
                                <option value="EUR" <?php echo ($edit_budget && $edit_budget['currency'] === 'EUR') ? 'selected' : ''; ?>>EUR</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Committed Amount</label>
                            <input type="number" name="committed_amount" step="0.01" value="<?php echo $edit_budget ? number_format($edit_budget['committed_amount'], 2, '.', '') : '0'; ?>">
                        </div>
                        <div class="form-group">
                            <label>Spent Amount</label>
                            <input type="number" name="spent_amount" step="0.01" value="<?php echo $edit_budget ? number_format($edit_budget['spent_amount'], 2, '.', '') : '0'; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="3"><?php echo htmlspecialchars($edit_budget['notes'] ?? ''); ?></textarea>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Budget Line</button>
                        <a href="?view=budgets&grant_id=<?php echo $grant_id; ?>" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif ($grant_id > 0): ?>
            <div class="gms-card">
                <h2>Grant Budgets</h2>
                <a href="?view=budgets&grant_id=<?php echo $grant_id; ?>&action=new" class="btn btn-primary" style="margin-bottom: 20px;">➕ Add Budget Line</a>
                <?php
                $total_budgeted = array_sum(array_column($budgets, 'budget_amount'));
                $total_committed = array_sum(array_column($budgets, 'committed_amount'));
                $total_spent = array_sum(array_column($budgets, 'spent_amount'));
                ?>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <strong>Budget Summary:</strong> 
                    Budgeted: <?php echo number_format($total_budgeted, 2); ?> | 
                    Committed: <?php echo number_format($total_committed, 2); ?> | 
                    Spent: <?php echo number_format($total_spent, 2); ?> | 
                    Remaining: <?php echo number_format($total_budgeted - $total_spent, 2); ?>
                </div>
                <table class="gms-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Budgeted</th>
                            <th>Committed</th>
                            <th>Spent</th>
                            <th>Remaining</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($budgets as $budget): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($budget['budget_line_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($budget['budget_category']); ?></td>
                                <td><?php echo htmlspecialchars($budget['description'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($budget['budget_amount'], 2); ?></td>
                                <td><?php echo number_format($budget['committed_amount'], 2); ?></td>
                                <td><?php echo number_format($budget['spent_amount'], 2); ?></td>
                                <td><?php echo number_format($budget['budget_amount'] - $budget['spent_amount'], 2); ?></td>
                                <td>
                                    <a href="?view=budgets&grant_id=<?php echo $grant_id; ?>&budget_id=<?php echo $budget['id']; ?>&action=edit" class="btn btn-primary btn-sm">✏️ Edit</a>
                                    <a href="?view=budgets&grant_id=<?php echo $grant_id; ?>&budget_id=<?php echo $budget['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="gms-card">
                <h2>Grant Budgets</h2>
                <p>Please select a grant to view budgets.</p>
            </div>
        <?php endif; ?>

    <?php elseif ($view === 'reporting'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
        $edit_report = null;
        if ($report_id > 0 && $action !== 'new') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_reporting_schedule WHERE id = ?");
                $stmt->execute([$report_id]);
                $edit_report = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading report: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($action === 'new' || ($edit_report && $action === 'edit')): ?>
            <div class="gms-card">
                <h2><?php echo $edit_report ? 'Edit Report Schedule' : 'Add Report Schedule'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_report">
                    <?php if ($edit_report): ?>
                        <input type="hidden" name="report_id" value="<?php echo $edit_report['id']; ?>">
                        <input type="hidden" name="grant_id" value="<?php echo $edit_report['grant_id']; ?>">
                    <?php else: ?>
                        <div class="form-group">
                            <label>Grant *</label>
                            <select name="grant_id" required>
                                <option value="">-- Select Grant --</option>
                                <?php foreach ($grants as $g): ?>
                                    <option value="<?php echo $g['id']; ?>" <?php echo ($grant_id == $g['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($g['grant_code'] . ' - ' . $g['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Report Type *</label>
                            <select name="report_type" required>
                                <option value="Financial" <?php echo ($edit_report && ($edit_report['report_type'] === 'Financial' || !$edit_report)) ? 'selected' : ''; ?>>Financial</option>
                                <option value="Narrative" <?php echo ($edit_report && $edit_report['report_type'] === 'Narrative') ? 'selected' : ''; ?>>Narrative</option>
                                <option value="Combined" <?php echo ($edit_report && $edit_report['report_type'] === 'Combined') ? 'selected' : ''; ?>>Combined</option>
                                <option value="Annual" <?php echo ($edit_report && $edit_report['report_type'] === 'Annual') ? 'selected' : ''; ?>>Annual</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Report Number</label>
                            <input type="number" name="report_number" min="1" value="<?php echo $edit_report ? $edit_report['report_number'] : '1'; ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Due Date *</label>
                            <input type="date" name="due_date" value="<?php echo $edit_report && $edit_report['due_date'] ? date('Y-m-d', strtotime($edit_report['due_date'])) : ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="Pending" <?php echo ($edit_report && ($edit_report['status'] === 'Pending' || !$edit_report)) ? 'selected' : ''; ?>>Pending</option>
                                <option value="Draft" <?php echo ($edit_report && $edit_report['status'] === 'Draft') ? 'selected' : ''; ?>>Draft</option>
                                <option value="Submitted" <?php echo ($edit_report && $edit_report['status'] === 'Submitted') ? 'selected' : ''; ?>>Submitted</option>
                                <option value="Overdue" <?php echo ($edit_report && $edit_report['status'] === 'Overdue') ? 'selected' : ''; ?>>Overdue</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Submission Date</label>
                            <input type="date" name="submission_date" value="<?php echo $edit_report && $edit_report['submission_date'] ? date('Y-m-d', strtotime($edit_report['submission_date'])) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label>Donor Feedback</label>
                            <textarea name="donor_feedback" rows="3"><?php echo htmlspecialchars($edit_report['donor_feedback'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Report Schedule</button>
                        <a href="?view=reporting<?php echo $grant_id > 0 ? '&grant_id=' . $grant_id : ''; ?>" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="gms-card" id="reportingCard">
                <h2>Reporting Schedule & Timeliness</h2>
                <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
                    <a href="?view=reporting&action=new" class="btn btn-primary">➕ Add Report Schedule</a>
                    <button onclick="downloadTemplate('reporting')" class="btn" style="background: #17a2b8; color: white;">📥 Download Template</button>
                    <button onclick="importTemplate('reporting')" class="btn" style="background: #28a745; color: white;">📤 Import Data</button>
                    <button onclick="exportTable('reportingTable', 'reporting_export_' + new Date().toISOString().split('T')[0], 'excel')" class="btn" style="background: #6c757d; color: white;">📊 Export Excel</button>
                    <button onclick="exportTable('reportingTable', 'reporting_export_' + new Date().toISOString().split('T')[0], 'pdf')" class="btn" style="background: #dc3545; color: white;">📄 Export PDF</button>
                    <button onclick="printSection('reportingCard')" class="btn" style="background: #ffc107; color: #000;">🖨️ Print</button>
                    <button onclick="openAIAssistant('Reporting')" class="btn" style="background: #9c27b0; color: white;">🤖 AI Assistant</button>
                </div>
            
            <?php
            $today = date('Y-m-d');
            $overdue = array_filter($reports, fn($r) => $r['status'] === 'Overdue' || ($r['due_date'] < $today && $r['status'] !== 'Submitted'));
            $upcoming = array_filter($reports, fn($r) => $r['due_date'] >= $today && $r['due_date'] <= date('Y-m-d', strtotime('+30 days')) && $r['status'] !== 'Submitted');
            ?>
            
            <?php if (!empty($overdue)): ?>
                <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <strong>⚠️ Overdue Reports:</strong> <?php echo count($overdue); ?> report(s) are overdue
                </div>
            <?php endif; ?>
            
            <?php if (!empty($upcoming)): ?>
                <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <strong>📅 Upcoming Reports:</strong> <?php echo count($upcoming); ?> report(s) due in next 30 days
                </div>
            <?php endif; ?>
            
            <table class="gms-table">
                <thead>
                    <tr>
                        <th>Grant</th>
                        <th>Report Type</th>
                        <th>Report #</th>
                        <th>Due Date</th>
                        <th>Submission Date</th>
                        <th>Status</th>
                        <th>Timeliness</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $report): ?>
                        <?php
                        $daysLate = 0;
                        $timeliness = 'On Time';
                        $timelinessClass = 'status-active';
                        if ($report['due_date'] < $today && $report['status'] !== 'Submitted') {
                            $daysLate = (strtotime($today) - strtotime($report['due_date'])) / (60 * 60 * 24);
                            $timeliness = $daysLate . ' days overdue';
                            $timelinessClass = 'status-suspended';
                        } elseif ($report['submission_date'] && $report['due_date']) {
                            $daysDiff = (strtotime($report['submission_date']) - strtotime($report['due_date'])) / (60 * 60 * 24);
                            if ($daysDiff > 0) {
                                $timeliness = $daysDiff . ' days late';
                                $timelinessClass = 'status-suspended';
                            } elseif ($daysDiff < -7) {
                                $timeliness = abs($daysDiff) . ' days early';
                                $timelinessClass = 'status-active';
                            } else {
                                $timeliness = 'On Time';
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($report['grant_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($report['report_type']); ?></td>
                            <td><?php echo htmlspecialchars($report['report_number'] ?? 'N/A'); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($report['due_date'])); ?></td>
                            <td><?php echo $report['submission_date'] ? date('Y-m-d', strtotime($report['submission_date'])) : 'Not submitted'; ?></td>
                            <td><span class="status-badge status-<?php echo strtolower($report['status']); ?>"><?php echo htmlspecialchars($report['status']); ?></span></td>
                            <td><span class="status-badge <?php echo $timelinessClass; ?>"><?php echo $timeliness; ?></span></td>
                            <td>
                                <a href="?view=reporting&report_id=<?php echo $report['id']; ?>&action=edit" class="btn btn-primary btn-sm">✏️ Edit</a>
                                <a href="?view=reporting&report_id=<?php echo $report['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this report schedule?');">
                                    <input type="hidden" name="action" value="delete_report">
                                    <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                    <button type="submit" class="btn btn-sm" style="background: #dc3545; color: white; padding: 6px 12px;">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif ($view === 'risks'): ?>
        <?php
        $action = $_GET['action'] ?? '';
        $risk_id = isset($_GET['risk_id']) ? (int)$_GET['risk_id'] : 0;
        $issue_id = isset($_GET['issue_id']) ? (int)$_GET['issue_id'] : 0;
        $edit_risk = null;
        $edit_issue = null;
        if ($risk_id > 0 && $action !== 'new_risk' && $action !== 'new_compliance') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_risk_register WHERE id = ?");
                $stmt->execute([$risk_id]);
                $edit_risk = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading risk: " . $e->getMessage());
            }
        }
        if ($issue_id > 0 && $action !== 'new_risk' && $action !== 'new_compliance') {
            try {
                $stmt = $pdo->prepare("SELECT * FROM gms_compliance_issues WHERE id = ?");
                $stmt->execute([$issue_id]);
                $edit_issue = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log("Error loading compliance issue: " . $e->getMessage());
            }
        }
        ?>
        
        <?php if ($grant_id > 0 && ($action === 'new_risk' || ($edit_risk && $action === 'edit_risk'))): ?>
            <div class="gms-card">
                <h2><?php echo $edit_risk ? 'Edit Risk' : 'Add Risk'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_risk">
                    <input type="hidden" name="grant_id" value="<?php echo $grant_id; ?>">
                    <?php if ($edit_risk): ?>
                        <input type="hidden" name="risk_id" value="<?php echo $edit_risk['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Risk Code</label>
                            <input type="text" name="risk_code" value="<?php echo htmlspecialchars($edit_risk['risk_code'] ?? 'RISK-' . date('YmdHis')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Risk Category *</label>
                            <select name="risk_category" required>
                                <option value="Operational" <?php echo ($edit_risk && ($edit_risk['risk_category'] === 'Operational' || !$edit_risk)) ? 'selected' : ''; ?>>Operational</option>
                                <option value="Financial" <?php echo ($edit_risk && $edit_risk['risk_category'] === 'Financial') ? 'selected' : ''; ?>>Financial</option>
                                <option value="Reputational" <?php echo ($edit_risk && $edit_risk['risk_category'] === 'Reputational') ? 'selected' : ''; ?>>Reputational</option>
                                <option value="Compliance" <?php echo ($edit_risk && $edit_risk['risk_category'] === 'Compliance') ? 'selected' : ''; ?>>Compliance</option>
                                <option value="Strategic" <?php echo ($edit_risk && $edit_risk['risk_category'] === 'Strategic') ? 'selected' : ''; ?>>Strategic</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Risk Description *</label>
                        <textarea name="risk_description" rows="4" required><?php echo htmlspecialchars($edit_risk['risk_description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Likelihood</label>
                            <select name="likelihood">
                                <option value="Low" <?php echo ($edit_risk && $edit_risk['likelihood'] === 'Low') ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo ($edit_risk && ($edit_risk['likelihood'] === 'Medium' || !$edit_risk)) ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo ($edit_risk && $edit_risk['likelihood'] === 'High') ? 'selected' : ''; ?>>High</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Impact</label>
                            <select name="impact">
                                <option value="Low" <?php echo ($edit_risk && $edit_risk['impact'] === 'Low') ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo ($edit_risk && ($edit_risk['impact'] === 'Medium' || !$edit_risk)) ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo ($edit_risk && $edit_risk['impact'] === 'High') ? 'selected' : ''; ?>>High</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Risk Level</label>
                            <select name="risk_level">
                                <option value="Low" <?php echo ($edit_risk && $edit_risk['risk_level'] === 'Low') ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo ($edit_risk && ($edit_risk['risk_level'] === 'Medium' || !$edit_risk)) ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo ($edit_risk && $edit_risk['risk_level'] === 'High') ? 'selected' : ''; ?>>High</option>
                                <option value="Critical" <?php echo ($edit_risk && $edit_risk['risk_level'] === 'Critical') ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="Open" <?php echo ($edit_risk && ($edit_risk['status'] === 'Open' || !$edit_risk)) ? 'selected' : ''; ?>>Open</option>
                                <option value="Mitigated" <?php echo ($edit_risk && $edit_risk['status'] === 'Mitigated') ? 'selected' : ''; ?>>Mitigated</option>
                                <option value="Closed" <?php echo ($edit_risk && $edit_risk['status'] === 'Closed') ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Mitigation Measures</label>
                        <textarea name="mitigation_measures" rows="4"><?php echo htmlspecialchars($edit_risk['mitigation_measures'] ?? ''); ?></textarea>
                    </div>
                    
                    <div style="border:1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-top: 12px; background: #fafafa;">
                        <h3 style="margin:0 0 10px 0; font-size: 14px;">UN/INGO Donor-Standard Fields (optional)</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Responsible Person</label>
                                <input type="text" name="responsible_person" value="<?php echo htmlspecialchars($edit_risk['responsible_person'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Due Date</label>
                                <input type="date" name="due_date" value="<?php echo !empty($edit_risk['due_date']) ? date('Y-m-d', strtotime($edit_risk['due_date'])) : ''; ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Risk Owner Name</label>
                                <input type="text" name="risk_owner_name" value="<?php echo htmlspecialchars($edit_risk['risk_owner_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Risk Owner Email</label>
                                <input type="email" name="risk_owner_email" value="<?php echo htmlspecialchars($edit_risk['risk_owner_email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Residual Risk Level</label>
                                <select name="residual_risk_level">
                                    <option value="">(optional)</option>
                                    <?php $rrl = $edit_risk['residual_risk_level'] ?? ''; ?>
                                    <option value="Low" <?php echo ($rrl==='Low')?'selected':''; ?>>Low</option>
                                    <option value="Medium" <?php echo ($rrl==='Medium')?'selected':''; ?>>Medium</option>
                                    <option value="High" <?php echo ($rrl==='High')?'selected':''; ?>>High</option>
                                    <option value="Critical" <?php echo ($rrl==='Critical')?'selected':''; ?>>Critical</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Review Date</label>
                                <input type="date" name="review_date" value="<?php echo !empty($edit_risk['review_date']) ? date('Y-m-d', strtotime($edit_risk['review_date'])) : ''; ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Contingency Plan</label>
                            <textarea name="contingency_plan" rows="2"><?php echo htmlspecialchars($edit_risk['contingency_plan'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" rows="2"><?php echo htmlspecialchars($edit_risk['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

<div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Risk</button>
                        <a href="?view=risks&grant_id=<?php echo $grant_id; ?>" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif ($grant_id > 0 && ($action === 'new_compliance' || ($edit_issue && $action === 'edit_compliance'))): ?>
            <div class="gms-card">
                <h2><?php echo $edit_issue ? 'Edit Compliance Issue' : 'Add Compliance Issue'; ?></h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_compliance_issue">
                    <input type="hidden" name="grant_id" value="<?php echo $grant_id; ?>">
                    <?php if ($edit_issue): ?>
                        <input type="hidden" name="issue_id" value="<?php echo $edit_issue['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Issue Code</label>
                            <input type="text" name="issue_code" value="<?php echo htmlspecialchars($edit_issue['issue_code'] ?? 'COMP-' . date('YmdHis')); ?>">
                        </div>
                        <div class="form-group">
                            <label>Issue Type *</label>
                            <select name="issue_type" required>
                                <option value="Financial" <?php echo ($edit_issue && ($edit_issue['issue_type'] === 'Financial' || !$edit_issue)) ? 'selected' : ''; ?>>Financial</option>
                                <option value="Programmatic" <?php echo ($edit_issue && $edit_issue['issue_type'] === 'Programmatic') ? 'selected' : ''; ?>>Programmatic</option>
                                <option value="Administrative" <?php echo ($edit_issue && $edit_issue['issue_type'] === 'Administrative') ? 'selected' : ''; ?>>Administrative</option>
                                <option value="Legal" <?php echo ($edit_issue && $edit_issue['issue_type'] === 'Legal') ? 'selected' : ''; ?>>Legal</option>
                                <option value="Other" <?php echo ($edit_issue && $edit_issue['issue_type'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Description *</label>
                        <textarea name="description" rows="4" required><?php echo htmlspecialchars($edit_issue['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Severity</label>
                            <select name="severity">
                                <option value="Low" <?php echo ($edit_issue && $edit_issue['severity'] === 'Low') ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo ($edit_issue && ($edit_issue['severity'] === 'Medium' || !$edit_issue)) ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo ($edit_issue && $edit_issue['severity'] === 'High') ? 'selected' : ''; ?>>High</option>
                                <option value="Critical" <?php echo ($edit_issue && $edit_issue['severity'] === 'Critical') ? 'selected' : ''; ?>>Critical</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="Open" <?php echo ($edit_issue && ($edit_issue['status'] === 'Open' || !$edit_issue)) ? 'selected' : ''; ?>>Open</option>
                                <option value="In Progress" <?php echo ($edit_issue && $edit_issue['status'] === 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Resolved" <?php echo ($edit_issue && $edit_issue['status'] === 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                                <option value="Closed" <?php echo ($edit_issue && $edit_issue['status'] === 'Closed') ? 'selected' : ''; ?>>Closed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Identified Date</label>
                            <input type="date" name="identified_date" value="<?php echo $edit_issue && $edit_issue['identified_date'] ? date('Y-m-d', strtotime($edit_issue['identified_date'])) : date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Target Resolution Date</label>
                            <input type="date" name="target_resolution_date" value="<?php echo $edit_issue && $edit_issue['target_resolution_date'] ? date('Y-m-d', strtotime($edit_issue['target_resolution_date'])) : ''; ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Corrective Action</label>
                        <textarea name="corrective_action" rows="4"><?php echo htmlspecialchars($edit_issue['corrective_action'] ?? ''); ?></textarea>
                    </div>
                    
                    <div style="border:1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-top: 12px; background: #fafafa;">
                        <h3 style="margin:0 0 10px 0; font-size: 14px;">UN/INGO Donor-Standard Fields (optional)</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Compliance Area</label>
                                <input type="text" name="compliance_area" value="<?php echo htmlspecialchars($edit_issue['compliance_area'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Requirement Reference</label>
                                <input type="text" name="requirement_reference" value="<?php echo htmlspecialchars($edit_issue['requirement_reference'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Evidence Required</label>
                            <textarea name="evidence_required" rows="2"><?php echo htmlspecialchars($edit_issue['evidence_required'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Responsible Name</label>
                                <input type="text" name="responsible_person_name" value="<?php echo htmlspecialchars($edit_issue['responsible_person_name'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Responsible Email</label>
                                <input type="email" name="responsible_person_email" value="<?php echo htmlspecialchars($edit_issue['responsible_person_email'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Responsible Person (compat)</label>
                                <input type="text" name="responsible_person" value="<?php echo htmlspecialchars($edit_issue['responsible_person'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Follow-up Date</label>
                                <input type="date" name="follow_up_date" value="<?php echo !empty($edit_issue['follow_up_date']) ? date('Y-m-d', strtotime($edit_issue['follow_up_date'])) : ''; ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Root Cause</label>
                            <textarea name="root_cause" rows="2"><?php echo htmlspecialchars($edit_issue['root_cause'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="notes" rows="2"><?php echo htmlspecialchars($edit_issue['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

<div style="margin-top: 20px;">
                        <button type="submit" class="btn btn-primary">Save Compliance Issue</button>
                        <a href="?view=risks&grant_id=<?php echo $grant_id; ?>" class="btn" style="background: #6c757d; color: white; margin-left: 10px;">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif ($grant_id > 0): ?>
            <div class="gms-card">
                <h2>Risks & Compliance</h2>

                <div style="display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap;">
                    <a href="?view=risks&grant_id=<?php echo $grant_id; ?>&action=new_risk" class="btn btn-primary">➕ Add Risk</a>
                    <a href="?view=risks&grant_id=<?php echo $grant_id; ?>&action=new_compliance" class="btn btn-primary">➕ Add Compliance Issue</a>
                    <button onclick="downloadTemplate('risks')" class="btn" style="background: #17a2b8; color: white;">📥 Risk Template</button>
                    <button onclick="importTemplate('risks')" class="btn" style="background: #28a745; color: white;">📤 Import Risks</button>
                    <button onclick="exportTable('risksTable','risks','excel')" class="btn" style="background: #6c757d; color: white;">📊 Export Risks</button>
                    <button onclick="exportTable('risksTable','risks','pdf')" class="btn" style="background: #dc3545; color: white;">📄 Risks PDF</button>
                    <button onclick="downloadTemplate('compliance')" class="btn" style="background: #17a2b8; color: white;">📥 Compliance Template</button>
                    <button onclick="importTemplate('compliance')" class="btn" style="background: #28a745; color: white;">📤 Import Compliance</button>
                    <button onclick="exportTable('complianceTable','compliance','excel')" class="btn" style="background: #6c757d; color: white;">📊 Export Compliance</button>
                    <button onclick="exportTable('complianceTable','compliance','pdf')" class="btn" style="background: #dc3545; color: white;">📄 Compliance PDF</button>
                    <button onclick="printSection('risksComplianceSection')" class="btn" style="background: #ffc107; color: #000;">🖨️ Print</button>
                    <button onclick="openAIAssistant('Risks & Compliance')" class="btn" style="background: #9c27b0; color: white;">🤖 AI Assistant</button>
                </div>

                
                <h3 style="margin-top: 30px;">Risk Register</h3>
                <table class="gms-table" id="risksTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Risk Level</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($risks as $risk): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($risk['risk_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($risk['risk_category']); ?></td>
                                <td><?php echo htmlspecialchars(substr($risk['risk_description'] ?? '', 0, 100)) . (strlen($risk['risk_description'] ?? '') > 100 ? '...' : ''); ?></td>
                                <td><span class="status-badge status-<?php echo strtolower($risk['risk_level']); ?>"><?php echo htmlspecialchars($risk['risk_level']); ?></span></td>
                                <td><span class="status-badge"><?php echo htmlspecialchars($risk['status']); ?></span></td>
                                <td>
                                    <a href="?view=risks&grant_id=<?php echo $grant_id; ?>&risk_id=<?php echo $risk['id']; ?>&action=edit_risk" class="btn btn-primary btn-sm">✏️ Edit</a>
                                    <a href="?view=risks&grant_id=<?php echo $grant_id; ?>&risk_id=<?php echo $risk['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this risk?');">
                                        <input type="hidden" name="action" value="delete_risk">
                                        <input type="hidden" name="risk_id" value="<?php echo $risk['id']; ?>">
                                        <button type="submit" class="btn btn-sm" style="background: #dc3545; color: white; padding: 6px 12px;">🗑️ Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <h3 style="margin-top: 30px;">Compliance Issues</h3>
                <table class="gms-table" id="complianceTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($compliance_issues as $issue): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($issue['issue_code'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($issue['issue_type']); ?></td>
                                <td><?php echo htmlspecialchars(substr($issue['description'] ?? '', 0, 100)) . (strlen($issue['description'] ?? '') > 100 ? '...' : ''); ?></td>
                                <td><span class="status-badge status-<?php echo strtolower($issue['severity']); ?>"><?php echo htmlspecialchars($issue['severity']); ?></span></td>
                                <td><span class="status-badge"><?php echo htmlspecialchars($issue['status']); ?></span></td>
                                <td>
                                    <a href="?view=risks&grant_id=<?php echo $grant_id; ?>&issue_id=<?php echo $issue['id']; ?>&action=edit_compliance" class="btn btn-primary btn-sm">✏️ Edit</a>
                                    <a href="?view=risks&grant_id=<?php echo $grant_id; ?>&issue_id=<?php echo $issue['id']; ?>" class="btn btn-primary btn-sm" style="background: #17a2b8;">👁️ View</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="gms-card">
                <h2>Risks & Compliance</h2>
                <p>Select a grant to manage risks and compliance issues.</p>
                <form method="GET" action="">
                    <input type="hidden" name="view" value="risks">
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label>Select Grant *</label>
                            <select name="grant_id" required>
                                <option value="">-- Select Grant --</option>
                                <?php foreach ($grants as $g): ?>
                                    <option value="<?php echo (int)$g['id']; ?>">
                                        <?php echo htmlspecialchars(($g['grant_code'] ?? 'GRANT') . ' - ' . ($g['title'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="align-self: flex-end;">
                            <button type="submit" class="btn btn-primary">Open Risks & Compliance</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="gms-card">
            <h2><?php echo ucfirst($view); ?> Module</h2>
            <p>This module is under development. Coming soon!</p>
        </div>
    <?php endif; ?>
</div>
<!-- Enhanced JavaScript for GMS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
// Initialize Charts
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($view === 'dashboard'): ?>
    // Grant Status Chart
    const grantStatusCtx = document.getElementById('grantStatusChart');
    if (grantStatusCtx) {
        new Chart(grantStatusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Pipeline', 'Closed', 'Suspended'],
                datasets: [{
                    data: [<?php echo $active_grants; ?>, <?php echo $pipeline_grants; ?>, <?php echo $closed_grants; ?>, <?php echo $suspended_grants; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#6c757d', '#dc3545']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
    
    // Report Status Chart
    const reportStatusCtx = document.getElementById('reportStatusChart');
    if (reportStatusCtx) {
        new Chart(reportStatusCtx, {
            type: 'bar',
            data: {
                labels: ['Pending', 'Submitted', 'Overdue'],
                datasets: [{
                    label: 'Reports',
                    data: [<?php echo $pending_reports; ?>, <?php echo $submitted_reports; ?>, <?php echo $overdue_reports; ?>],
                    backgroundColor: ['#ffc107', '#28a745', '#dc3545']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
    
    // Budget Chart
    const budgetCtx = document.getElementById('budgetChart');
    if (budgetCtx) {
        new Chart(budgetCtx, {
            type: 'pie',
            data: {
                labels: ['Budgeted', 'Spent', 'Remaining'],
                datasets: [{
                    data: [<?php echo $total_budgeted; ?>, <?php echo $total_spent; ?>, <?php echo max(0, $total_budgeted - $total_spent); ?>],
                    backgroundColor: ['#007bff', '#28a745', '#ffc107']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
    
    // Timeliness Chart
    const timelinessCtx = document.getElementById('timelinessChart');
    if (timelinessCtx) {
        new Chart(timelinessCtx, {
            type: 'bar',
            data: {
                labels: ['On Time', 'Late', 'Early'],
                datasets: [{
                    label: 'Reports',
                    data: [<?php echo $on_time_reports; ?>, <?php echo $late_reports; ?>, <?php echo $early_reports; ?>],
                    backgroundColor: ['#28a745', '#dc3545', '#17a2b8']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    }
    <?php endif; ?>
    
    // Real-time updates every 30 seconds
    setInterval(function() {
        updateDashboardStats();
    }, 30000);
    
    // Show confirmation messages
    <?php if (!empty($message)): ?>
    showNotification(<?php echo json_encode($message); ?>, 'success');
    <?php endif; ?>
    
    <?php if (!empty($errorMsg)): ?>
    showNotification(<?php echo json_encode($errorMsg); ?>, 'error');
    <?php endif; ?>
});

// Export Dashboard
function exportDashboard(format) {
    if (format === 'excel') {
        const table = document.getElementById('grantsTable');
        if (!table) return;
        const wb = XLSX.utils.table_to_book(table, {sheet: 'Grants Dashboard'});
        XLSX.writeFile(wb, 'gms_dashboard_' + new Date().toISOString().split('T')[0] + '.xlsx');
        showNotification('Dashboard exported to Excel successfully!', 'success');
    } else if (format === 'pdf') {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'pt', 'a4');
        doc.setFontSize(16);
        doc.text('Grant Management System - Dashboard', 40, 40);
        doc.setFontSize(12);
        doc.text('Export Date: ' + new Date().toLocaleString(), 40, 60);
        doc.save('gms_dashboard_' + new Date().toISOString().split('T')[0] + '.pdf');
        showNotification('Dashboard exported to PDF successfully!', 'success');
    }
}

// Export functions for all modules
function exportTable(tableId, filename, format) {
    const table = document.getElementById(tableId);
    if (!table) {
        showNotification('Table not found', 'error');
        return;
    }

    // Clone table and remove any no-export columns
    const clonedTable = table.cloneNode(true);
    const noExportElements = clonedTable.querySelectorAll('.no-export, th.no-export, td.no-export');
    // Remove by column index (from header rows)
    const colIndexes = new Set();
    noExportElements.forEach(el => {
        if (el.tagName === 'TH' || el.tagName === 'TD') {
            const colIndex = Array.from(el.parentNode.children).indexOf(el);
            if (colIndex >= 0) colIndexes.add(colIndex);
        }
    });
    if (colIndexes.size > 0) {
        const sorted = Array.from(colIndexes).sort((a,b)=>b-a);
        clonedTable.querySelectorAll('tr').forEach(row => {
            sorted.forEach(idx => {
                if (row.children && row.children[idx]) row.removeChild(row.children[idx]);
            });
        });
    }

    const safeName = (filename || 'export').replace(/[^a-z0-9_\-]+/gi, '_');

    if (format === 'excel') {
        // Offline-safe Excel export using HTML .xls (opens in Excel)
        const html = `
            <html>
            <head>
              <meta charset="utf-8" />
              <style>
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #333; padding: 6px; text-align: left; }
                th { background: #f2f2f2; }
              </style>
            </head>
            <body>
              ${clonedTable.outerHTML}
            </body>
            </html>
        `;
        const blob = new Blob([html], {type: 'application/vnd.ms-excel;charset=utf-8;'});
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = safeName + '.xls';
        link.click();
        showNotification('Exported to Excel successfully!', 'success');
    } else if (format === 'csv') {
        const csv = tableToCSV(clonedTable);
        downloadCSV(csv, safeName + '.csv');
        showNotification('Exported to CSV successfully!', 'success');
    } else if (format === 'pdf') {
        // Offline-safe PDF export via print dialog (user can Save as PDF)
        const w = window.open('', '_blank');
        if (!w) {
            showNotification('Popup blocked. Please allow popups for PDF export.', 'error');
            return;
        }
        w.document.open();
        w.document.write(`
            <html>
            <head>
              <meta charset="utf-8" />
              <title>${safeName}</title>
              <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #333; padding: 6px; text-align: left; }
                th { background: #f2f2f2; }
                @media print { button { display:none; } }
              </style>
            </head>
            <body>
              <h2 style="margin-top:0;">${safeName}</h2>
              <button onclick="window.print()">Print / Save as PDF</button>
              ${clonedTable.outerHTML}
            </body>
            </html>
        `);
        w.document.close();
            try { w.focus(); setTimeout(() => { try { w.print(); } catch(e) {} }, 300); } catch(e) {}
            showNotification('PDF ready. Use the print dialog to Save as PDF.', 'success');
    } else {
        showNotification('Unknown export format', 'error');
    }
}


function tableToCSV(table) {
    let csv = [];
    const rows = table.querySelectorAll('tr');
    rows.forEach(row => {
        const cols = row.querySelectorAll('th:not(.no-export), td:not(.no-export)');
        const rowData = [];
        cols.forEach(col => {
            rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
        });
        csv.push(rowData.join(','));
    });
    return csv.join('\n');
}

function downloadCSV(csv, filename) {
    const blob = new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.click();
}

// Import Template
function importTemplate(module) {
    const input = document.createElement('input');
    input.type = 'file';
    // CSV/TSV import is guaranteed to work offline. Excel files require extra libraries.
    input.accept = '.csv,.tsv,.txt,.xlsx,.xls';
    input.onchange = function(e) {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('action', 'import_' + (module || 'grants'));
        formData.append('file', file);

        fetch('?action=import', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.success) {
                showNotification('Successfully imported ' + (data.count || 0) + ' records!', 'success');
                const params = new URLSearchParams(window.location.search);
                const currentView = params.get('view') || 'grants';
                const grantId = params.get('grant_id');
                setTimeout(() => {
                    let url = '?view=' + encodeURIComponent(currentView);
                    if (grantId) url += '&grant_id=' + encodeURIComponent(grantId);
                    window.location.href = url;
                }, 800);
            } else {
                showNotification('Import failed: ' + (data && data.message ? data.message : 'Unknown error'), 'error');
            }
        })
        .catch(error => {
            showNotification('Import error: ' + (error && error.message ? error.message : error), 'error');
        });
    };
    input.click();
}


// Download Template
function downloadTemplate(module) {
    // Reliable server-side template download (opens in Excel as CSV)
    const url = '?action=export_template&module=' + encodeURIComponent(module || 'grants');
    window.location.href = url;
}


// Print function
function printSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (!section) {
        showNotification('Section not found', 'error');
        return;
    }
    
    // Clone the section and remove no-print/no-export elements
    const clonedSection = section.cloneNode(true);
    const noPrintElements = clonedSection.querySelectorAll('.no-print, .no-export, th.no-print, td.no-print, th.no-export, td.no-export');
    noPrintElements.forEach(el => {
        if (el.tagName === 'TH' || el.tagName === 'TD') {
            const colIndex = Array.from(el.parentNode.children).indexOf(el);
            // Remove from all rows
            clonedSection.querySelectorAll('tr').forEach(row => {
                const cell = row.children[colIndex];
                if (cell && (cell.classList.contains('no-print') || cell.classList.contains('no-export'))) {
                    cell.remove();
                }
            });
        } else {
            el.remove();
        }
    });
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write('<!DOCTYPE html><html><head><title>GMS Print</title>');
    printWindow.document.write('<style>body{font-family:Arial;padding:20px;} table{width:100%;border-collapse:collapse;} th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background-color:#f0f0f0;font-weight:bold;} .no-print,.no-export{display:none !important;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h2>Grant Management System</h2>');
    printWindow.document.write('<p>Print Date: ' + new Date().toLocaleString() + '</p>');
    printWindow.document.write(clonedSection.innerHTML);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
    printWindow.print();
        printWindow.close();
    }, 250);
}

// Notification System
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = 'gms-notification gms-notification-' + type;
    notification.style.cssText = 'position:fixed;top:20px;right:20px;padding:15px 20px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:10000;max-width:400px;animation:slideIn 0.3s ease;';
    notification.style.background = type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1';
    notification.style.color = type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460';
    notification.innerHTML = '<strong>' + (type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️') + '</strong> ' + message;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Real-time Dashboard Updates
function updateDashboardStats() {
    fetch('?ajax=1&action=get_dashboard_stats')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update stats cards
                updateStatCard('total-grants', data.stats.total_grants);
                updateStatCard('active-grants', data.stats.active_grants);
                updateStatCard('overdue-reports', data.stats.overdue_reports);
            }
        })
        .catch(error => console.error('Update error:', error));
}

function updateStatCard(id, value) {
    const card = document.getElementById(id);
    if (card) {
        const valueEl = card.querySelector('.stat-value');
        if (valueEl) {
            valueEl.textContent = number_format(value);
        }
    }
}

// AI Assistant
function openAIAssistant(context) {
    const modal = document.createElement('div');
    modal.className = 'gms-ai-modal';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
    modal.innerHTML = `
        <div style="background:white;padding:30px;border-radius:10px;max-width:600px;width:90%;max-height:80vh;overflow-y:auto;">
            <h2>🤖 AI Assistant - ${context}</h2>
            <div id="ai-chat" style="min-height:300px;max-height:400px;overflow-y:auto;border:1px solid #ddd;padding:15px;margin:15px 0;border-radius:5px;"></div>
            <input type="text" id="ai-input" placeholder="Ask me anything about ${context}..." style="width:100%;padding:10px;border:1px solid #ddd;border-radius:5px;">
            <div style="margin-top:15px;text-align:right;">
                <button onclick="sendAIQuery('${context}')" class="btn btn-primary">Send</button>
                <button onclick="this.closest('.gms-ai-modal').remove()" class="btn" style="background:#6c757d;color:white;">Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    document.getElementById('ai-input').focus();
}

function sendAIQuery(context) {
    const input = document.getElementById('ai-input');
    const query = input.value.trim();
    if (!query) return;
    
    const chat = document.getElementById('ai-chat');
    chat.innerHTML += '<div style="margin-bottom:10px;"><strong>You:</strong> ' + query + '</div>';
    input.value = '';
    
    fetch('?ajax=1&action=ai_query', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({context: context, query: query})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            chat.innerHTML += '<div style="margin-bottom:10px;color:#667eea;"><strong>AI:</strong> ' + data.response + '</div>';
        } else {
            chat.innerHTML += '<div style="margin-bottom:10px;color:#dc3545;"><strong>AI:</strong> Error: ' + data.message + '</div>';
        }
        chat.scrollTop = chat.scrollHeight;
    })
    .catch(error => {
        chat.innerHTML += '<div style="margin-bottom:10px;color:#dc3545;"><strong>AI:</strong> Connection error</div>';
    });
}

// Add keyboard shortcut for AI (Ctrl+K)
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 'k') {
        e.preventDefault();
        openAIAssistant('General');
    }
});

// Toggle responsible person fields based on Go/No-Go decision
function toggleResponsiblePersonFields() {
    const decision = document.getElementById('go_no_go_decision');
    const section = document.getElementById('responsible_person_section');
    if (decision && section) {
        if (decision.value === 'Go') {
            section.style.display = 'block';
            // Make fields required
            const requiredFields = section.querySelectorAll('input[type="text"], input[type="email"]');
            requiredFields.forEach(field => {
                field.setAttribute('required', 'required');
            });
        } else {
            section.style.display = 'none';
            // Remove required attribute
            const requiredFields = section.querySelectorAll('input[type="text"], input[type="email"]');
            requiredFields.forEach(field => {
                field.removeAttribute('required');
            });
        }
    }
}

// Auto-select currency based on donor selection
function updateCurrencyFromDonor() {
    const donorSelect = document.getElementById('donor_select');
    const currencySelect = document.getElementById('currency_select');
    
    if (!donorSelect || !currencySelect || donorSelect.value === '' || donorSelect.value === 'OTHER') {
        return;
    }
    
    const selectedOption = donorSelect.options[donorSelect.selectedIndex];
    const donorCurrency = selectedOption ? selectedOption.getAttribute('data-currency') : null;
    
    if (donorCurrency) {
        // Find and select the matching currency
        for (let i = 0; i < currencySelect.options.length; i++) {
            if (currencySelect.options[i].value === donorCurrency) {
                currencySelect.value = donorCurrency;
                currencySelect.dispatchEvent(new Event('change'));
                break;
            }
        }
    }
}

// Handle currency selection (show/hide Other input)
function handleCurrencySelection() {
    const currencySelect = document.getElementById('currency_select');
    const otherSection = document.getElementById('currency_other_section');
    if (currencySelect && otherSection) {
        if (currencySelect.value === 'OTHER') {
            otherSection.style.display = 'block';
            document.getElementById('currency_other_input').setAttribute('required', 'required');
        } else {
            otherSection.style.display = 'none';
            document.getElementById('currency_other_input').removeAttribute('required');
        }
    }
}

// Calculate days remaining from announcement to deadline
function calculateDaysRemaining() {
    const announcementDate = document.getElementById('announcement_date') || document.querySelector('input[name="announcement_date"]');
    const deadlineDate = document.getElementById('submission_deadline');
    const daysDisplay = document.getElementById('days_count');
    const totalDaysDisplay = document.getElementById('total_days_count');
    const daysDisplayContainer = document.getElementById('days_remaining_display');
    
    if (!announcementDate || !deadlineDate) {
        return;
    }
    
    const announcementValue = announcementDate.value;
    const deadlineValue = deadlineDate.value;
    
    if (!announcementValue || !deadlineValue) {
        if (daysDisplay) daysDisplay.innerHTML = '--';
        if (totalDaysDisplay) totalDaysDisplay.textContent = '--';
        return;
    }
    
    try {
        // Parse dates
        const announcement = new Date(announcementValue + 'T00:00:00');
        const deadline = new Date(deadlineValue);
        const now = new Date();
        
        if (isNaN(announcement.getTime()) || isNaN(deadline.getTime())) {
            if (daysDisplay) daysDisplay.innerHTML = '--';
            if (totalDaysDisplay) totalDaysDisplay.textContent = '--';
            return;
        }
        
        // Calculate total days from announcement to deadline
        const diffTime = deadline - announcement;
        const totalDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        // Calculate days remaining from now to deadline
        const remainingTime = deadline - now;
        const remainingDays = Math.ceil(remainingTime / (1000 * 60 * 60 * 24));
        
        // Update total days display
        if (totalDaysDisplay) {
            totalDaysDisplay.textContent = totalDays + ' days';
            totalDaysDisplay.style.color = '#667eea';
        }
        
        // Show remaining days
        let displayText = '';
        let alertMessage = '';
        
        if (remainingDays < 0) {
            // Deadline has passed
            displayText = '<span style="color: red; font-weight: bold;">0 (CLOSED)</span>';
            if (daysDisplayContainer) {
                daysDisplayContainer.style.background = '#ffebee';
                daysDisplayContainer.style.border = '2px solid #dc3545';
            }
            alertMessage = '⚠️ DEADLINE HAS PASSED! This opportunity is now CLOSED.';
        } else if (remainingDays === 0) {
            // Deadline is today
            displayText = '<span style="color: red; font-weight: bold;">0 (ENDS TODAY!)</span>';
            if (daysDisplayContainer) {
                daysDisplayContainer.style.background = '#ffebee';
                daysDisplayContainer.style.border = '2px solid #dc3545';
            }
            alertMessage = '🚨 URGENT: Deadline is TODAY! Submit immediately!';
        } else if (remainingDays <= 7) {
            // Less than 7 days remaining
            displayText = '<span style="color: orange; font-weight: bold;">' + remainingDays + ' days (URGENT!)</span>';
            if (daysDisplayContainer) {
                daysDisplayContainer.style.background = '#fff3e0';
                daysDisplayContainer.style.border = '2px solid #ff9800';
            }
            alertMessage = '⏰ WARNING: Only ' + remainingDays + ' days remaining until deadline!';
        } else if (remainingDays <= 30) {
            // Less than 30 days remaining
            displayText = '<span style="color: #ff9800;">' + remainingDays + ' days</span>';
            if (daysDisplayContainer) {
                daysDisplayContainer.style.background = '#fff9e6';
                daysDisplayContainer.style.border = '1px solid #ffc107';
            }
            alertMessage = '📅 Reminder: ' + remainingDays + ' days remaining until deadline.';
        } else {
            // More than 30 days
            displayText = remainingDays + ' days';
            if (daysDisplayContainer) {
                daysDisplayContainer.style.background = '#f0f0f0';
                daysDisplayContainer.style.border = '1px solid #ddd';
            }
        }
        
        if (daysDisplay) {
            daysDisplay.innerHTML = displayText;
        }
        
        // Show alert message if needed
        if (alertMessage && (remainingDays <= 30 || remainingDays < 0)) {
            // Create or update alert notification
            const alertDiv = document.getElementById('deadline_alert');
            if (alertDiv) {
                alertDiv.style.display = 'block';
                alertDiv.style.background = remainingDays < 0 ? '#ffebee' : (remainingDays <= 7 ? '#fff3e0' : '#fff9e6');
                alertDiv.style.border = remainingDays < 0 ? '2px solid #dc3545' : (remainingDays <= 7 ? '2px solid #ff9800' : '1px solid #ffc107');
                alertDiv.style.color = remainingDays < 0 ? '#c62828' : (remainingDays <= 7 ? '#e65100' : '#f57c00');
                alertDiv.textContent = alertMessage;
            }
            showNotification(alertMessage, remainingDays < 0 ? 'error' : (remainingDays <= 7 ? 'error' : 'warning'));
        } else {
            const alertDiv = document.getElementById('deadline_alert');
            if (alertDiv) {
                alertDiv.style.display = 'none';
            }
        }
        
        // Update opportunity status automatically if deadline passed
        const statusSelect = document.getElementById('opportunity_status');
        if (remainingDays < 0 && statusSelect && statusSelect.value === 'Open') {
            statusSelect.value = 'Closed';
            showNotification('Status automatically changed to CLOSED because deadline has passed.', 'warning');
        }
    } catch (error) {
        console.error('Error calculating days:', error);
        if (daysDisplay) daysDisplay.innerHTML = '--';
        if (totalDaysDisplay) totalDaysDisplay.textContent = '--';
    }
}
            // No dates entered yet
            if (daysDisplay) daysDisplay.innerHTML = '--';
            if (totalDaysDisplay) totalDaysDisplay.textContent = '--';
            if (daysDisplayContainer) {
                daysDisplayContainer.style.background = '#f0f0f0';
                daysDisplayContainer.style.border = '1px solid #ddd';
            }
            const alertDiv = document.getElementById('deadline_alert');
            if (alertDiv) {
                alertDiv.style.display = 'none';
            }
        }
    } catch (error) {
        console.error('Error calculating days:', error);
        if (daysDisplay) daysDisplay.innerHTML = '--';
        if (totalDaysDisplay) totalDaysDisplay.textContent = '--';
    }
}

// Fetch AI summary from website
function fetchAISummary() {
    const websiteUrl = document.getElementById('opportunity_website');
    const summarySection = document.getElementById('ai_summary_section');
    const summaryContent = document.getElementById('ai_summary_content');
    const summaryInput = document.getElementById('ai_summary_input');
    
    if (!websiteUrl || !websiteUrl.value) {
        return;
    }
    
    if (summaryContent) {
        summaryContent.innerHTML = '<p style="color: #666; font-style: italic;">🤖 Generating AI summary from website... Please wait...</p>';
    }
    if (summarySection) {
        summarySection.style.display = 'block';
    }
    
    // Call AI assistant to summarize website
    fetch('?action=ai_summarize_website&url=' + encodeURIComponent(websiteUrl.value), {
        method: 'GET'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.summary) {
            if (summaryContent) {
                summaryContent.innerHTML = '<p style="white-space: pre-wrap;">' + data.summary.replace(/\n/g, '<br>') + '</p>';
            }
            if (summaryInput) {
                summaryInput.value = data.summary;
            }
            showNotification('AI summary generated successfully!', 'success');
        } else {
            if (summaryContent) {
                summaryContent.innerHTML = '<p style="color: #dc3545;">Failed to generate summary. Please try again or enter manually.</p>';
            }
            showNotification('Failed to generate AI summary: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        if (summaryContent) {
            summaryContent.innerHTML = '<p style="color: #dc3545;">Error generating summary. Please enter manually.</p>';
        }
        showNotification('Error: ' + error.message, 'error');
    });
}

// Fill responsible person from current user profile
function fillFromCurrentUser() {
    // Get current user info from session/profile
    fetch('?action=get_current_user_info', {
        method: 'GET'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const nameField = document.getElementById('responsible_person_name');
            const emailField = document.getElementById('responsible_person_email');
            const positionField = document.getElementById('responsible_person_position');
            
            if (nameField && data.full_name) nameField.value = data.full_name;
            if (emailField && data.email) emailField.value = data.email;
            if (positionField && data.position) positionField.value = data.position;
            
            showNotification('Profile information filled successfully!', 'success');
        }
    })
    .catch(error => {
        // Fallback: try to get from page context
        console.log('Could not fetch user info, using fallback');
    });
}

// Comment system functions
function submitComment() {
    const commentText = document.getElementById('new_comment_text');
    const opportunityId = <?php echo $opportunity_id ?? 0; ?>;
    
    if (!commentText || !commentText.value.trim() || !opportunityId) {
        showNotification('Please enter a comment', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_opportunity_comment');
    formData.append('opportunity_id', opportunityId);
    formData.append('comment_text', commentText.value);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Comment added successfully!', 'success');
            location.reload();
        } else {
            showNotification('Failed to add comment: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Error: ' + error.message, 'error');
    });
}

function submitReply(parentCommentId) {
    const replyTextarea = document.getElementById('reply_textarea_' + parentCommentId);
    const opportunityId = <?php echo $opportunity_id ?? 0; ?>;
    
    if (!replyTextarea || !replyTextarea.value.trim() || !opportunityId) {
        showNotification('Please enter a reply', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_opportunity_comment');
    formData.append('opportunity_id', opportunityId);
    formData.append('parent_comment_id', parentCommentId);
    formData.append('comment_text', replyTextarea.value);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Reply added successfully!', 'success');
            location.reload();
        } else {
            showNotification('Failed to add reply: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showNotification('Error: ' + error.message, 'error');
    });
}

function showReplyForm(commentId) {
    const form = document.getElementById('reply_form_' + commentId);
    if (form) {
        form.style.display = 'block';
    }
}

function hideReplyForm(commentId) {
    const form = document.getElementById('reply_form_' + commentId);
    if (form) {
        form.style.display = 'none';
        const textarea = document.getElementById('reply_textarea_' + commentId);
        if (textarea) textarea.value = '';
    }
}

function editComment(commentId) {
    const commentText = document.getElementById('comment_text_' + commentId);
    if (commentText) {
        const currentText = commentText.innerText;
        commentText.innerHTML = '<textarea id="edit_comment_' + commentId + '" rows="3" style="width: 100%; padding: 8px;">' + currentText + '</textarea>' +
            '<div style="margin-top: 5px;"><button onclick="saveComment(' + commentId + ')" class="btn btn-sm" style="background: #28a745; color: white;">Save</button> ' +
            '<button onclick="cancelEditComment(' + commentId + ')" class="btn btn-sm" style="background: #6c757d; color: white;">Cancel</button></div>';
    }
}

function saveComment(commentId) {
    const textarea = document.getElementById('edit_comment_' + commentId);
    if (!textarea) return;
    
    const formData = new FormData();
    formData.append('action', 'update_opportunity_comment');
    formData.append('comment_id', commentId);
    formData.append('comment_text', textarea.value);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Comment updated successfully!', 'success');
            location.reload();
        } else {
            showNotification('Failed to update comment: ' + data.message, 'error');
        }
    });
}

function cancelEditComment(commentId) {
    location.reload();
}

function editReply(replyId) {
    editComment(replyId); // Reuse same function
}

function deleteComment(commentId) {
    if (!confirm('Are you sure you want to delete this comment?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'delete_opportunity_comment');
    formData.append('comment_id', commentId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Comment deleted successfully!', 'success');
            document.getElementById('comment_' + commentId)?.remove();
            document.getElementById('reply_' + commentId)?.remove();
        } else {
            showNotification('Failed to delete comment: ' + data.message, 'error');
        }
    });
}

// Handle donor selection for grant form
function handleGrantDonorSelection() {
    const donorSelect = document.getElementById('grant_donor_select');
    const otherSection = document.getElementById('grant_donor_other_section');
    const otherInput = document.getElementById('grant_donor_other_input');
    
    if (donorSelect && otherSection && otherInput) {
        if (donorSelect.value === 'OTHER') {
            otherSection.style.display = 'block';
            otherInput.setAttribute('required', 'required');
        } else {
            otherSection.style.display = 'none';
            otherInput.removeAttribute('required');
            otherInput.value = '';
        }
    }
}

// Handle donor selection for opportunity form
function handleDonorSelection() {
    const donorSelect = document.getElementById('donor_select');
    const otherSection = document.getElementById('donor_other_section');
    const otherInput = document.getElementById('donor_other_input');
    
    if (donorSelect && otherSection && otherInput) {
        if (donorSelect.value === 'OTHER') {
            otherSection.style.display = 'block';
            otherInput.setAttribute('required', 'required');
        } else {
            otherSection.style.display = 'none';
            otherInput.removeAttribute('required');
            otherInput.value = '';
        }
    }
}

// Handle donor selection for donor registration form
function handleDonorRegistrationSelection() {
    const donorSelect = document.getElementById('donor_registration_select');
    const otherSection = document.getElementById('donor_registration_other_section');
    const otherInput = document.getElementById('donor_registration_other_input');
    const shortNameInput = document.getElementById('short_name_input');
    const donorTypeSelect = document.getElementById('donor_type_select');
    
    if (donorSelect && otherSection && otherInput) {
        if (donorSelect.value === 'OTHER') {
            otherSection.style.display = 'block';
            otherInput.setAttribute('required', 'required');
            if (shortNameInput) shortNameInput.value = '';
        } else {
            otherSection.style.display = 'none';
            otherInput.removeAttribute('required');
            otherInput.value = '';
            
            // Auto-fill donor info if donor is selected
            if (donorSelect.value && donorSelect.value !== '') {
                const selectedOption = donorSelect.options[donorSelect.selectedIndex];
                
                // Auto-fill short name and donor type from data attributes
                if (selectedOption.dataset.shortName && shortNameInput) {
                    shortNameInput.value = selectedOption.dataset.shortName;
                }
                if (selectedOption.dataset.donorType && donorTypeSelect) {
                    donorTypeSelect.value = selectedOption.dataset.donorType;
                }
            }
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleResponsiblePersonFields();
    handleDonorSelection();
    handleGrantDonorSelection();
    handleDonorRegistrationSelection();
    handleCurrencySelection();
    calculateDaysRemaining();
    
    // Update days remaining when dates change
    const announcementDate = document.getElementById('announcement_date') || document.querySelector('input[name="announcement_date"]');
    const deadlineDate = document.getElementById('submission_deadline');
    if (announcementDate) {
        announcementDate.addEventListener('change', calculateDaysRemaining);
        announcementDate.addEventListener('input', calculateDaysRemaining);
    }
    if (deadlineDate) {
        deadlineDate.addEventListener('change', calculateDaysRemaining);
        deadlineDate.addEventListener('input', calculateDaysRemaining);
    }
    
    // Calculate on page load
    setTimeout(calculateDaysRemaining, 500);
    
    // Auto-update every minute to show real-time countdown
    setInterval(calculateDaysRemaining, 60000);
    
    // Show proposal status section when Go is selected
    const goNoGo = document.getElementById('go_no_go_decision');
    const proposalStatusSection = document.getElementById('proposal_status_section');
    if (goNoGo && proposalStatusSection) {
        goNoGo.addEventListener('change', function() {
            if (this.value === 'Go') {
                proposalStatusSection.style.display = 'block';
                document.getElementById('proposal_status').setAttribute('required', 'required');
            } else {
                proposalStatusSection.style.display = 'none';
                document.getElementById('proposal_status').removeAttribute('required');
            }
        });
    }
    
    // Show proposal files section when status is submitted or later
    const proposalStatus = document.getElementById('proposal_status');
    const proposalFilesSection = document.getElementById('proposal_files_section');
    if (proposalStatus && proposalFilesSection) {
        proposalStatus.addEventListener('change', function() {
            const submittedStatuses = ['Submitted', 'Under Review', 'Waiting for Response', 'Declined', 'Failed', 'Succeeded'];
            if (submittedStatuses.includes(this.value)) {
                proposalFilesSection.style.display = 'block';
            } else {
                proposalFilesSection.style.display = 'none';
            }
        });
    }
});

// Auto-save form drafts
let formDraftTimer;
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('input', function() {
        clearTimeout(formDraftTimer);
        formDraftTimer = setTimeout(() => {
            const formData = new FormData(form);
            const formId = form.id || 'form_' + Date.now();
            localStorage.setItem('gms_draft_' + formId, JSON.stringify(Object.fromEntries(formData)));
        }, 2000);
    });
});

// Load form drafts
document.querySelectorAll('form').forEach(form => {
    const formId = form.id || 'form_' + Date.now();
    const draft = localStorage.getItem('gms_draft_' + formId);
    if (draft) {
        const data = JSON.parse(draft);
        Object.keys(data).forEach(key => {
            const input = form.querySelector('[name="' + key + '"]');
            if (input && input.type !== 'file') {
                input.value = data[key];
            }
        });
    }
});
</script>

<style>
@keyframes slideIn {
    from { transform: translateX(400px); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(400px); opacity: 0; }
}

@media print {
    .gms-nav, .btn, .no-print { display: none !important; }
    .gms-card { page-break-inside: avoid; }
}
</style>

<?php require_once __DIR__ . '/../footer.php'; ?>


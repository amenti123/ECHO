<?php
require_once __DIR__ . '/_preflight.php';
require_login();
$pdo = getPDO();
require_once __DIR__ . '/../header.php';


// Start output buffering to prevent headers already sent errors
ob_start();

// -------------------------------------------------------------------
// 0) Ensure tables and columns exist with proper schema
// -------------------------------------------------------------------
$pdo->exec("
    CREATE TABLE IF NOT EXISTS activity_budget (
        id INT AUTO_INCREMENT PRIMARY KEY,
        activity_id INT NOT NULL,
        budget_code VARCHAR(100),
        description TEXT NOT NULL,
        unit_description VARCHAR(100),
        unit_quantity DECIMAL(18,4) DEFAULT 0,
        unit_cost DECIMAL(18,4) DEFAULT 0,
        duration INT DEFAULT 1,
        percent_cbpf DECIMAL(5,2) DEFAULT 0,
        total_cost DECIMAL(18,4) DEFAULT 0,
        currency VARCHAR(10) DEFAULT 'USD',
        fiscal_year VARCHAR(10),
        donor_agency VARCHAR(255),
        budget_status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft',
        budget_type ENUM('program','admin') DEFAULT 'program',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS budget_installments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        budget_id INT NOT NULL,
        installment_no INT NOT NULL,
        currency VARCHAR(10) DEFAULT 'USD',
        rate DECIMAL(18,6) DEFAULT 1,
        percent DECIMAL(8,4) DEFAULT 0,
        amount_etb DECIMAL(18,4) DEFAULT 0,
        donor_name VARCHAR(255) DEFAULT '',
        payment_date DATE NULL,
        status ENUM('planned', 'disbursed', 'pending') DEFAULT 'planned',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (budget_id) REFERENCES activity_budget(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS budget_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        psc_percent DECIMAL(5,2) DEFAULT 0,
        psc_description VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_budget_settings_project (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// -------------------------------------------------------------------
// 1) Add missing columns to activity_budget if they don't exist
// -------------------------------------------------------------------
$columns_to_check = ['currency', 'fiscal_year', 'donor_agency', 'budget_status', 'budget_type'];
foreach ($columns_to_check as $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'activity_budget'
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$column]);
    $column_exists = $stmt->fetchColumn();

    if (!$column_exists) {
        switch ($column) {
            case 'currency':
                $pdo->exec("ALTER TABLE activity_budget ADD COLUMN currency VARCHAR(10) DEFAULT 'USD'");
                break;
            case 'fiscal_year':
                $pdo->exec("ALTER TABLE activity_budget ADD COLUMN fiscal_year VARCHAR(10)");
                break;
            case 'donor_agency':
                $pdo->exec("ALTER TABLE activity_budget ADD COLUMN donor_agency VARCHAR(255)");
                break;
            case 'budget_status':
                $pdo->exec("ALTER TABLE activity_budget ADD COLUMN budget_status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'draft'");
                break;
            case 'budget_type':
                $pdo->exec("ALTER TABLE activity_budget ADD COLUMN budget_type ENUM('program','admin') DEFAULT 'program'");
                break;
        }
    }
}

// -------------------------------------------------------------------
// 1.5) Determine selected project and load project list
// -------------------------------------------------------------------
$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$projects       = [];
$currentProject = null;

try {
    $projStmt = $pdo->query("SELECT * FROM projects ORDER BY id DESC");
    $projects  = $projStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedProjectId > 0) {
        foreach ($projects as $p) {
            if ((int)$p['id'] === $selectedProjectId) {
                $currentProject = $p;
                break;
            }
        }
    }
} catch (Exception $e) {
    $projects = [];
}

// simple map for project meta (for summary)
$projectMeta = [];
foreach ($projects as $p) {
    $pid = (int)$p['id'];
    $title = $p['project_title'] ?? $p['title'] ?? $p['name'] ?? ('Project ' . $pid);
    $code  = $p['project_code'] ?? $p['code'] ?? '';
    $region = $p['region'] ?? ($p['project_region'] ?? '');
    $zone   = $p['zone'] ?? ($p['project_zone'] ?? '');
    $woreda = $p['woreda'] ?? ($p['project_woreda'] ?? '');
    $sector = $p['sector'] ?? ($p['project_sector'] ?? '');
    $projectMeta[$pid] = [
        'title'  => $title,
        'code'   => $code,
        'region' => $region,
        'zone'   => $zone,
        'woreda' => $woreda,
        'sector' => $sector
    ];
}

// -------------------------------------------------------------------
// 2) Load activities (filtered by selected project if any)
// -------------------------------------------------------------------
$activitiesSql    = "SELECT a.* FROM activities a";
$paramsActivities = [];

if ($selectedProjectId > 0) {
    $activitiesSql .= " WHERE a.project_id = :pid";
    $paramsActivities[':pid'] = $selectedProjectId;
}
$activitiesSql .= " ORDER BY a.code";

$activitiesStmt = $pdo->prepare($activitiesSql);
$activitiesStmt->execute($paramsActivities);
$activities = $activitiesStmt->fetchAll();

$message = '';
$error   = '';

$editBudget      = null;
$editInstallment = null;

// -------------------------------------------------------------------
// Currency configuration - Load from database using helper functions
// -------------------------------------------------------------------
$currencies = [];
$currenciesData = get_all_currencies();
foreach ($currenciesData as $curr) {
    $currencies[$curr['code']] = [
        'name' => $curr['name'],
        'symbol' => $curr['symbol'] ?? $curr['code'],
        'default_rate' => (float)($curr['default_rate'] ?? 1.0)
    ];
}
// Fallback if database is empty
if (empty($currencies)) {
$currencies = [
    // Major Currencies
    'USD' => ['name' => 'US Dollar', 'symbol' => '$',  'default_rate' => 55.5],
    'EUR' => ['name' => 'Euro',      'symbol' => '€',  'default_rate' => 60.2],
    'GBP' => ['name' => 'British Pound','symbol' => '£','default_rate' => 70.1],
    'CHF' => ['name' => 'Swiss Franc','symbol' => 'CHF','default_rate' => 61.5],
    'JPY' => ['name' => 'Japanese Yen','symbol' => '¥','default_rate' => 0.37],
    'CNY' => ['name' => 'Chinese Yuan','symbol' => '¥','default_rate' => 7.8],
    'CAD' => ['name' => 'Canadian Dollar','symbol' => 'C$','default_rate' => 41.2],
    'AUD' => ['name' => 'Australian Dollar','symbol' => 'A$','default_rate' => 36.8],
    'NZD' => ['name' => 'New Zealand Dollar','symbol' => 'NZ$','default_rate' => 33.5],
    'SEK' => ['name' => 'Swedish Krona','symbol' => 'kr','default_rate' => 5.2],
    'NOK' => ['name' => 'Norwegian Krone','symbol' => 'kr','default_rate' => 5.1],
    'DKK' => ['name' => 'Danish Krone','symbol' => 'kr','default_rate' => 8.1],
    // Ethiopia & Horn of Africa
    'ETB' => ['name' => 'Ethiopian Birr','symbol' => 'Br','default_rate' => 1],
    'SOS' => ['name' => 'Somali Shilling','symbol' => 'S','default_rate' => 0.08],
    'SSP' => ['name' => 'South Sudanese Pound','symbol' => '£','default_rate' => 0.04],
    'SDG' => ['name' => 'Sudanese Pound','symbol' => '£','default_rate' => 0.09],
    'KES' => ['name' => 'Kenyan Shilling','symbol' => 'KSh','default_rate' => 0.42],
    'UGX' => ['name' => 'Ugandan Shilling','symbol' => 'USh','default_rate' => 0.015],
    'TZS' => ['name' => 'Tanzanian Shilling','symbol' => 'TSh','default_rate' => 0.024],
    'RWF' => ['name' => 'Rwandan Franc','symbol' => 'RF','default_rate' => 0.044],
    'BIF' => ['name' => 'Burundian Franc','symbol' => 'FBu','default_rate' => 0.019],
    'DJF' => ['name' => 'Djiboutian Franc','symbol' => 'Fdj','default_rate' => 0.31],
    'ERN' => ['name' => 'Eritrean Nakfa','symbol' => 'Nfk','default_rate' => 3.7],
    // Southern & Central Africa
    'ZAR' => ['name' => 'South African Rand','symbol' => 'R','default_rate' => 3.0],
    'MWK' => ['name' => 'Malawian Kwacha','symbol' => 'MK','default_rate' => 0.033],
    'ZMW' => ['name' => 'Zambian Kwacha','symbol' => 'ZK','default_rate' => 2.1],
    'MZN' => ['name' => 'Mozambican Metical','symbol' => 'MT','default_rate' => 0.87],
    'SZL' => ['name' => 'Lilangeni','symbol' => 'L','default_rate' => 3.0],
    'LSL' => ['name' => 'Lesotho Loti','symbol' => 'L','default_rate' => 3.0],
    'NAD' => ['name' => 'Namibian Dollar','symbol' => 'N$','default_rate' => 3.0],
    'BWP' => ['name' => 'Botswana Pula','symbol' => 'P','default_rate' => 4.1],
    'MUR' => ['name' => 'Mauritian Rupee','symbol' => '₨','default_rate' => 1.2],
    'SCR' => ['name' => 'Seychelles Rupee','symbol' => '₨','default_rate' => 4.1],
    // West Africa
    'XAF' => ['name' => 'CFA Franc BEAC','symbol' => 'FCFA','default_rate' => 0.091],
    'XOF' => ['name' => 'CFA Franc BCEAO','symbol' => 'FCFA','default_rate' => 0.091],
    'NGN' => ['name' => 'Nigerian Naira','symbol' => '₦','default_rate' => 0.066],
    'GHS' => ['name' => 'Ghanaian Cedi','symbol' => '₵','default_rate' => 4.6],
    'SLL' => ['name' => 'Sierra Leonean Leone','symbol' => 'Le','default_rate' => 0.0029],
    'LRD' => ['name' => 'Liberian Dollar','symbol' => '$','default_rate' => 0.29],
    'GNF' => ['name' => 'Guinean Franc','symbol' => 'FG','default_rate' => 0.0061],
    'CVE' => ['name' => 'Cape Verdean Escudo','symbol' => 'Esc','default_rate' => 0.55],
    'MRU' => ['name' => 'Mauritanian Ouguiya','symbol' => 'UM','default_rate' => 0.15],
    // North Africa
    'EGP' => ['name' => 'Egyptian Pound','symbol' => '£','default_rate' => 1.8],
    'MAD' => ['name' => 'Moroccan Dirham','symbol' => 'DH','default_rate' => 5.5],
    'TND' => ['name' => 'Tunisian Dinar','symbol' => 'DT','default_rate' => 18.0],
    'DZD' => ['name' => 'Algerian Dinar','symbol' => 'د.ج','default_rate' => 0.40],
    'LYD' => ['name' => 'Libyan Dinar','symbol' => 'LD','default_rate' => 11.5],
    // Middle East & Gulf
    'SAR' => ['name' => 'Saudi Riyal','symbol' => '﷼','default_rate' => 14.8],
    'QAR' => ['name' => 'Qatari Riyal','symbol' => '﷼','default_rate' => 15.2],
    'AED' => ['name' => 'UAE Dirham','symbol' => 'د.إ','default_rate' => 15.1],
    'KWD' => ['name' => 'Kuwaiti Dinar','symbol' => 'د.ك','default_rate' => 180.0],
    'YER' => ['name' => 'Yemeni Rial','symbol' => '﷼','default_rate' => 0.22],
    'JOD' => ['name' => 'Jordanian Dinar','symbol' => 'د.ا','default_rate' => 78.3],
    'ILS' => ['name' => 'Israeli New Shekel','symbol' => '₪','default_rate' => 15.0],
    'LBP' => ['name' => 'Lebanese Pound','symbol' => '£','default_rate' => 0.037],
    // Asia
    'INR' => ['name' => 'Indian Rupee','symbol' => '₹','default_rate' => 0.67],
    'SGD' => ['name' => 'Singapore Dollar','symbol' => 'S$','default_rate' => 41.0],
    'HKD' => ['name' => 'Hong Kong Dollar','symbol' => 'HK$','default_rate' => 7.1],
    'KRW' => ['name' => 'South Korean Won','symbol' => '₩','default_rate' => 0.042],
    'BDT' => ['name' => 'Bangladeshi Taka','symbol' => '৳','default_rate' => 0.51],
    'PKR' => ['name' => 'Pakistani Rupee','symbol' => '₨','default_rate' => 0.20],
    'LKR' => ['name' => 'Sri Lankan Rupee','symbol' => '₨','default_rate' => 0.17],
    'NPR' => ['name' => 'Nepalese Rupee','symbol' => '₨','default_rate' => 0.42],
    'MMK' => ['name' => 'Myanmar Kyat','symbol' => 'K','default_rate' => 0.027],
    'KHR' => ['name' => 'Cambodian Riel','symbol' => '៛','default_rate' => 0.013],
    'THB' => ['name' => 'Thai Baht','symbol' => '฿','default_rate' => 1.5],
    'VND' => ['name' => 'Vietnamese Dong','symbol' => '₫','default_rate' => 0.0023],
    'MYR' => ['name' => 'Malaysian Ringgit','symbol' => 'RM','default_rate' => 11.8],
    'PHP' => ['name' => 'Philippine Peso','symbol' => '₱','default_rate' => 0.99],
    'IDR' => ['name' => 'Indonesian Rupiah','symbol' => 'Rp','default_rate' => 0.0037],
    // Europe
    'RUB' => ['name' => 'Russian Ruble','symbol' => '₽','default_rate' => 0.60],
    'TRY' => ['name' => 'Turkish Lira','symbol' => '₺','default_rate' => 1.9],
    'UAH' => ['name' => 'Ukrainian Hryvnia','symbol' => '₴','default_rate' => 1.5],
    'PLN' => ['name' => 'Polish Zloty','symbol' => 'zł','default_rate' => 13.8],
    'CZK' => ['name' => 'Czech Koruna','symbol' => 'Kč','default_rate' => 2.4],
    'HUF' => ['name' => 'Hungarian Forint','symbol' => 'Ft','default_rate' => 0.15],
    'RON' => ['name' => 'Romanian Leu','symbol' => 'lei','default_rate' => 12.1],
    'BGN' => ['name' => 'Bulgarian Lev','symbol' => 'лв','default_rate' => 30.8],
    'RSD' => ['name' => 'Serbian Dinar','symbol' => 'дин','default_rate' => 0.52],
    // Latin America
    'BRL' => ['name' => 'Brazilian Real','symbol' => 'R$','default_rate' => 10.5],
    'MXN' => ['name' => 'Mexican Peso','symbol' => '$','default_rate' => 3.2],
    'ARS' => ['name' => 'Argentine Peso','symbol' => '$','default_rate' => 0.063],
    'CLP' => ['name' => 'Chilean Peso','symbol' => '$','default_rate' => 0.061],
    'PEN' => ['name' => 'Peruvian Sol','symbol' => 'S/','default_rate' => 14.8],
    'COP' => ['name' => 'Colombian Peso','symbol' => '$','default_rate' => 0.013],
    'UYU' => ['name' => 'Uruguayan Peso','symbol' => '$U','default_rate' => 1.4],
    'BOB' => ['name' => 'Boliviano','symbol' => 'Bs.','default_rate' => 8.0],
    'PYG' => ['name' => 'Paraguayan Guaraní','symbol' => '₲','default_rate' => 0.0075],
    'XCD' => ['name' => 'East Caribbean Dollar','symbol' => '$','default_rate' => 20.5],
    'DOP' => ['name' => 'Dominican Peso','symbol' => '$','default_rate' => 0.98],
    'HTG' => ['name' => 'Haitian Gourde','symbol' => 'G','default_rate' => 0.39],
    // Oceania
    'PGK' => ['name' => 'Papua New Guinean Kina','symbol' => 'K','default_rate' => 14.5],
    'FJD' => ['name' => 'Fijian Dollar','symbol' => 'FJ$','default_rate' => 24.8],
];
}

// -------------------------------------------------------------------
// PSC settings per project
// -------------------------------------------------------------------
$pscPercent      = 0.0;
$pscDescription  = '';

if ($selectedProjectId > 0) {
    $psStmt = $pdo->prepare("SELECT psc_percent, psc_description FROM budget_settings WHERE project_id = ?");
    $psStmt->execute([$selectedProjectId]);
    if ($row = $psStmt->fetch(PDO::FETCH_ASSOC)) {
        $pscPercent     = (float)$row['psc_percent'];
        $pscDescription = $row['psc_description'] ?? '';
    }
}

// -------------------------------------------------------------------
// 3) Handle POST: budget, installments, PSC, bulk, import
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ----- Save PSC settings -----
    if (isset($_POST['save_psc']) && $selectedProjectId > 0) {
        $pscPercent     = isset($_POST['psc_percent']) ? (float)$_POST['psc_percent'] : 0;
        $pscDescription = trim($_POST['psc_description'] ?? '');

        $stmt = $pdo->prepare("
            INSERT INTO budget_settings (project_id, psc_percent, psc_description)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                psc_percent = VALUES(psc_percent),
                psc_description = VALUES(psc_description),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$selectedProjectId, $pscPercent, $pscDescription]);
        $message = 'PSC settings saved successfully.';
    }

    // ----- Save or update budget line (+ inline installments) -----
    if (isset($_POST['save_budget'])) {
        $budgetId       = isset($_POST['budget_id']) ? (int)$_POST['budget_id'] : 0;
        $activity_id    = isset($_POST['activity_id']) ? (int)$_POST['activity_id'] : 0;
        $budget_code    = trim($_POST['budget_code'] ?? '');
        $description    = trim($_POST['description'] ?? '');
        $unit_desc      = $_POST['unit_description'] ?? '';
        $unit_quantity  = (float)($_POST['unit_quantity'] ?? 0);
        $unit_cost      = (float)($_POST['unit_cost'] ?? 0);
        $duration       = (int)($_POST['duration'] ?? 1);
        $percent_cbpf   = (float)($_POST['percent_cbpf'] ?? 0);
        $currency       = trim($_POST['currency'] ?? 'USD');
        $fiscal_year    = trim($_POST['fiscal_year'] ?? date('Y'));
        $donor_agency   = trim($_POST['donor_agency'] ?? '');
        $budget_status  = trim($_POST['budget_status'] ?? 'draft');
        $budget_type    = trim($_POST['budget_type'] ?? 'program');

        if ($donor_agency === 'Other' && !empty($_POST['donor_agency_other'])) {
            $donor_agency = trim($_POST['donor_agency_other']);
        }

        if ($activity_id <= 0 || $description === '') {
            $error = 'Please select activity and fill description.';
        } else {
            $total_cost = round($unit_quantity * $unit_cost * $duration, 4);

            if ($budgetId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE activity_budget
                    SET activity_id = ?, budget_code = ?, description = ?, unit_description = ?,
                        unit_quantity = ?, unit_cost = ?, duration = ?, percent_cbpf = ?,
                        total_cost = ?, currency = ?, fiscal_year = ?, donor_agency = ?,
                        budget_status = ?, budget_type = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $activity_id, $budget_code, $description, $unit_desc,
                    $unit_quantity, $unit_cost, $duration, $percent_cbpf, $total_cost,
                    $currency, $fiscal_year, $donor_agency, $budget_status, $budget_type,
                    $budgetId
                ]);
                $message = 'Budget line updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO activity_budget
                    (activity_id, budget_code, description, unit_description,
                     unit_quantity, unit_cost, duration, percent_cbpf, total_cost,
                     currency, fiscal_year, donor_agency, budget_status, budget_type)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $activity_id, $budget_code, $description, $unit_desc,
                    $unit_quantity, $unit_cost, $duration, $percent_cbpf, $total_cost,
                    $currency, $fiscal_year, $donor_agency, $budget_status, $budget_type
                ]);
                $message  = 'Budget line added successfully.';
                $budgetId = (int)$pdo->lastInsertId();
            }

            // Reload current budget row
            if ($budgetId > 0) {
                $stmt = $pdo->prepare("SELECT * FROM activity_budget WHERE id = ?");
                $stmt->execute([$budgetId]);
                $editBudget = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // ----- Inline Installments from "Add Budget Line" form -----
            if ($budgetId > 0 && isset($_POST['inst_installment_no']) && is_array($_POST['inst_installment_no'])) {
                // delete existing installments for this budget
                $pdo->prepare("DELETE FROM budget_installments WHERE budget_id = ?")->execute([$budgetId]);

                $instNos   = $_POST['inst_installment_no'] ?? [];
                $instPerc  = $_POST['inst_percent'] ?? [];
                $instCurr  = $_POST['inst_currency'] ?? [];
                $instRate  = $_POST['inst_rate'] ?? [];

                foreach ($instNos as $idx => $instNoVal) {
                    $instNo = (int)$instNoVal;
                    if ($instNo <= 0) {
                        continue;
                    }

                    $p = isset($instPerc[$idx]) ? (float)$instPerc[$idx] : 0;
                    if ($p <= 0) {
                        continue;
                    }

                    $cur = isset($instCurr[$idx]) ? trim($instCurr[$idx]) : $currency;
                    $r   = isset($instRate[$idx]) ? (float)$instRate[$idx] : 1;

                    // if ETB as budget or installment currency, keep rate 1
                    if ($cur === 'ETB' || $currency === 'ETB') {
                        $r = 1;
                    }

                    $amount_etb = round($total_cost * ($p / 100.0) * $r, 4);

                    $stmtInst = $pdo->prepare("
                        INSERT INTO budget_installments
                        (budget_id, installment_no, currency, rate, percent, amount_etb, donor_name, status)
                        VALUES (?,?,?,?,?,?,?,?)
                    ");
                    $stmtInst->execute([
                        $budgetId,
                        $instNo,
                        $cur,
                        $r,
                        $p,
                        $amount_etb,
                        $donor_agency,
                        'planned'
                    ]);
                }
            }
        }
    }

    // ----- Delete budget line -----
    if (isset($_POST['delete_budget'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM budget_installments WHERE budget_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM activity_budget WHERE id = ?")->execute([$id]);
            $message = 'Budget line deleted successfully.';
        }
    }

    // ----- Save or update installment (from Installments tab) -----
    if (isset($_POST['save_installment'])) {
        $instId        = isset($_POST['installment_id']) ? (int)$_POST['installment_id'] : 0;
        $budget_id     = isset($_POST['budget_id_for_inst']) ? (int)$_POST['budget_id_for_inst'] : 0;
        $installmentNo = (int)($_POST['installment_no'] ?? 1);
        $currency      = trim($_POST['inst_currency_single'] ?? 'USD');
        $rate          = (float)($_POST['inst_rate_single'] ?? 1);
        $percent       = (float)($_POST['inst_percent_single'] ?? 0);
        $donor_name    = trim($_POST['donor_name'] ?? '');
        $payment_date  = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
        $status        = trim($_POST['status'] ?? 'planned');
        $notes         = trim($_POST['notes'] ?? '');

        if ($budget_id <= 0) {
            $error = 'Please select a budget line for the installment.';
        } else {
            $stmt = $pdo->prepare("SELECT total_cost, currency FROM activity_budget WHERE id = ?");
            $stmt->execute([$budget_id]);
            $row            = $stmt->fetch();
            $total_cost      = $row ? (float)$row['total_cost'] : 0.0;
            $budget_currency = $row ? $row['currency'] : 'USD';

            if ($currency === 'ETB' || $budget_currency === 'ETB') {
                $rate = 1;
            }

            $amount_etb = round($total_cost * ($percent / 100.0) * $rate, 4);

            if ($instId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE budget_installments
                    SET budget_id = ?, installment_no = ?, currency = ?, rate = ?, percent = ?,
                        amount_etb = ?, donor_name = ?, payment_date = ?, status = ?, notes = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $budget_id, $installmentNo, $currency, $rate, $percent,
                    $amount_etb, $donor_name, $payment_date, $status, $notes, $instId
                ]);
                $message = 'Installment updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO budget_installments
                    (budget_id, installment_no, currency, rate, percent, amount_etb, donor_name, payment_date, status, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $budget_id, $installmentNo, $currency, $rate, $percent,
                    $amount_etb, $donor_name, $payment_date, $status, $notes
                ]);
                $message = 'Installment added successfully.';
            }
        }
    }

    // ----- Delete installment -----
    if (isset($_POST['delete_installment'])) {
        $instId = (int)($_POST['id'] ?? 0);
        if ($instId > 0) {
            $pdo->prepare("DELETE FROM budget_installments WHERE id = ?")->execute([$instId]);
            $message = 'Installment deleted successfully.';
        }
    }

    // ----- Bulk actions (delete budgets) -----
    if (isset($_POST['bulk_action'])) {
        $action   = $_POST['bulk_action'];
        $selected = $_POST['selected_items'] ?? [];

        if (!empty($selected)) {
            if ($action === 'delete_budgets') {
                $placeholders = str_repeat('?,', count($selected) - 1) . '?';
                $stmtInst = $pdo->prepare("DELETE FROM budget_installments WHERE budget_id IN ($placeholders)");
                $stmtInst->execute($selected);

                $stmtBud = $pdo->prepare("DELETE FROM activity_budget WHERE id IN ($placeholders)");
                $stmtBud->execute($selected);

                $message = count($selected) . ' budget lines deleted successfully.';
            }
        }
    }

    // ----- Import budgets from CSV -----
    if (isset($_POST['import_budgets']) && isset($_FILES['budget_file'])) {
        if ($_FILES['budget_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload failed. Please try again.';
        } else {
            $filePath = $_FILES['budget_file']['tmp_name'];
            $handle   = fopen($filePath, 'r');

            if ($handle === false) {
                $error = 'Unable to read the uploaded file.';
            } else {
                $row          = 0;
                $headerFound  = false;
                $importedRows = 0;

                while (($data = fgetcsv($handle)) !== false) {
                    $row++;

                    if (count(array_filter($data, 'strlen')) === 0) {
                        continue;
                    }

                    if (!$headerFound) {
                        if (isset($data[0]) && strtolower(trim($data[0])) === 'activity code') {
                            $headerFound = true;
                        }
                        continue;
                    }

                    $activityCode = trim($data[0] ?? '');
                    if ($activityCode === '') {
                        continue;
                    }

                    $budget_code   = trim($data[1] ?? '');
                    $description   = trim($data[2] ?? '');
                    $unit_desc     = trim($data[3] ?? '');
                    $unit_quantity = (float)($data[4] ?? 0);
                    $unit_cost     = (float)($data[5] ?? 0);
                    $duration      = (int)($data[6] ?? 1);
                    $currency      = trim($data[7] ?? 'USD');
                    $percent_cbpf  = (float)($data[8] ?? 0);
                    $donor_agency  = trim($data[9] ?? '');
                    $fiscal_year   = trim($data[10] ?? date('Y'));
                    $budget_status = trim($data[11] ?? 'draft');
                    $budget_type   = trim($data[12] ?? 'program');

                    if ($description === '') {
                        continue;
                    }

                    $actSql   = "SELECT id FROM activities WHERE code = ?";
                    $actParms = [$activityCode];

                    if ($selectedProjectId > 0) {
                        $actSql   .= " AND project_id = ?";
                        $actParms[] = $selectedProjectId;
                    }

                    $actStmt = $pdo->prepare($actSql);
                    $actStmt->execute($actParms);
                    $actRow = $actStmt->fetch();

                    if (!$actRow) {
                        continue;
                    }

                    $activity_id = (int)$actRow['id'];
                    $total_cost  = round($unit_quantity * $unit_cost * $duration, 4);

                    $stmt = $pdo->prepare("
                        INSERT INTO activity_budget
                        (activity_id, budget_code, description, unit_description,
                         unit_quantity, unit_cost, duration, percent_cbpf, total_cost,
                         currency, fiscal_year, donor_agency, budget_status, budget_type)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $activity_id, $budget_code, $description, $unit_desc,
                        $unit_quantity, $unit_cost, $duration, $percent_cbpf, $total_cost,
                        $currency, $fiscal_year, $donor_agency, $budget_status, $budget_type
                    ]);

                    $importedRows++;
                }

                fclose($handle);
                if ($importedRows > 0) {
                    $message = "Imported {$importedRows} budget line(s) from the file.";
                } else {
                    $error = 'No valid budget lines were imported. Please check the template format.';
                }
            }
        }
    }
}

// -------------------------------------------------------------------
// 4) Handle GET: load row for editing
// -------------------------------------------------------------------
if (isset($_GET['edit_budget_id'])) {
    $id = (int)$_GET['edit_budget_id'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM activity_budget WHERE id = ?");
        $stmt->execute([$id]);
        $editBudget = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (isset($_GET['edit_installment_id'])) {
    $id = (int)$_GET['edit_installment_id'];
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM budget_installments WHERE id = ?");
        $stmt->execute([$id]);
        $editInstallment = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// -------------------------------------------------------------------
// 5) Load budget lines (filtered by project)
// -------------------------------------------------------------------
$budgetSql = "
    SELECT 
        b.*,
        a.code AS activity_code,
        a.name AS activity_name,
        a.project_id AS project_id,
        (SELECT COALESCE(SUM(amount_etb),0)
         FROM budget_installments bi
         WHERE bi.budget_id = b.id) AS total_etb,
        (SELECT COUNT(*)
         FROM budget_installments bi
         WHERE bi.budget_id = b.id) AS installment_count
    FROM activity_budget b
    LEFT JOIN activities a ON b.activity_id = a.id
";

$paramsBudget = [];
if ($selectedProjectId > 0) {
    $budgetSql           .= " WHERE a.project_id = :pid";
    $paramsBudget[':pid'] = $selectedProjectId;
}
$budgetSql .= " ORDER BY b.id DESC LIMIT 1000";

$budgetStmt = $pdo->prepare($budgetSql);
$budgetStmt->execute($paramsBudget);
$budgetLines = $budgetStmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------
// 6) Load installments (filtered by project)
// -------------------------------------------------------------------
$installSql = "
    SELECT
        bi.*,
        b.budget_code,
        b.description AS budget_desc,
        b.currency AS budget_currency,
        b.total_cost AS budget_total
    FROM budget_installments bi
    JOIN activity_budget b ON bi.budget_id = b.id
    JOIN activities a ON b.activity_id = a.id
";
$paramsInst = [];
if ($selectedProjectId > 0) {
    $installSql           .= " WHERE a.project_id = :pid";
    $paramsInst[':pid'] = $selectedProjectId;
}
$installSql .= " ORDER BY bi.budget_id, bi.installment_no";

$instStmt     = $pdo->prepare($installSql);
$instStmt->execute($paramsInst);
$installments = $instStmt->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------
// 7) Summary calculations (including Program/Admin + PSC)
// -------------------------------------------------------------------
$total_budget    = 0;
$total_etb       = 0;
$currency_totals = [];

$program_total_donor = 0;
$admin_total_donor   = 0;
$program_total_etb   = 0;
$admin_total_etb     = 0;

// per-project totals (for "Nexus Ethiopia" all-projects summary)
$projectTotals = [];

foreach ($budgetLines as $b) {
    $amountDonor = (float)$b['total_cost'];
    $amountEtb   = (float)$b['total_etb'];
    $currency    = $b['currency'] ?? 'USD';
    $type        = $b['budget_type'] ?? 'program';
    $pid         = (int)($b['project_id'] ?? 0);

    $total_budget += $amountDonor;
    $total_etb    += $amountEtb;

    if (!isset($currency_totals[$currency])) {
        $currency_totals[$currency] = 0;
    }
    $currency_totals[$currency] += $amountDonor;

    if ($type === 'admin') {
        $admin_total_donor += $amountDonor;
        $admin_total_etb   += $amountEtb;
    } else {
        $program_total_donor += $amountDonor;
        $program_total_etb   += $amountEtb;
    }

    if (!isset($projectTotals[$pid])) {
        $projectTotals[$pid] = [
            'total_donor'   => 0,
            'total_etb'     => 0,
            'program_donor' => 0,
            'admin_donor'   => 0
        ];
    }
    $projectTotals[$pid]['total_donor']   += $amountDonor;
    $projectTotals[$pid]['total_etb']     += $amountEtb;
    if ($type === 'admin') {
        $projectTotals[$pid]['admin_donor'] += $amountDonor;
    } else {
        $projectTotals[$pid]['program_donor'] += $amountDonor;
    }
}

// PSC amounts
$psc_amount_donor = $program_total_donor * ($pscPercent / 100.0);
$psc_amount_etb   = $program_total_etb * ($pscPercent / 100.0);

$admin_with_psc_donor = $admin_total_donor + $psc_amount_donor;
$admin_with_psc_etb   = $admin_total_etb + $psc_amount_etb;

$grand_total_donor = $program_total_donor + $admin_with_psc_donor;
$grand_total_etb   = $program_total_etb + $admin_with_psc_etb;

$program_share_pct = $grand_total_donor > 0 ? ($program_total_donor / $grand_total_donor) * 100 : 0;
$admin_share_pct   = $grand_total_donor > 0 ? ($admin_with_psc_donor / $grand_total_donor) * 100 : 0;
$psc_share_pct     = $grand_total_donor > 0 ? ($psc_amount_donor / $grand_total_donor) * 100 : 0;

// helpers
function unit_selected($value, $current) {
    return $value === $current ? 'selected' : '';
}
function currency_options($current = 'USD') {
    // Use helper function for currency dropdown
    return currency_dropdown_options($current, true, true);
}
function donor_options($current = '') {
    // Use helper function for donor dropdown
    $donors = get_all_donors();
    $html = '<option value="">-- Select Donor --</option>';
    foreach ($donors as $donor) {
        $selected = ($donor['name'] === $current || $donor['id'] == $current) ? 'selected' : '';
        $displayName = !empty($donor['short_name']) ? $donor['short_name'] . ' - ' . $donor['name'] : $donor['name'];
        $html .= '<option value="' . htmlspecialchars($donor['name']) . '" ' . $selected . '>' . htmlspecialchars($displayName) . '</option>';
    }
    $html .= '<option value="Other"' . ($current === 'Other' ? ' selected' : '') . '>Other</option>';
    return $html;
}
function status_options($current = 'draft') {
    $statuses = [
        'draft'     => '📝 Draft',
        'submitted' => '📤 Submitted',
        'approved'  => '✅ Approved',
        'rejected'  => '❌ Rejected'
    ];
    $html = '';
    foreach ($statuses as $value => $label) {
        $selected = $value === $current ? 'selected' : '';
        $html    .= "<option value=\"$value\" $selected>$label</option>";
    }
    return $html;
}

// -------------------------------------------------------------------
// 7.1) Header metadata (for export / print)
// -------------------------------------------------------------------
$headerOrg                = 'Nexus Ethiopia';
$headerProjectTitle       = $selectedProjectId > 0 ? 'Selected Project' : 'All Projects';
$headerProjectCode        = '';
$headerDonor              = '';
$headerDonorCurrency      = '';
$headerTotalDonorCurrency = '';
$headerFiscalYear         = date('Y');
$headerTotalEtb           = $total_etb;
$headerTotalEtbFormatted  = number_format((float)$headerTotalEtb, 2);

$projectSettings = [];
try {
    $settingsStmt   = $pdo->query("
        SELECT setting_key, setting_value
        FROM settings_projects
        WHERE setting_key IN ('project_name', 'project_code', 'fiscal_year', 'organization')
    ");
    $projectSettings = $settingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!empty($projectSettings['organization'])) {
        $headerOrg = $projectSettings['organization'];
    }
    if (!empty($projectSettings['fiscal_year'])) {
        $headerFiscalYear = $projectSettings['fiscal_year'];
    }
    if ($selectedProjectId === 0 && !empty($projectSettings['project_name'])) {
        $headerProjectTitle = $projectSettings['project_name'];
    }
    if ($selectedProjectId === 0 && !empty($projectSettings['project_code'])) {
        $headerProjectCode = $projectSettings['project_code'];
    }
} catch (Exception $e) {
    // ignore
}

if ($currentProject) {
    $headerProjectTitle  = $currentProject['project_title'] ?? $currentProject['title'] ?? $currentProject['name'] ?? $headerProjectTitle;
    $headerProjectCode   = $currentProject['project_code'] ?? $currentProject['code'] ?? $headerProjectCode;
    $headerDonor         = $currentProject['donor_name'] ?? $currentProject['donor'] ?? $headerDonor;
    $headerDonorCurrency = $currentProject['donor_currency'] ?? $currentProject['currency'] ?? $headerDonorCurrency;

    if (isset($currentProject['total_budget_donor_currency'])) {
        $headerTotalDonorCurrency = $currentProject['total_budget_donor_currency'];
    } elseif (isset($currentProject['total_budget_donor'])) {
        $headerTotalDonorCurrency = $currentProject['total_budget_donor'];
    }
}

if (!$headerDonorCurrency && !empty($currency_totals)) {
    $maxCurrency = '';
    $maxAmount   = 0;
    foreach ($currency_totals as $cur => $amt) {
        if ($amt > $maxAmount) {
            $maxAmount  = $amt;
            $maxCurrency = $cur;
        }
    }
    $headerDonorCurrency = $maxCurrency;
    if ($headerTotalDonorCurrency === '') {
        $headerTotalDonorCurrency = $maxAmount;
    }
}
$headerTotalDonorCurrencyFormatted = $headerTotalDonorCurrency !== '' ? number_format((float)$headerTotalDonorCurrency, 2) : '';

// -------------------------------------------------------------------
// 8) Export functionality (CSV + Word) – CLEAN, ONLY HEADER + TABLES
// -------------------------------------------------------------------
if (isset($_GET['export'])) {
    $type = $_GET['export'];

    // ---- WORD exports (header + clean table only) ----
    if (in_array($type, ['budgets_word', 'installments_word', 'summary_word'], true)) {
        if (function_exists('hrs_end_all_output_buffers')) { hrs_end_all_output_buffers(); } else { @ob_end_clean(); }

        $isBudgets      = ($type === 'budgets_word');
        $isInstallments = ($type === 'installments_word');
        $isSummary      = ($type === 'summary_word');

        if ($isBudgets) {
            $filename = 'budget_lines_' . date('Y-m-d') . '.doc';
        } elseif ($isInstallments) {
            $filename = 'budget_installments_' . date('Y-m-d') . '.doc';
        } else {
            $filename = 'budget_summary_program_admin_' . date('Y-m-d') . '.doc';
        }

        if (function_exists('hrs_prepare_word_download')) { hrs_prepare_word_download($filename); } else {
            header('Content-Type: application/msword');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        echo "<html><head><meta charset='UTF-8'></head><body>";
        echo "<h2>" . htmlspecialchars($headerOrg) . " - ";
        if ($isBudgets)       echo "Budget Lines";
        elseif ($isInstallments) echo "Budget Installments";
        else                  echo "Program vs Admin Summary";
        echo "</h2>";

        echo "<p>";
        echo "<strong>Project Title:</strong> " . htmlspecialchars($headerProjectTitle) . "<br>";
        echo "<strong>Project Code:</strong> " . htmlspecialchars($headerProjectCode) . "<br>";
        echo "<strong>Donor:</strong> " . htmlspecialchars($headerDonor) . "<br>";
        echo "<strong>Donor Currency:</strong> " . htmlspecialchars($headerDonorCurrency) . "<br>";
        echo "<strong>Total (Donor Currency):</strong> " . htmlspecialchars($headerTotalDonorCurrencyFormatted) . "<br>";
        echo "<strong>Total (ETB):</strong> " . htmlspecialchars($headerTotalEtbFormatted) . "</p>";

        if ($isBudgets) {
            echo "<table border='1' cellspacing='0' cellpadding='4'>";
            echo "<tr>
                    <th>ID</th><th>Activity Code</th><th>Activity Name</th>
                    <th>Budget Code</th><th>Description</th>
                    <th>Unit</th><th>Qty</th><th>Unit Cost</th>
                    <th>Duration</th><th>Currency</th><th>Total Cost</th>
                    <th>Budget Type</th><th>Donor</th><th>Status</th>
                    <th>Installments</th><th>Total ETB</th>
                  </tr>";
            foreach ($budgetLines as $b) {
                echo "<tr>";
                echo "<td>" . (int)$b['id'] . "</td>";
                echo "<td>" . htmlspecialchars($b['activity_code']) . "</td>";
                echo "<td>" . htmlspecialchars($b['activity_name']) . "</td>";
                echo "<td>" . htmlspecialchars($b['budget_code']) . "</td>";
                echo "<td>" . htmlspecialchars($b['description']) . "</td>";
                echo "<td>" . htmlspecialchars($b['unit_description']) . "</td>";
                echo "<td>" . number_format($b['unit_quantity'], 4) . "</td>";
                echo "<td>" . number_format($b['unit_cost'], 4) . "</td>";
                echo "<td>" . htmlspecialchars($b['duration']) . "</td>";
                echo "<td>" . htmlspecialchars($b['currency']) . "</td>";
                echo "<td>" . number_format($b['total_cost'], 4) . "</td>";
                echo "<td>" . htmlspecialchars(ucfirst($b['budget_type'] ?? 'program')) . "</td>";
                echo "<td>" . htmlspecialchars($b['donor_agency'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($b['budget_status'] ?? 'draft') . "</td>";
                echo "<td>" . (int)$b['installment_count'] . "</td>";
                echo "<td>" . number_format((float)$b['total_etb'], 4) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } elseif ($isInstallments) {
            echo "<table border='1' cellspacing='0' cellpadding='4'>";
            echo "<tr>
                    <th>ID</th><th>Budget Code</th><th>Budget Description</th>
                    <th>Installment No</th><th>Currency</th><th>Rate</th>
                    <th>%</th><th>Amount ETB</th><th>Donor</th>
                    <th>Payment Date</th><th>Status</th><th>Notes</th>
                  </tr>";
            foreach ($installments as $inst) {
                echo "<tr>";
                echo "<td>" . (int)$inst['id'] . "</td>";
                echo "<td>" . htmlspecialchars($inst['budget_code']) . "</td>";
                echo "<td>" . htmlspecialchars($inst['budget_desc']) . "</td>";
                echo "<td>" . (int)$inst['installment_no'] . "</td>";
                echo "<td>" . htmlspecialchars($inst['currency']) . "</td>";
                echo "<td>" . number_format($inst['rate'], 4) . "</td>";
                echo "<td>" . number_format($inst['percent'], 2) . "</td>";
                echo "<td>" . number_format($inst['amount_etb'], 4) . "</td>";
                echo "<td>" . htmlspecialchars($inst['donor_name']) . "</td>";
                echo "<td>" . htmlspecialchars($inst['payment_date']) . "</td>";
                echo "<td>" . htmlspecialchars(ucfirst($inst['status'])) . "</td>";
                echo "<td>" . htmlspecialchars($inst['notes']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            // summary Word
            echo "<h3>Program vs Admin Summary</h3>";
            echo "<table border='1' cellspacing='0' cellpadding='4'>";
            echo "<tr>
                    <th>Category</th>
                    <th>Donor Currency</th>
                    <th>ETB</th>
                    <th>% of Total (Donor Currency)</th>
                  </tr>";
            echo "<tr><td>Program (Direct)</td>
                    <td>" . number_format($program_total_donor, 2) . "</td>
                    <td>" . number_format($program_total_etb, 2) . "</td>
                    <td>" . number_format($program_share_pct, 2) . "%</td></tr>";
            echo "<tr><td>Admin (Direct, excl. PSC)</td>
                    <td>" . number_format($admin_total_donor, 2) . "</td>
                    <td>" . number_format($admin_total_etb, 2) . "</td>
                    <td>" . number_format($admin_total_donor && $grand_total_donor ? ($admin_total_donor / $grand_total_donor * 100) : 0, 2) . "%</td></tr>";
            echo "<tr><td>PSC (" . number_format($pscPercent, 2) . "% of Program)</td>
                    <td>" . number_format($psc_amount_donor, 2) . "</td>
                    <td>" . number_format($psc_amount_etb, 2) . "</td>
                    <td>" . number_format($psc_share_pct, 2) . "%</td></tr>";
            echo "<tr><td>Admin incl. PSC</td>
                    <td>" . number_format($admin_with_psc_donor, 2) . "</td>
                    <td>" . number_format($admin_with_psc_etb, 2) . "</td>
                    <td>" . number_format($admin_share_pct, 2) . "%</td></tr>";
            echo "<tr><td><strong>Grand Total</strong></td>
                    <td><strong>" . number_format($grand_total_donor, 2) . "</strong></td>
                    <td><strong>" . number_format($grand_total_etb, 2) . "</strong></td>
                    <td>100%</td></tr>";
            echo "</table>";
        }

        echo "</body></html>";
        exit;
    }

    // ---- CSV exports (Excel-friendly) ----
    $filename = '';
    $data     = [];

    if ($type === 'budgets') {
        $filename = 'budget_lines_' . date('Y-m-d') . '.csv';
        $data[] = [
            'ID', 'Activity Code', 'Activity Name', 'Budget Code', 'Description',
            'Unit', 'Quantity', 'Unit Cost', 'Duration', 'Currency', 'Total Cost',
            'CBPF %', 'Budget Type', 'Donor Agency', 'Status', 'Installments', 'Total ETB'
        ];
        foreach ($budgetLines as $b) {
            $data[] = [
                $b['id'],
                $b['activity_code'],
                $b['activity_name'],
                $b['budget_code'],
                $b['description'],
                $b['unit_description'],
                $b['unit_quantity'],
                $b['unit_cost'],
                $b['duration'],
                $b['currency'],
                $b['total_cost'],
                $b['percent_cbpf'],
                $b['budget_type'] ?? 'program',
                $b['donor_agency'] ?? '',
                $b['budget_status'] ?? 'draft',
                $b['installment_count'],
                $b['total_etb']
            ];
        }
    } elseif ($type === 'budget_template') {
        // Clean import template: header + column header only
        $filename = 'budget_import_template_' . date('Y-m-d') . '.csv';
        $data[] = ["Nexus Ethiopia - Budget Planning Import Template"];
        $data[] = ["Project Title:", $headerProjectTitle];
        $data[] = ["Project Code:", $headerProjectCode];
        $data[] = ["Donor:", $headerDonor];
        $data[] = ["Donor Currency:", $headerDonorCurrency];
        $data[] = ["Total Allocated (Donor Currency):", $headerTotalDonorCurrencyFormatted];
        $data[] = ["Total Allocated (ETB):", $headerTotalEtbFormatted];
        $data[] = [];
        $data[] = [
            'Activity Code', 'Budget Code', 'Description', 'Unit',
            'Quantity', 'Unit Cost', 'Duration (months)', 'Currency',
            'CBPF %', 'Donor Agency', 'Fiscal Year', 'Status', 'Budget Type'
        ];
    } elseif ($type === 'installments') {
        $filename = 'budget_installments_' . date('Y-m-d') . '.csv';
        $data[] = [
            'ID', 'Budget Code', 'Budget Description', 'Installment No',
            'Currency', 'Rate', 'Percentage', 'Amount ETB', 'Donor',
            'Payment Date', 'Status', 'Notes'
        ];
        foreach ($installments as $inst) {
            $data[] = [
                $inst['id'],
                $inst['budget_code'],
                $inst['budget_desc'],
                $inst['installment_no'],
                $inst['currency'],
                $inst['rate'],
                $inst['percent'],
                $inst['amount_etb'],
                $inst['donor_name'],
                $inst['payment_date'],
                $inst['status'],
                $inst['notes']
            ];
        }
    } elseif ($type === 'summary_csv') {
        $filename = 'budget_summary_program_admin_' . date('Y-m-d') . '.csv';
        $data[] = ['Category', 'Donor Currency Total', 'ETB Total', '% of Total (Donor Currency)'];
        $data[] = ['Program (Direct)', $program_total_donor, $program_total_etb, $program_share_pct];
        $data[] = ['Admin (Direct, excl. PSC)', $admin_total_donor, $admin_total_etb,
            $admin_total_donor && $grand_total_donor ? ($admin_total_donor / $grand_total_donor * 100) : 0];
        $data[] = ['PSC (' . $pscPercent . '% of Program)', $psc_amount_donor, $psc_amount_etb, $psc_share_pct];
        $data[] = ['Admin incl. PSC', $admin_with_psc_donor, $admin_with_psc_etb, $admin_share_pct];
        $data[] = ['Grand Total', $grand_total_donor, $grand_total_etb, 100];
    }

    if (!empty($data)) {
        if (function_exists('hrs_prepare_csv_download')) { hrs_prepare_csv_download($filename); } else {
            if (function_exists('hrs_end_all_output_buffers')) { hrs_end_all_output_buffers(); } else { @ob_end_clean(); }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "﻿";
        }
        $output = fopen('php://output', 'w');
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }
}

// End output buffering for normal HTML
if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_flush(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Budget Planning - <?php echo h($headerProjectTitle); ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --accent-color: #e74c3c;
            --warning-color: #f39c12;
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #2c3e50;
            --border-color: #2c3e50;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: #334155;
            line-height: 1.6;
            min-height: 100vh;
        }
        a { text-decoration: none; color: inherit; }
        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
        }
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .project-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin: 10px 0;
        }
        .project-info h2 { margin: 0 0 10px 0; font-size: 1.5em; }
        .project-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            font-size: 0.9em;
        }
        .project-select-form { margin-top: 10px; }
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), #2980b9);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .stat-card h3 { margin: 0 0 10px 0; font-size: 14px; opacity: 0.9; }
        .stat-card .value { font-size: 24px; font-weight: bold; margin: 5px 0; }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            align-items: end;
        }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: 600; margin-bottom: 5px; font-size: 14px; }
        select, input, textarea, button {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .btn:hover { opacity: 0.9; transform: translateY(-2px); }
        .btn-success { background: #2ecc71; }
        .btn-warning { background: #f39c12; }
        .btn-danger  { background: #e74c3c; }
        .btn-info    { background: #3498db; }
        .btn-outline {
            background: transparent;
            border: 1px dashed var(--primary-color);
            color: var(--primary-color);
        }
        .btn-outline:hover { background: var(--primary-color); color: white; }
        .btn-sm { padding: 6px 10px; font-size: 12px; }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
        }
        .table, .table th, .table td { border: 1px solid var(--border-color); }
        .table th, .table td {
            padding: 8px 10px;
            text-align: left;
            color: #000;
            font-size: 13px;
        }
        .table th {
            background: #f0f0f0;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .table tr:hover { background: rgba(52,152,219,0.08); }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success   { background: #2ecc71; color: white; }
        .badge-warning   { background: #f39c12; color: white; }
        .badge-danger    { background: #e74c3c; color: white; }
        .badge-info      { background: #3498db; color: white; }
        .badge-draft     { background: #95a5a6; color: white; }
        .badge-submitted { background: #3498db; color: white; }
        .badge-approved  { background: #27ae60; color: white; }
        .badge-rejected  { background: #e74c3c; color: white; }

        .tab-container { margin: 20px 0; }
        .tabs {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            border: none;
            background: none;
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        .tab.active { border-bottom-color: var(--primary-color); color: var(--primary-color); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .chart-container { position: relative; height: 400px; margin: 20px 0; }
        .toolbar { display: flex; gap: 10px; margin: 15px 0; flex-wrap: wrap; }
        .bulk-actions { display: flex; gap: 10px; align-items: center; margin: 15px 0; }

        .currency-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--primary-color);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
        }
        .calculation-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px;
            margin: 10px 0;
            font-family: monospace;
        }

        .export-dropdown { position: relative; display: inline-block; }
        .export-dropdown-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 220px;
            box-shadow: 0px 8px 16px rgba(0,0,0,0.2);
            z-index: 10;
            border-radius: 6px;
            overflow: hidden;
        }
        .export-dropdown-content a {
            color: var(--text-color);
            padding: 8px 14px;
            text-decoration: none;
            display: block;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
        }
        .export-dropdown-content a:hover { background-color: var(--bg-color); }
        .export-dropdown:hover .export-dropdown-content { display: block; }

        .ai-assistant {
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            color: #ecf0f1;
        }
        .ai-assistant h2 { margin-top: 0; }

        .psc-box {
            background: #fef9e7;
            border: 1px solid #f5c518;
            border-radius: 8px;
            padding: 10px 12px;
            margin-top: 10px;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .stats-cards { grid-template-columns: 1fr; }
            .dashboard-header { flex-direction: column; }
            .toolbar { flex-direction: column; }
            .project-details { grid-template-columns: 1fr; }
        }
        @media print {
            .no-print { display: none !important; }
            .card { box-shadow: none; border: 1px solid #000; }
            .table, .table th, .table td { border: 1px solid #000; }
            .table th { background: #f0f0f0 !important; color: #000 !important; }
        }
    </style>
</head>
<body>
<div class="card">
    <div class="project-info">
        <h2>💰 Advanced Budget Planning</h2>
        <div class="project-details">
            <div><strong>Organization:</strong> <?php echo h($headerOrg); ?></div>
            <div><strong>Fiscal Year:</strong> <?php echo h($headerFiscalYear); ?></div>
            <div><strong>Project:</strong> <?php echo h($headerProjectTitle); ?></div>
            <div><strong>Project Code:</strong> <?php echo h($headerProjectCode ?: 'N/A'); ?></div>
            <div><strong>Donor:</strong> <?php echo h($headerDonor ?: 'N/A'); ?></div>
            <div><strong>Donor Currency:</strong> <?php echo h($headerDonorCurrency ?: 'N/A'); ?></div>
            <div><strong>Total (Donor Currency):</strong> <?php echo $headerTotalDonorCurrencyFormatted ?: '0.00'; ?></div>
            <div><strong>Total (ETB):</strong> <?php echo $headerTotalEtbFormatted; ?></div>
        </div>

        <?php if (!empty($projects)): ?>
            <form method="get" class="project-select-form no-print">
                <label for="project_id">🔎 Choose Project for Budget Planning</label>
                <select name="project_id" id="project_id" onchange="this.form.submit()" data-ai-key="project">
                    <option value="0"<?php echo $selectedProjectId === 0 ? ' selected' : ''; ?>>-- All Projects --</option>
                    <?php foreach ($projects as $proj):
                        $pid   = (int)$proj['id'];
                        $title = $proj['project_title'] ?? $proj['title'] ?? $proj['name'] ?? ('Project ' . $pid);
                        $code  = $proj['project_code'] ?? $proj['code'] ?? '';
                        $donor = $proj['donor_name'] ?? $proj['donor'] ?? '';
                        $labelParts = [];
                        if ($code)  $labelParts[] = $code;
                        $labelParts[] = $title;
                        if ($donor) $labelParts[] = '[' . $donor . ']';
                        $label = implode(' - ', $labelParts);
                        ?>
                        <option value="<?php echo $pid; ?>" <?php echo $selectedProjectId === $pid ? 'selected' : ''; ?>>
                            <?php echo h($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>When you select a project, only its activities and budget lines will be displayed and exported.</small>
            </form>
        <?php else: ?>
            <p class="no-print"><strong>Note:</strong> No projects are registered yet. You can still plan budget lines per activity.</p>
        <?php endif; ?>
    </div>

    <div class="dashboard-header no-print">
        <h1>Budget Management System</h1>
        <div class="toolbar">
            <div class="export-dropdown">
                <button class="btn btn-success">📊 Export Budgets ▼</button>
                <div class="export-dropdown-content">
                    <a href="?export=budgets<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">Excel (CSV - Data)</a>
                    <a href="?export=budget_template<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">Excel (CSV - Import Template)</a>
                    <a href="javascript:void(0)" onclick="exportToPDF('budgets')">PDF Document</a>
                    <a href="?export=budgets_word<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">Word Document</a>
                    <a href="javascript:void(0)" onclick="printBudgetLines()">Print Budget Lines</a>
                </div>
            </div>
            <div class="export-dropdown">
                <button class="btn btn-warning">💰 Export Installments ▼</button>
                <div class="export-dropdown-content">
                    <a href="?export=installments<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">Excel (CSV)</a>
                    <a href="javascript:void(0)" onclick="exportToPDF('installments')">PDF Document</a>
                    <a href="?export=installments_word<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">Word Document</a>
                    <a href="javascript:void(0)" onclick="printInstallments()">Print Installments</a>
                </div>
            </div>
            <div class="export-dropdown">
                <button class="btn btn-info">📈 Export Summary ▼</button>
                <div class="export-dropdown-content">
                    <a href="?export=summary_csv<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">Excel (CSV)</a>
                    <a href="?export=summary_word<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">Word Document</a>
                    <a href="javascript:void(0)" onclick="exportSummaryPDF()">PDF Document</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="badge badge-success no-print" style="margin-top:10px;">✅ <?php echo h($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="badge badge-danger no-print" style="margin-top:10px;">❌ <?php echo h($error); ?></div>
    <?php endif; ?>

    <div class="stats-cards">
        <div class="stat-card">
            <h3>Total Budget Value (All Currencies)</h3>
            <div class="value"><?php echo number_format($total_budget, 2); ?></div>
            <div>💰 Overall Budget</div>
        </div>
        <div class="stat-card">
            <h3>Total ETB Equivalent</h3>
            <div class="value"><?php echo number_format($total_etb, 2); ?></div>
            <div>🇪🇹 ETB Conversion</div>
        </div>
        <div class="stat-card">
            <h3>Budget Lines</h3>
            <div class="value"><?php echo count($budgetLines); ?></div>
            <div>📋 Active Items</div>
        </div>
        <div class="stat-card">
            <h3>Installments</h3>
            <div class="value"><?php echo count($installments); ?></div>
            <div>⏰ Payment Schedule</div>
        </div>
    </div>
</div>

<div class="card ai-assistant no-print">
    <h2>🤖 Nexus Smart Budget Assistant</h2>
    <p id="aiAssistantMessage">
        Start by selecting a project and linking each budget line to the correct activity. As you click different fields, this assistant will explain what you are doing.
    </p>
</div>

<div class="tab-container">
    <div class="tabs no-print">
        <button class="tab active" onclick="switchTab('budgets')">📋 Budget Management</button>
        <button class="tab" onclick="switchTab('installments')">💰 Installments</button>
        <button class="tab" onclick="switchTab('summary')">📈 Analytics & Program vs Admin</button>
    </div>

    <!-- BUDGETS TAB -->
    <div id="budgets" class="tab-content active">
        <div class="card">
            <h2><?php echo $editBudget ? '✏️ Edit Budget Line' : '➕ Add Budget Line'; ?></h2>
            <form method="post" id="budgetForm" class="no-print">
                <input type="hidden" name="budget_id" value="<?php echo $editBudget ? (int)$editBudget['id'] : 0; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="activity_id">Activity</label>
                        <select name="activity_id" id="activity_id" required data-ai-key="activity">
                            <option value="">-- Select activity --</option>
                            <?php foreach ($activities as $a): ?>
                                <option value="<?php echo $a['id']; ?>"
                                    <?php
                                    $selAct = $editBudget ? (int)$editBudget['activity_id'] : 0;
                                    echo ($selAct === (int)$a['id']) ? 'selected' : '';
                                    ?>>
                                    <?php echo h(($a['code'] ?? '') . ' - ' . ($a['name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="budget_code">Budget Code</label>
                        <input type="text" id="budget_code" name="budget_code"
                               data-ai-key="budget_code"
                               value="<?php echo $editBudget ? h($editBudget['budget_code']) : ''; ?>"
                               placeholder="e.g., B.1.1 or BGT-001">
                    </div>
                    <div class="form-group">
                        <label for="fiscal_year">Fiscal Year</label>
                        <input type="text" id="fiscal_year" name="fiscal_year"
                               data-ai-key="fiscal_year"
                               value="<?php echo $editBudget ? h($editBudget['fiscal_year']) : date('Y'); ?>"
                               placeholder="e.g., 2025">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" required
                               data-ai-key="description"
                               value="<?php echo $editBudget ? h($editBudget['description']) : ''; ?>"
                               placeholder="Budget item description (what will be paid/purchased)">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="unit_description">Unit of Measurement</label>
                        <select name="unit_description" id="unit_description" data-ai-key="unit">
                            <?php
                            $currentUnit = $editBudget ? $editBudget['unit_description'] : '';
                            $units = [
                                'Persons','Households','Items','Sessions','Rounds','Trainings','Groups',
                                'Health facilities','Schools','Frequency','Km','Meter','Materials','%','Trips'
                            ];
                            foreach ($units as $u): ?>
                                <option value="<?php echo h($u); ?>" <?php echo unit_selected($u, $currentUnit); ?>>
                                    <?php echo h($u); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="unit_quantity">Unit Quantity</label>
                        <input type="number" step="0.0001" name="unit_quantity" id="unit_quantity"
                               data-ai-key="unit_quantity"
                               value="<?php echo $editBudget ? h($editBudget['unit_quantity']) : '0'; ?>">
                    </div>
                    <div class="form-group">
                        <label for="unit_cost">Unit Cost</label>
                        <input type="number" step="0.0001" name="unit_cost" id="unit_cost"
                               data-ai-key="unit_cost"
                               value="<?php echo $editBudget ? h($editBudget['unit_cost']) : '0'; ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="duration">Duration (Months)</label>
                        <input type="number" name="duration" id="duration"
                               data-ai-key="duration"
                               value="<?php echo $editBudget ? h($editBudget['duration']) : '1'; ?>">
                    </div>
                    <div class="form-group">
                        <label for="currency">Currency</label>
                        <select name="currency" id="currency" data-ai-key="currency">
                            <?php echo currency_options($editBudget ? $editBudget['currency'] : 'USD'); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="budget_type">Budget Type (Program / Admin)</label>
                        <select name="budget_type" id="budget_type">
                            <?php
                            $bt = $editBudget ? ($editBudget['budget_type'] ?? 'program') : 'program';
                            ?>
                            <option value="program" <?php echo $bt === 'program' ? 'selected' : ''; ?>>Program</option>
                            <option value="admin" <?php echo $bt === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="donor_agency">Donor Agency</label>
                        <select name="donor_agency" id="donor_agency" data-ai-key="donor">
                            <?php echo donor_options($editBudget ? ($editBudget['donor_agency'] ?? '') : ''); ?>
                        </select>
                        <input type="text" name="donor_agency_other" id="donor_agency_other"
                               style="display: none; margin-top: 5px;"
                               placeholder="Specify other donor"
                               value="<?php echo ($editBudget && !in_array($editBudget['donor_agency'], $donors)) ? h($editBudget['donor_agency']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="budget_status">Budget Status</label>
                        <select name="budget_status" id="budget_status" data-ai-key="budget_status">
                            <?php echo status_options($editBudget ? ($editBudget['budget_status'] ?? 'draft') : 'draft'); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="percent_cbpf">% charged to CBPF</label>
                        <input type="number" step="0.01" name="percent_cbpf" id="percent_cbpf"
                               data-ai-key="percent_cbpf"
                               value="<?php echo $editBudget ? h($editBudget['percent_cbpf']) : '0'; ?>">
                    </div>
                </div>

                <div class="calculation-preview" id="calculationPreview" style="display:none;">
                    <strong>💰 Real-time Calculation:</strong><br>
                    <span id="calcDetails"></span><br>
                    <strong>Total Budget: <span id="totalCost">0.0000</span> <span id="currencySymbol">$</span></strong>
                </div>

                <!-- Inline Installments Block -->
                <div class="card" style="margin-top:15px; border-style:dashed;">
                    <h3>💵 Installment Setup for This Budget Line</h3>
                    <p style="font-size:13px;">
                        Define the donor installments for this budget line. Total % across installments should be 100.
                        You can later edit installments in the <strong>Installments</strong> tab when exchange rates change.
                    </p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="inst_total_number">Number of Installments</label>
                            <input type="number" id="inst_total_number" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="button" class="btn btn-outline btn-sm" onclick="generateInstallmentRows()">
                                ➕ Generate Installment Rows
                            </button>
                        </div>
                    </div>
                    <div class="table-container" id="installmentTableWrapper" style="margin-top:10px; display:none;">
                        <table class="table" id="inlineInstallmentsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Installment No</th>
                                    <th>Currency</th>
                                    <th>Rate (to ETB)</th>
                                    <th>% of Budget</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Filled by JS -->
                            </tbody>
                        </table>
                        <p style="font-size:12px; margin-top:5px;">
                            <strong>Note:</strong> Amount in ETB per installment = Total budget (donor currency) × % × rate.
                        </p>
                    </div>
                </div>

                <div class="form-row" style="margin-top:15px;">
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="toolbar">
                            <button type="submit" name="save_budget" class="btn btn-success">
                                <?php echo $editBudget ? '🔄 Update Budget' : '💾 Save Budget'; ?>
                            </button>
                            <?php if ($editBudget): ?>
                                <a href="?<?php echo $selectedProjectId ? 'project_id='.$selectedProjectId : ''; ?>" class="btn btn-warning">➕ Add New Budget</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Import block -->
        <div class="card no-print">
            <h2>📥 Import Budget Lines from CSV (Excel)</h2>
            <p>
                1) Download the import template, 2) Fill it in Excel, 3) Save as <strong>CSV</strong>, 4) Upload here.<br>
                The header block (Nexus Ethiopia, Project Title, Donor, Budget Code, Totals) is kept but ignored automatically during import.
            </p>
            <div class="toolbar">
                <a class="btn btn-outline"
                   href="?export=budget_template<?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>">
                    ⬇️ Download Import Template (CSV)
                </a>
            </div>
            <form method="post" enctype="multipart/form-data">
                <div class="form-row">
                    <div class="form-group">
                        <label for="budget_file">Select CSV File (Exported from Excel)</label>
                        <input type="file" name="budget_file" id="budget_file" accept=".csv,text/csv" data-ai-key="import">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" name="import_budgets" class="btn btn-success">📥 Import Budget Lines</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Budget lines table -->
        <div class="card">
            <div class="toolbar no-print">
                <h2>Budget Lines (<?php echo count($budgetLines); ?>)</h2>
                <div class="bulk-actions">
                    <select name="bulk_action" id="bulkAction" data-ai-key="bulk_action">
                        <option value="">Bulk Actions</option>
                        <option value="delete_budgets">Delete Selected</option>
                    </select>
                    <button type="button" class="btn btn-danger" onclick="applyBulkAction()">Apply</button>
                </div>
            </div>

            <form method="post" id="bulkForm" class="no-print">
                <input type="hidden" name="bulk_action" id="bulkFormAction" value="">
                <div class="table-container">
                    <table class="table" id="budgetTable">
                        <thead>
                        <tr>
                            <th class="no-print"><input type="checkbox" id="selectAll"></th>
                            <th>ID</th>
                            <th>Activity</th>
                            <th>Budget Code</th>
                            <th>Description</th>
                            <th>Unit</th>
                            <th>Qty</th>
                            <th>Unit Cost</th>
                            <th>Duration</th>
                            <th>Currency</th>
                            <th>Total Cost</th>
                            <th>Type</th>
                            <th>Donor</th>
                            <th>Status</th>
                            <th>Installments</th>
                            <th>Total ETB</th>
                            <th class="no-print">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($budgetLines as $b): ?>
                            <tr>
                                <td class="no-print"><input type="checkbox" name="selected_items[]" value="<?php echo $b['id']; ?>"></td>
                                <td><?php echo (int)$b['id']; ?></td>
                                <td>
                                    <small><?php echo h($b['activity_code']); ?></small><br>
                                    <strong><?php echo h($b['activity_name']); ?></strong>
                                </td>
                                <td><?php echo h($b['budget_code']); ?></td>
                                <td><?php echo h($b['description']); ?></td>
                                <td><?php echo h($b['unit_description']); ?></td>
                                <td><?php echo number_format($b['unit_quantity'], 4); ?></td>
                                <td><?php echo number_format($b['unit_cost'], 4); ?></td>
                                <td><?php echo h($b['duration']); ?></td>
                                <td>
                                    <span class="currency-badge">
                                        <?php
                                        $c = $b['currency'] ?? 'USD';
                                        $sym = $currencies[$c]['symbol'] ?? '$';
                                        echo $sym . ' ' . $c;
                                        ?>
                                    </span>
                                </td>
                                <td><strong><?php echo number_format($b['total_cost'], 2); ?></strong></td>
                                <td><?php echo h(ucfirst($b['budget_type'] ?? 'program')); ?></td>
                                <td><?php echo h($b['donor_agency'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php
                                    $status = $b['budget_status'] ?? 'draft';
                                    $badgeClass = [
                                        'draft'     => 'badge-draft',
                                        'submitted' => 'badge-submitted',
                                        'approved'  => 'badge-approved',
                                        'rejected'  => 'badge-rejected'
                                    ][$status] ?? 'badge-draft';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($status); ?></span>
                                </td>
                                <td><span class="badge badge-info"><?php echo (int)$b['installment_count']; ?></span></td>
                                <td><strong><?php echo number_format((float)$b['total_etb'], 2); ?></strong></td>
                                <td class="no-print">
                                    <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                        <a href="?edit_budget_id=<?php echo (int)$b['id']; ?><?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>" class="btn btn-sm">✏️ Edit</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this budget line and all its installments?');">
                                            <input type="hidden" name="id" value="<?php echo (int)$b['id']; ?>">
                                            <button type="submit" name="delete_budget" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$budgetLines): ?>
                            <tr><td colspan="17">📭 No budget lines yet. Create your first budget above.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <!-- INSTALLMENTS TAB -->
    <div id="installments" class="tab-content">
        <div class="card">
            <h2>💰 Installment Management</h2>
            <p class="no-print" style="font-size:13px;">
                Use this section to adjust installment exchange rates and percentages when donor disbursement schedules change.
                Installments created from the Budget form will appear here and can be edited.
            </p>

            <!-- Add/Edit Installment Form -->
            <form method="post" class="no-print">
                <input type="hidden" name="installment_id" value="<?php echo $editInstallment ? (int)$editInstallment['id'] : 0; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="budget_id_for_inst">Budget Line</label>
                        <select name="budget_id_for_inst" id="budget_id_for_inst">
                            <option value="">-- Select Budget Line --</option>
                            <?php foreach ($budgetLines as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>"
                                    <?php echo ($editInstallment && (int)$editInstallment['budget_id'] === (int)$b['id']) ? 'selected' : ''; ?>>
                                    <?php echo h(($b['budget_code'] ?: 'NoCode') . ' - ' . mb_strimwidth($b['description'], 0, 60, '...')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="installment_no">Installment No</label>
                        <input type="number" name="installment_no" id="installment_no"
                               value="<?php echo $editInstallment ? (int)$editInstallment['installment_no'] : 1; ?>">
                    </div>
                    <div class="form-group">
                        <label for="inst_currency_single">Currency</label>
                        <select name="inst_currency_single" id="inst_currency_single">
                            <?php echo currency_options($editInstallment ? $editInstallment['currency'] : 'USD'); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="inst_rate_single">Rate (to ETB)</label>
                        <input type="number" step="0.000001" name="inst_rate_single" id="inst_rate_single"
                               value="<?php echo $editInstallment ? h($editInstallment['rate']) : '1'; ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="inst_percent_single">% of Budget</label>
                        <input type="number" step="0.01" name="inst_percent_single" id="inst_percent_single"
                               value="<?php echo $editInstallment ? h($editInstallment['percent']) : '0'; ?>">
                    </div>
                    <div class="form-group">
                        <label for="payment_date">Payment Date</label>
                        <input type="date" name="payment_date" id="payment_date"
                               value="<?php echo $editInstallment && $editInstallment['payment_date'] ? h($editInstallment['payment_date']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <?php
                            $instStatus = $editInstallment ? $editInstallment['status'] : 'planned';
                            ?>
                            <option value="planned"   <?php echo $instStatus === 'planned' ? 'selected' : ''; ?>>Planned</option>
                            <option value="disbursed" <?php echo $instStatus === 'disbursed' ? 'selected' : ''; ?>>Disbursed</option>
                            <option value="pending"   <?php echo $instStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="donor_name">Donor Name (optional)</label>
                        <input type="text" name="donor_name" id="donor_name"
                               value="<?php echo $editInstallment ? h($editInstallment['donor_name']) : ''; ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <input type="text" name="notes" id="notes"
                               value="<?php echo $editInstallment ? h($editInstallment['notes']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div class="toolbar">
                            <button type="submit" name="save_installment" class="btn btn-success">
                                <?php echo $editInstallment ? '🔄 Update Installment' : '💾 Save Installment'; ?>
                            </button>
                            <?php if ($editInstallment): ?>
                                <a href="?<?php echo $selectedProjectId ? 'project_id='.$selectedProjectId : ''; ?>" class="btn btn-warning btn-sm">
                                    ➕ Add New Installment
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </form>

            <!-- Installments table -->
            <div class="table-container" style="margin-top:15px;">
                <table class="table" id="installmentsTable">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Budget Code</th>
                        <th>Budget Description</th>
                        <th>Installment No</th>
                        <th>Currency</th>
                        <th>Rate</th>
                        <th>%</th>
                        <th>Amount ETB</th>
                        <th>Donor</th>
                        <th>Payment Date</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th class="no-print">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($installments as $inst): ?>
                        <tr>
                            <td><?php echo (int)$inst['id']; ?></td>
                            <td><?php echo h($inst['budget_code']); ?></td>
                            <td><?php echo h($inst['budget_desc']); ?></td>
                            <td><?php echo (int)$inst['installment_no']; ?></td>
                            <td><?php echo h($inst['currency']); ?></td>
                            <td><?php echo number_format($inst['rate'], 4); ?></td>
                            <td><?php echo number_format($inst['percent'], 2); ?></td>
                            <td><?php echo number_format($inst['amount_etb'], 2); ?></td>
                            <td><?php echo h($inst['donor_name']); ?></td>
                            <td><?php echo h($inst['payment_date']); ?></td>
                            <td><?php echo h(ucfirst($inst['status'])); ?></td>
                            <td><?php echo h($inst['notes']); ?></td>
                            <td class="no-print">
                                <div style="display:flex; gap:5px;">
                                    <a href="?edit_installment_id=<?php echo (int)$inst['id']; ?><?php echo $selectedProjectId ? '&project_id='.$selectedProjectId : ''; ?>" class="btn btn-sm">✏️ Edit</a>
                                    <form method="post" onsubmit="return confirm('Delete this installment?');">
                                        <input type="hidden" name="id" value="<?php echo (int)$inst['id']; ?>">
                                        <button type="submit" name="delete_installment" class="btn btn-danger btn-sm">🗑️ Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$installments): ?>
                        <tr><td colspan="13">📭 No installments planned yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- SUMMARY TAB -->
    <div id="summary" class="tab-content">
        <div class="card">
            <h2>📈 Budget Analytics & Program vs Admin Summary</h2>

            <!-- PSC Settings -->
            <div class="psc-box no-print">
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="psc_percent">PSC % of Program Cost</label>
                            <input type="number" step="0.01" name="psc_percent" id="psc_percent"
                                   value="<?php echo htmlspecialchars($pscPercent); ?>">
                        </div>
                        <div class="form-group">
                            <label for="psc_description">PSC Description (optional)</label>
                            <input type="text" name="psc_description" id="psc_description"
                                   value="<?php echo htmlspecialchars($pscDescription); ?>"
                                   placeholder="e.g., HQ indirect cost, management fee">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" name="save_psc" class="btn btn-success btn-sm" <?php echo $selectedProjectId ? '' : 'disabled'; ?>>
                                💾 Save PSC Settings
                            </button>
                            <?php if (!$selectedProjectId): ?>
                                <div style="font-size:12px; margin-top:4px;">Select a specific project to store PSC %.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <div class="stats-cards">
                <div class="stat-card" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">
                    <h3>Program vs Admin (Donor Currency)</h3>
                    <div class="value"><?php echo number_format($grand_total_donor, 2); ?></div>
                    <div>Total Budget (Program + Admin + PSC)</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #27ae60, #2ecc71);">
                    <h3>Program Share</h3>
                    <div class="value"><?php echo number_format($program_share_pct, 1); ?>%</div>
                    <div>of total donor currency</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #e67e22, #d35400);">
                    <h3>Admin incl. PSC Share</h3>
                    <div class="value"><?php echo number_format($admin_share_pct, 1); ?>%</div>
                    <div>of total donor currency</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #c0392b, #e74c3c);">
                    <h3>PSC as % of Total</h3>
                    <div class="value"><?php echo number_format($psc_share_pct, 1); ?>%</div>
                    <div>(<?php echo number_format($pscPercent, 1); ?>% of Program)</div>
                </div>
            </div>

            <!-- Program vs Admin Summary Table -->
            <h3>Program vs Admin Summary (Donor Currency & ETB)</h3>
            <div class="table-container" id="summaryTableWrapper">
                <table class="table" id="summaryTable">
                    <thead>
                    <tr>
                        <th>Category</th>
                        <th>Donor Currency Total</th>
                        <th>ETB Total</th>
                        <th>% of Total (Donor Currency)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>Program (Direct)</td>
                        <td><?php echo number_format($program_total_donor, 2); ?></td>
                        <td><?php echo number_format($program_total_etb, 2); ?></td>
                        <td><?php echo number_format($program_share_pct, 2); ?>%</td>
                    </tr>
                    <tr>
                        <td>Admin (Direct, excl. PSC)</td>
                        <td><?php echo number_format($admin_total_donor, 2); ?></td>
                        <td><?php echo number_format($admin_total_etb, 2); ?></td>
                        <td>
                            <?php
                            $admin_direct_pct = $grand_total_donor > 0 ? ($admin_total_donor / $grand_total_donor * 100) : 0;
                            echo number_format($admin_direct_pct, 2);
                            ?>%
                        </td>
                    </tr>
                    <tr>
                        <td>PSC (<?php echo number_format($pscPercent, 2); ?>% of Program)</td>
                        <td><?php echo number_format($psc_amount_donor, 2); ?></td>
                        <td><?php echo number_format($psc_amount_etb, 2); ?></td>
                        <td><?php echo number_format($psc_share_pct, 2); ?>%</td>
                    </tr>
                    <tr>
                        <td>Admin incl. PSC</td>
                        <td><?php echo number_format($admin_with_psc_donor, 2); ?></td>
                        <td><?php echo number_format($admin_with_psc_etb, 2); ?></td>
                        <td><?php echo number_format($admin_share_pct, 2); ?>%</td>
                    </tr>
                    <tr>
                        <td><strong>Grand Total</strong></td>
                        <td><strong><?php echo number_format($grand_total_donor, 2); ?></strong></td>
                        <td><strong><?php echo number_format($grand_total_etb, 2); ?></strong></td>
                        <td>100%</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- Currency breakdown chart -->
            <h3 style="margin-top:20px;">Currency Distribution</h3>
            <div class="chart-container">
                <canvas id="budgetChart"></canvas>
            </div>

            <!-- Currency breakdown table -->
            <h3>Currency Breakdown</h3>
            <div class="table-container">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Currency</th>
                        <th>Total Amount</th>
                        <th>Budget Lines</th>
                        <th>Percentage</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($currency_totals as $currency => $amount): ?>
                        <tr>
                            <td>
                                <span class="currency-badge">
                                    <?php
                                    $symbol = $currencies[$currency]['symbol'] ?? '$';
                                    echo $symbol . ' ' . $currency;
                                    ?>
                                </span>
                            </td>
                            <td><strong><?php echo number_format($amount, 2); ?></strong></td>
                            <td>
                                <?php
                                $countCur = 0;
                                foreach ($budgetLines as $b) {
                                    if (($b['currency'] ?? 'USD') === $currency) $countCur++;
                                }
                                echo $countCur;
                                ?>
                            </td>
                            <td>
                                <?php echo $total_budget > 0 ? number_format(($amount / $total_budget) * 100, 2) : '0.00'; ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$currency_totals): ?>
                        <tr><td colspan="4">No budget data available.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- All-projects summary (Nexus Ethiopia) -->
            <?php if ($selectedProjectId === 0 && !empty($projectTotals)): ?>
                <h3 style="margin-top:25px;">Nexus Ethiopia – Budget Summary by Project (Program vs Admin)</h3>
                <div class="table-container">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Project</th>
                            <th>Code</th>
                            <th>Region</th>
                            <th>Zone</th>
                            <th>Woreda</th>
                            <th>Total Donor</th>
                            <th>Program Donor</th>
                            <th>Admin Donor</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($projectTotals as $pid => $pt): ?>
                            <?php
                            $meta = $projectMeta[$pid] ?? [
                                'title' => 'Project '.$pid,
                                'code'  => '',
                                'region'=> '',
                                'zone'  => '',
                                'woreda'=> ''
                            ];
                            ?>
                            <tr>
                                <td><?php echo h($meta['title']); ?></td>
                                <td><?php echo h($meta['code']); ?></td>
                                <td><?php echo h($meta['region']); ?></td>
                                <td><?php echo h($meta['zone']); ?></td>
                                <td><?php echo h($meta['woreda']); ?></td>
                                <td><?php echo number_format($pt['total_donor'], 2); ?></td>
                                <td><?php echo number_format($pt['program_donor'], 2); ?></td>
                                <td><?php echo number_format($pt['admin_donor'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    const aiMessages = {
        project: "You are choosing which project this budget planning page should focus on. All activities and budget lines below will now be filtered to this project.",
        activity: "Select the project activity that this budget line belongs to. This helps link the budget to your logframe and progress reports.",
        budget_code: "Enter the internal or donor budget code for this line (e.g., B.1.1 or BGT-001). Use the same code used in the donor budget.",
        fiscal_year: "Specify the fiscal year for this expense (e.g., 2025). This supports cross-year reporting and auditing.",
        description: "Describe clearly what this budget line covers – for example, 'Training venue rental for 3 days' or 'Incentive for MHNT staff'.",
        unit: "Choose the unit of measurement that best describes this cost (persons, households, items, sessions, etc.).",
        unit_quantity: "How many units will you pay for? For example, number of days, number of items, or number of people.",
        unit_cost: "Cost per single unit in the selected currency. The system will multiply this by quantity and duration.",
        duration: "How many months (or periods) this cost will be incurred. For one-off costs, use 1.",
        currency: "Select the donor currency for this budget line. If you choose ETB, exchange-rate conversion will be disabled for installments.",
        donor: "Select the donor agency funding this cost. Use 'Other' if it is not in the list and type the name below.",
        budget_status: "Track the status of this line: Draft, Submitted, Approved or Rejected. This is useful for internal control.",
        percent_cbpf: "If this is an EHF/CBPF-funded project, type the percentage of this line that will be charged to CBPF (0–100).",
        bulk_action: "Bulk actions allow you to delete multiple budget lines at once. Be careful – deletion also removes associated installments.",
        import: "Upload the CSV file that you prepared in Excel using the Nexus import template. The system will ignore the header block automatically."
    };

    function updateAssistant(key) {
        const el = document.getElementById('aiAssistantMessage');
        if (!el) return;
        el.textContent = aiMessages[key] || "Nexus Smart Budget Assistant is ready to guide you through the budget planning form.";
    }

    function switchTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));

        document.getElementById(tabName).classList.add('active');
        const btn = document.querySelector(`.tab[onclick="switchTab('${tabName}')"]`);
        if (btn) btn.classList.add('active');

        if (tabName === 'summary') {
            renderBudgetChart();
        }
    }

    function calculateTotalCost() {
        const quantity = parseFloat(document.getElementById('unit_quantity').value) || 0;
        const cost     = parseFloat(document.getElementById('unit_cost').value) || 0;
        const duration = parseInt(document.getElementById('duration').value) || 1;
        const currencySelect = document.getElementById('currency');
        const selectedOption = currencySelect.options[currencySelect.selectedIndex];
        const currencySymbol = selectedOption.textContent.split(' ')[0];

        const total = quantity * cost * duration;

        if (total > 0) {
            document.getElementById('calculationPreview').style.display = 'block';
            document.getElementById('calcDetails').textContent = `${quantity} × ${cost} × ${duration} =`;
            document.getElementById('totalCost').textContent = total.toFixed(4);
            document.getElementById('currencySymbol').textContent = currencySymbol;
        } else {
            document.getElementById('calculationPreview').style.display = 'none';
        }
    }

    function toggleOtherDonor() {
        const donorSelect = document.getElementById('donor_agency');
        const otherInput  = document.getElementById('donor_agency_other');
        if (!donorSelect || !otherInput) return;
        otherInput.style.display = donorSelect.value === 'Other' ? 'block' : 'none';
    }

    function attachAiHints() {
        const elements = document.querySelectorAll('[data-ai-key]');
        elements.forEach(el => {
            const key = el.dataset.aiKey;
            el.addEventListener('focus', () => updateAssistant(key));
            el.addEventListener('change', () => updateAssistant(key));
        });
    }

    function applyBulkAction() {
        const action = document.getElementById('bulkAction').value;
        const selected = Array.from(document.querySelectorAll('input[name="selected_items[]"]:checked')).map(cb => cb.value);

        if (selected.length === 0) {
            alert('Please select at least one budget line.');
            return;
        }

        if (action === 'delete_budgets') {
            if (!confirm(`Are you sure you want to delete ${selected.length} budget line(s)? This will also delete all associated installments.`)) {
                return;
            }
            const form = document.getElementById('bulkForm');
            document.getElementById('bulkFormAction').value = action;
            form.submit();
        } else {
            alert('Select a bulk action first.');
        }
    }

    function printBudgetLines() {
        const table = document.getElementById('budgetTable');
        if (!table) { window.print(); return; }

        const org        = <?php echo json_encode($headerOrg); ?>;
        const proj       = <?php echo json_encode($headerProjectTitle); ?>;
        const code       = <?php echo json_encode($headerProjectCode); ?>;
        const donor      = <?php echo json_encode($headerDonor); ?>;
        const donorCur   = <?php echo json_encode($headerDonorCurrency); ?>;
        const totalDonor = <?php echo json_encode($headerTotalDonorCurrencyFormatted); ?>;
        const totalEtb   = <?php echo json_encode($headerTotalEtbFormatted); ?>;

        const w = window.open('', '_blank');
        w.document.write('<html><head><title>Budget Lines</title>');
        w.document.write('<style>body{font-family:Segoe UI,Arial,sans-serif;font-size:12px;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #000;padding:4px;}th{background:#f0f0f0;}</style>');
        w.document.write('</head><body>');
        w.document.write('<h3>' + org + ' - Budget Lines</h3>');
        w.document.write('<p><strong>Project:</strong> ' + proj + '<br>');
        w.document.write('<strong>Code:</strong> ' + code + '<br>');
        w.document.write('<strong>Donor:</strong> ' + donor + '<br>');
        w.document.write('<strong>Donor Currency:</strong> ' + donorCur + '<br>');
        w.document.write('<strong>Total (' + donorCur + '):</strong> ' + totalDonor + '<br>');
        w.document.write('<strong>Total (ETB):</strong> ' + totalEtb + '</p>');
        w.document.write(table.outerHTML);
        w.document.write('</body></html>');
        w.document.close();
        w.focus();
        w.print();
        w.close();
    }

    function printInstallments() {
        const table = document.getElementById('installmentsTable');
        if (!table) { window.print(); return; }

        const w = window.open('', '_blank');
        w.document.write('<html><head><title>Budget Installments</title>');
        w.document.write('<style>body{font-family:Segoe UI,Arial,sans-serif;font-size:12px;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #000;padding:4px;}th{background:#f0f0f0;}</style>');
        w.document.write('</head><body>');
        w.document.write('<h3>Nexus Ethiopia - Budget Installments</h3>');
        w.document.write(table.outerHTML);
        w.document.write('</body></html>');
        w.document.close();
        w.focus();
        w.print();
        w.close();
    }

    function renderBudgetChart() {
        const canvas = document.getElementById('budgetChart');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        const labels = <?php echo json_encode(array_keys($currency_totals)); ?>;
        const values = <?php echo json_encode(array_values($currency_totals)); ?>;
        if (!labels.length) return;

        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Budget by Currency',
                    data: values,
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    title: { display: true, text: 'Budget Distribution by Currency' }
                }
            }
        });
    }

    function exportToPDF(type) {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            alert('PDF library not loaded.');
            return;
        }
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'pt', 'a4');

        const org  = <?php echo json_encode($headerOrg); ?>;
        const proj = <?php echo json_encode($headerProjectTitle); ?>;
        const code = <?php echo json_encode($headerProjectCode); ?>;
        const donor = <?php echo json_encode($headerDonor); ?>;
        const donorCur = <?php echo json_encode($headerDonorCurrency); ?>;
        const totalDonor = <?php echo json_encode($headerTotalDonorCurrencyFormatted); ?>;
        const totalEtb = <?php echo json_encode($headerTotalEtbFormatted); ?>;

        const isBudgets = (type === 'budgets');
        const title = isBudgets ? 'Budget Lines' : 'Budget Installments';

        doc.setFontSize(14);
        doc.text(org + ' - ' + title, 40, 40);
        doc.setFontSize(11);
        doc.text('Project: ' + proj, 40, 60);
        doc.text('Code: ' + code, 40, 75);
        doc.text('Donor: ' + donor, 40, 90);
        doc.text('Donor Currency: ' + donorCur, 40, 105);
        doc.text('Total (' + donorCur + '): ' + totalDonor, 40, 120);
        doc.text('Total (ETB): ' + totalEtb, 40, 135);

        const table = isBudgets ? document.getElementById('budgetTable') : document.getElementById('installmentsTable');
        if (!table) {
            alert('Table not found');
            return;
        }

        let y = 160;
        const rows = table.querySelectorAll('tr');
        rows.forEach((row, rowIndex) => {
            const cells = row.querySelectorAll('th,td');
            const rowText = [];
            cells.forEach((cell, cIndex) => {
                if (cell.classList.contains('no-print')) return;
                // limit columns for readability
                if (cIndex < 8) {
                    rowText.push(cell.innerText.trim().replace(/\s+/g, ' '));
                }
            });
            doc.text(rowText.join(' | '), 40, y);
            y += 14;
            if (y > 780) {
                doc.addPage();
                y = 40;
            }
        });

        doc.save(isBudgets ? 'budget_lines.pdf' : 'budget_installments.pdf');
    }

    function exportSummaryPDF() {
        if (!window.jspdf || !window.jspdf.jsPDF) {
            alert('PDF library not loaded.');
            return;
        }
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('p', 'pt', 'a4');

        const org  = <?php echo json_encode($headerOrg); ?>;
        const proj = <?php echo json_encode($headerProjectTitle); ?>;

        doc.setFontSize(14);
        doc.text(org + ' - Program vs Admin Summary', 40, 40);
        doc.setFontSize(11);
        doc.text('Project: ' + proj, 40, 60);

        const table = document.getElementById('summaryTable');
        if (!table) {
            alert('Summary table not found');
            return;
        }

        let y = 90;
        const rows = table.querySelectorAll('tr');
        rows.forEach((row) => {
            const cells = row.querySelectorAll('th,td');
            const rowText = [];
            cells.forEach((cell) => {
                rowText.push(cell.innerText.trim().replace(/\s+/g, ' '));
            });
            doc.text(rowText.join(' | '), 40, y);
            y += 14;
            if (y > 780) {
                doc.addPage();
                y = 40;
            }
        });

        doc.save('budget_summary_program_admin.pdf');
    }

    function generateInstallmentRows() {
        const countInput = document.getElementById('inst_total_number');
        const wrapper = document.getElementById('installmentTableWrapper');
        const tbody = document.querySelector('#inlineInstallmentsTable tbody');
        const currencySelect = document.getElementById('currency');
        const baseCurrency = currencySelect ? currencySelect.value : 'USD';
        const defaultRate = (function() {
            const opt = currencySelect.options[currencySelect.selectedIndex];
            const r = opt ? opt.getAttribute('data-rate') : '1';
            return parseFloat(r) || 1;
        })();

        const count = parseInt(countInput.value) || 0;
        tbody.innerHTML = '';

        if (count <= 0) {
            wrapper.style.display = 'none';
            return;
        }

        wrapper.style.display = 'block';
        const equalPercent = (count > 0) ? (100 / count) : 0;

        for (let i = 0; i < count; i++) {
            const tr = document.createElement('tr');

            tr.innerHTML = `
                <td>${i + 1}</td>
                <td>
                    <input type="number" name="inst_installment_no[]" value="${i + 1}" min="1" style="width:70px;">
                </td>
                <td>
                    <select name="inst_currency[]" style="width:120px;">
                        <?php echo currency_options(); ?>
                    </select>
                </td>
                <td>
                    <input type="number" name="inst_rate[]" step="0.000001" value="${defaultRate}" style="width:90px;">
                </td>
                <td>
                    <input type="number" name="inst_percent[]" step="0.01" value="${equalPercent.toFixed(2)}" style="width:80px;">
                </td>
            `;
            tbody.appendChild(tr);

            // set default base currency for each select
            const selects = tr.querySelectorAll('select[name="inst_currency[]"]');
            selects.forEach(sel => {
                sel.value = baseCurrency;
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        calculateTotalCost();
        toggleOtherDonor();
        attachAiHints();

        const dq = document.getElementById('donor_agency');
        if (dq) dq.addEventListener('change', toggleOtherDonor);

        const selAll = document.getElementById('selectAll');
        if (selAll) {
            selAll.addEventListener('change', function () {
                const checkboxes = document.querySelectorAll('input[name="selected_items[]"]');
                checkboxes.forEach(cb => { cb.checked = selAll.checked; });
            });
        }

        // auto chart when summary initially active
        if (document.getElementById('summary').classList.contains('active')) {
            renderBudgetChart();
        }

        document.getElementById('unit_quantity').addEventListener('input', calculateTotalCost);
        document.getElementById('unit_cost').addEventListener('input', calculateTotalCost);
        document.getElementById('duration').addEventListener('input', calculateTotalCost);
        document.getElementById('currency').addEventListener('change', calculateTotalCost);
    });
</script>
</body>
</html>

<?php require_once __DIR__ . '/../footer.php'; ?>
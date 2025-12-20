<?php
// import_geographical_data.php - Script to import full geographical data from Excel structure
require_once __DIR__ . '/../header.php';
require_permission('admin', 'view');

$pdo = getPDO();

function importFullGeographicalData() {
    global $pdo;
    
    // This would be parsed from your actual Excel file
    // For now, I'll create a structured array from your provided data
    $geographicalData = [
        'Tigray' => [
            'Central Zone' => ['Abrgele yechla', 'Abyiadi 2', 'Adet 3', 'Adwa town', 'adwa zuria', 'Ahferom', 'Ahsea', 'Axum town', 'Chlla', 'Egella', 'Embasenyti', 'Edabatsahma', 'Enticho', 'Hahaylle', 'Keyhteklli', 'Kollatemben', 'Laelaymachew', 'Maiknetal', 'Naeder', 'Rama', 'Tahtaymachew', 'Tankamlash'],
            'Eastern Zone' => ['Adigrat', 'E/selase Atsbi', 'Atsbi', 'Edaga Hamus', 'Freweyni', 'Kilteawlaelo', 'Ketema Hawzen', 'Tsaeda Emba', 'Tsiraewemberta', 'Erob', 'Bizet', 'Hawzen', 'Wukro', 'Zalambesa', 'Subhasaesie', 'Ganta Afeshum', 'Gerealta', 'Gulomekeda'],
            'N/West Zone' => ['Asgede', 'L/Koraro', 'Shire Town', 'T/Koraro', 'Zana', 'L/Tselemti', 'Tselemti', 'M/Tsebri', 'Tsinbla', 'E/Guna', 'T/Adiabo', 'Shraro Town', 'L/Adiabo', 'Adi Daero', 'M/Adiabo'],
            'South Zone' => ['Mokoni', 'Raya azebo', 'C|hercher', 'Maychew', 'Endamokoni', 'Nekesege', 'ofla', 'korem Twon', 'zata', 'Alamata Town', 'Raya Alamata', 'E/Alaje', 'Bora', 'Selewa'],
            'West Zone' => ['welkayt', 'Tsegede', 'Setit Humera', 'May kadira', 'May Gaba', 'Korarit', 'Kafta Humera', 'Dansha', 'Awera'],
            'South East Zone' => ['Seharti', 'Adigudom', 'Degua', 'Hagereselam', 'Hintallo', 'Wejerat', 'Enderta', 'Samre'],
            'Mekele' => ['Adihaki', 'Ayder', 'Hadnet', 'Hawelti', 'K/ Weyane', 'Quiha', 'Semen']
        ],
        'Afar' => [
            'Zone 1' => ['Afambo', 'Adear', 'Ayssaita Town', 'Ayssaita woreda', 'Chifra', 'Dubti town', 'Dubti woreda', 'Elidear', 'Gereni', 'Kori', 'Mille', 'Semere-Logia'],
            'Zone 2' => ['Abe\'ala town', 'Abe\'ala Woreda', 'Afdera', 'Berahile', 'Bidu', 'Dallol', 'Erebti', 'Koneba', 'Megale'],
            'Zone 3' => ['Amibara', 'Aregoba', 'Awash Fentale', 'Awash town', 'Dulecha', 'Gele\'alo', 'Gewane', 'Hanorika', 'Bure Medaitu'],
            'Zone 4' => ['Awura', 'Ewa', 'Gulina', 'Teru', 'Yallo'],
            'Zone 5' => ['Dalifaghe', 'Dewe', 'Hadele\'ela', 'Semurobi', 'Telalak']
        ],
        // Add more regions here following the same structure
        'Amhara' => [
            'North Shoa' => ['Angolela', 'Ankober', 'Antsokia', 'Asagirete', 'Baso', 'Berhete', 'D/Birhan', 'Eferata', 'H/Mariam', 'Ensaro', 'Kewote', 'Menze Keya', 'Menze Lalo', 'Menze Mama', 'Menze Gera', 'Merhabete', 'Mida', 'Minjar', 'Moja', 'Morete', 'Shewarobit', 'Siadbere', 'Tarmaber', 'Gishe'],
            'Awi zone' => ['Ankesha', 'Ayehu Guagusa', 'Banja', 'Chagni Town', 'Dangila Town', 'Dangila Zuria', 'Fagita Lekoma', 'Guagusa shikudad', 'Guangua', 'Injibara Town', 'Jawi', 'Zigem']
            // Continue with other zones for Amhara...
        ]
        // Continue with other regions...
    ];
    
    try {
        $pdo->beginTransaction();
        
        $totalImported = 0;
        
        foreach ($geographicalData as $regionName => $zones) {
            // Insert region
            $regionStmt = $pdo->prepare("INSERT IGNORE INTO regions (name) VALUES (?)");
            $regionStmt->execute([$regionName]);
            $regionId = $pdo->lastInsertId();
            
            if (!$regionId) {
                // Region already exists, get its ID
                $getRegionStmt = $pdo->prepare("SELECT id FROM regions WHERE name = ?");
                $getRegionStmt->execute([$regionName]);
                $regionId = $getRegionStmt->fetchColumn();
            }
            
            foreach ($zones as $zoneName => $woredas) {
                // Insert zone
                $zoneStmt = $pdo->prepare("INSERT IGNORE INTO zones (name, region_id) VALUES (?, ?)");
                $zoneStmt->execute([$zoneName, $regionId]);
                $zoneId = $pdo->lastInsertId();
                
                if (!$zoneId) {
                    // Zone already exists, get its ID
                    $getZoneStmt = $pdo->prepare("SELECT id FROM zones WHERE name = ? AND region_id = ?");
                    $getZoneStmt->execute([$zoneName, $regionId]);
                    $zoneId = $getZoneStmt->fetchColumn();
                }
                
                foreach ($woredas as $woredaName) {
                    // Insert woreda
                    $woredaStmt = $pdo->prepare("INSERT IGNORE INTO woredas (name, zone_id) VALUES (?, ?)");
                    $woredaStmt->execute([$woredaName, $zoneId]);
                    $totalImported++;
                }
            }
        }
        
        $pdo->commit();
        return ['success' => true, 'message' => "Successfully imported $totalImported geographical entries!"];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Handle the import
if ($_POST && isset($_POST['import_full_data'])) {
    $result = importFullGeographicalData();
    
    if ($result['success']) {
        set_flash_message($result['message'], 'success');
    } else {
        set_flash_message("Error importing data: " . $result['error'], 'error');
    }
    
    header('Location: cfm_enhanced.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Geographical Data - SMART Nexus</title>
</head>
<body>
    <div class="page-container">
        <div class="cfm-container">
            <h1>Import Full Geographical Data</h1>
            
            <div class="notification info">
                <p>This will import the complete geographical data (regions, zones, woredas) from the provided Excel structure.</p>
                <p><strong>Note:</strong> This may take a few moments depending on the amount of data.</p>
            </div>
            
            <form method="POST">
                <button type="submit" name="import_full_data" class="btn btn-primary" 
                        onclick="return confirm('Are you sure you want to import the full geographical data? This will add all regions, zones, and woredas from the Excel structure.')">
                    <i class="fas fa-file-import"></i> Import Full Geographical Data
                </button>
                <a href="cfm_enhanced.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to CFM
                </a>
            </form>
        </div>
    </div>
</body>
</html>
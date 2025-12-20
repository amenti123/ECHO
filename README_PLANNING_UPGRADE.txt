Replace these files in your system (keep same paths):

1) health_reporting_system/pages/planning.php
2) health_reporting_system/db.php
3) health_reporting_system/config.php (only if you want to standardize; no functional change)

Notes:
- This upgrade fixes planning saves by aligning output_indicators total column (target_total vs total_target) and beneficiary table location/HH columns.
- Tables are auto-migrated on first load of planning.php / db.php (no manual SQL needed).

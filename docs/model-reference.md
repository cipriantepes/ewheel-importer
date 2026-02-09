# Ewheel Model ID Reference

Mapping of ewheel numeric model IDs to scooter names.
These are the `scooter_model` taxonomy terms — the plugin auto-names known models on first sync.

## Confirmed Mappings (84 models)

Scraped from ewheel.es single-model products (reliable 1:1 mapping).
These are hardcoded in `ModelService::MODEL_NAMES`.

| Model ID | Brand | Scooter Name |
|----------|-------|-------------|
| 1 | Ninebot | Ninebot ES2 |
| 2 | Ninebot | Ninebot ES4 |
| 3 | Ninebot | Ninebot ES1 |
| 7 | Xiaomi | Xiaomi Pro 2 |
| 8 | Xiaomi | Xiaomi Mi 3 |
| 13 | Xiaomi | Xiaomi Pro |
| 14 | Ninebot | Ninebot MAX G30 |
| 25 | Dualtron | Dualtron Speedway 4 |
| 26 | SmartGyro | SmartGyro Rockway Pro V2.0 |
| 29 | Ninebot | Ninebot KickScooter MAX G2 |
| 52 | Xiaomi | Xiaomi Mi 4 Pro (1st Gen) |
| 57 | Ninebot | Ninebot F2 |
| 59 | SmartGyro | SmartGyro SpeedWay PRO |
| 62 | Wispeed | Wispeed T850 |
| 67 | Ninebot | Ninebot KickScooter E22 |
| 68 | Ninebot | Ninebot KickScooter E25 |
| 69 | Ninebot | Ninebot KickScooter E45 |
| 71 | Dualtron | Dualtron Mini |
| 79 | Dualtron | Dualtron Thunder |
| 80 | Dualtron | Dualtron Raptor |
| 81 | Dualtron | Dualtron Thunder 2 |
| 82 | Dualtron | Dualtron Compact |
| 83 | Dualtron | Dualtron Storm |
| 85 | Dualtron | Dualtron Spider |
| 87 | Dualtron | Dualtron Speedway |
| 88 | Dualtron | Dualtron Achilleus |
| 90 | Dualtron | Dualtron Eagle |
| 91 | Dualtron | Dualtron Eagle |
| 93 | Dualtron | Dualtron 3 |
| 95 | Dualtron | Dualtron Storm |
| 96 | Dualtron | Dualtron Victor Luxury |
| 102 | Xiaomi | Xiaomi Mi 3 Lite |
| 110 | Xiaomi | Xiaomi Mi 4 Lite |
| 112 | Dualtron | Dualtron Thunder |
| 113 | Dualtron | Dualtron Victor |
| 116 | Dualtron | Dualtron Raptor |
| 117 | Dualtron | Dualtron Victor Luxury Plus |
| 118 | NIU | NIU KQi3 |
| 119 | NIU | NIU KQi2 Pro |
| 120 | NIU | NIU KQi1 |
| 122 | Xiaomi | Xiaomi Mi 4 |
| 124 | Kukirin | Kukirin G2 Max |
| 125 | Kukirin | Kukirin G3 Pro |
| 127 | Xiaomi | Xiaomi Mi 4 Ultra |
| 129 | NIU | NIU KQi3 Max |
| 130 | NIU | NIU KQi3 Pro |
| 140 | Dualtron | Dualtron Mini |
| 146 | Dualtron | Dualtron Speedway 5 Dual Motor |
| 147 | Xiaomi | Xiaomi Mi 4 GO |
| 152 | Dualtron | Dualtron Mini Special Long Body Single Motor |
| 153 | Dualtron | Dualtron Mini Special Long Body Dual Motor |
| 158 | Dualtron | Dualtron Popular Dual Motor |
| 159 | Dualtron | Dualtron Popular Single Motor |
| 204 | Ninebot | Ninebot KickScooter MAX G2 |
| 205 | Ninebot | Ninebot KickScooter MAX G2 D |
| 243 | Wispeed | Wispeed T850 |
| 327 | Kugoo | Kugoo G2 Pro |
| 444 | Navee | Navee N40 |
| 452 | Navee | Navee N65 |
| 459 | Xiaomi | Xiaomi Mi 4 Lite (2nd Generation) |
| 485 | Xiaomi | Xiaomi Mi 4 Pro (2nd Generation) |
| 486 | Xiaomi | Xiaomi Mi 4 Pro Max |
| 899 | Xiaomi | Xiaomi Mi 4 - Versión FR |
| 920 | NIU | Niu KQi300X |
| 921 | NIU | Niu KQi4 Sport |
| 923 | NIU | Niu KQi AIR |
| 924 | NIU | Niu KQi300P |
| 925 | NIU | Niu KQi100 |
| 926 | NIU | Niu KQi3 Sport |
| 932 | Wispeed | Wispeed T865 |
| 934 | Ninebot | Ninebot F3 E |
| 937 | Ninebot | Ninebot F3 E |
| 947 | Ninebot | Ninebot ZT3 Pro EU |
| 956 | NIU | Niu KQi1 Pro |
| 961 | SmartGyro | Smartgyro Crossover Dual Max 2 |
| 973 | Xiaomi | Xiaomi Mi4 Lite Gen2 (IT/DE) |
| 974 | Xiaomi | Xiaomi Mi4 Pro Gen1 (IT) |
| 975 | Xiaomi | Xiaomi Mi4 Pro Gen1 (IT) |
| 976 | Xiaomi | Xiaomi Mi4 Pro Plus |
| 978 | Xiaomi | Xiaomi Mi 5 Max |
| 979 | Xiaomi | Xiaomi Mi 5 Pro |
| 980 | Xiaomi | Xiaomi Mi 5 |
| 981 | Navee | Navee N20 |
| 990 | NIU | Niu KQi2 |

## Unmapped IDs (~240)

These IDs only appear in multi-model products (e.g., universal tires compatible with 20+ scooters).
Positional mapping is unreliable for these. They will be created as numeric terms until manually renamed or a future scrape resolves them.

## How to Use

1. Run a full sync — terms are auto-created with real names for the 84 known models
2. Unknown model IDs appear as numeric terms in WP Admin > Products > Models
3. To rename: click the term, change the name — the slug stays as the numeric ID
4. The `_ewheel_model_id` meta on each term ensures future syncs still match correctly

## Updating the Mapping

To add new mappings, edit `ModelService::MODEL_NAMES` in `includes/Service/ModelService.php`.
As ewheel.es adds new single-model products, re-scrape to discover new ID→name pairs.

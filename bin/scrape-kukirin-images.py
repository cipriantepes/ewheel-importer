#!/usr/bin/env python3
"""
Kukirin EU Image Scraper for WooCommerce Products

Scrapes product variant images from Kukirin EU Shopify store and matches them
to existing WooCommerce spare parts products using bilingual fuzzy matching.

Usage:
    python scrape-kukirin-images.py              # Dry-run (default)
    python scrape-kukirin-images.py --apply      # Actually download and assign images
"""

import sys
import json
import time
import re
import subprocess
import unicodedata
from pathlib import Path
from typing import Dict, List, Tuple, Optional
from difflib import SequenceMatcher
from functools import partial

# Force unbuffered output so logs appear in real-time
print = partial(print, flush=True)

# Auto-install dependencies
def ensure_dependencies():
    """Auto-install required packages if not found."""
    missing = []
    try:
        import requests
    except ImportError:
        missing.append('requests')

    try:
        from PIL import Image
    except ImportError:
        missing.append('Pillow')

    if missing:
        print(f"Installing missing dependencies: {', '.join(missing)}")
        subprocess.check_call([sys.executable, '-m', 'pip', 'install', '--quiet'] + missing)
        print("Dependencies installed successfully\n")

ensure_dependencies()

import requests
from PIL import Image

# Configuration
BASE_URL = "https://www.kugookirineu.com"
MODEL_URLS = {
    'Kukirin G2':          '/products/spare-parts-for-kukirin-g2-electric-scooter',
    'Kukirin G2 Master':   '/products/spare-parts-for-kukirin-g2-master-electric-scooter',
    'Kukirin G2 Max':      '/products/spare-parts-for-kukirin-g2-max-electric-scooter',
    'Kukirin G2 Pro 2023': '/products/spare-parts-for-kukirin-g2-pro-2024-version-kukirin-g2-pro-kugookirin-g2-pro-electric-scooter',
    'Kukirin G2 Pro 2024': '/products/spare-parts-for-kukirin-g2-pro-2024-version-kukirin-g2-pro-kugookirin-g2-pro-electric-scooter',
    'Kukirin G3':          '/products/spare-parts-for-kugoo-kirin-g3-kukirin-g3-electric-scooter',
    'Kukirin G3 Pro':      '/products/spare-parts-for-kukirin-g3-pro-electric-scooter',
    'Kukirin G4':          '/products/spare-parts-for-kukirin-g4-electric-scooter',
    'Kukirin G4 Max':      '/products/spare-parts-for-kukirin-g4-max-electric-scooter',
    'Kukirin A1':          '/products/spare-parts-for-kukirin-a1-electric-scooter',
    'Kukirin C1 Pro':      '/products/spare-parts-for-kukirin-c1-pro-electric-scooter',
    'Kukirin M4 Max':      '/products/spare-parts-for-kukirin-m4-max-electric-scooter',
    'Kukirin S1 Max':      '/products/spare-parts-for-kukirin-s1-max-electric-scooter',
    'Kukirin T3':          '/products/spare-parts-for-kukirin-t3-electric-scooter',
    'Kukirin G2 Ultra':    '/products/spare-parts-for-kukirin-g2-ultra-electric-scooter',
}

# English to Romanian translation dictionary for scooter parts
EN_TO_RO = {
    'brake': 'frana', 'cable': 'cablu', 'front': 'fata', 'rear': 'spate',
    'wheel': 'roata', 'motor': 'motor', 'battery': 'baterie',
    'charger': 'incarcator', 'display': 'display', 'dashboard': 'ecran',
    'controller': 'controller', 'light': 'lumina', 'headlight': 'far',
    'tail light': 'stop', 'taillight': 'stop', 'fender': 'aparatoare',
    'tire': 'anvelopa', 'tyre': 'anvelopa', 'tube': 'camera', 'inner tube': 'camera',
    'stem': 'tija', 'seat': 'scaun', 'folding': 'pliabil',
    'suspension': 'suspensie', 'shock absorber': 'amortizor', 'shock': 'amortizor',
    'throttle': 'accelerator', 'grip': 'manson', 'grips': 'mansoane', 'lever': 'maneta',
    'disc': 'disc', 'pad': 'placuta', 'pads': 'placute', 'caliper': 'etrier',
    'kickstand': 'picior', 'crossbar': 'bara', 'crossbeam': 'traversa',
    'rocker arm': 'brat', 'arm': 'brat',
    'left': 'stanga', 'right': 'dreapta', 'pair': 'pereche',
    'assembly': 'ansamblu', 'cover': 'capac', 'hook': 'carlig',
    'lock': 'incuietoare', 'switch': 'comutator', 'headset': 'cuveta',
    'hub': 'butuc', 'rim': 'janta', 'protective': 'protectie', 'protection': 'protectie',
    'pedal': 'pedala', 'rubber': 'cauciuc',
    'charging port': 'port', 'port': 'port', 'integrated': 'integrat',
    'sidelight': 'laterala', 'side': 'lateral',
    'handlebar': 'ghidon', 'screw': 'surub', 'bolt': 'surub',
    'nut': 'piulita', 'washer': 'saiba', 'bearing': 'rulment',
    'chain': 'lant', 'sprocket': 'pinion', 'gear': 'angrenaj',
    'plug': 'mufa', 'socket': 'priza', 'connector': 'conector',
    'wire': 'cablu', 'wiring': 'cablaj', 'harness': 'cablaj',
    'key': 'cheie', 'horn': 'claxon', 'bell': 'clopotel',
    'basket': 'cos', 'bag': 'geanta', 'mirror': 'oglinda',
    'stand': 'suport', 'holder': 'suport', 'mount': 'suport',
    'mudguard': 'aparatoare', 'splash guard': 'aparatoare',
    'footrest': 'platforma', 'deck': 'platforma', 'platform': 'platforma',
    'version': 'versiune', 'type': 'tip', 'model': 'model',
    # Additional terms for better matching
    'instrument panel': 'ecran', 'panel': 'panou',
    'swing arm': 'brat oscilant', 'beam': 'grinda',
    'ball': 'bila', 'ball bearing': 'rulment',
    'spring': 'arc', 'v-shaped spring': 'arc v',
    'outer tire': 'anvelopa', 'vacuum tire': 'anvelopa',
    'tubeless tire': 'anvelopa tubeless', 'tubeless': 'tubeless',
    'silicone': 'silicon', 'dust cap': 'capac antipraf', 'dust': 'praf',
    'decorative': 'decorativ', 'plate': 'placa',
    'main cable': 'cablu principal', 'brake cable': 'cablu frana',
    'clamp': 'clema', 'seatpost': 'tija sa', 'seat post': 'tija sa',
    'cushion': 'perna', 'backrest': 'spatar',
    'quick release': 'deconectare rapida', 'axle': 'ax',
    'folding mechanism': 'mecanism pliere', 'folding stand': 'suport pliabil',
    'tool kit': 'set instrumente', 'manual': 'manual',
    'sunflower': 'floare', 'wooden board': 'placa lemn',
    'module': 'modul', 'ambient light': 'lumina ambientala', 'ambient': 'ambient',
    'pole': 'stalp', 'vertical': 'vertical', 'threaded pipe': 'teava filetata',
    'threaded': 'filetat', 'pipe': 'teava',
    'ignition': 'contact', 'ignition lock': 'bloc contact',
    'bridge': 'punte', 'footpad': 'protectie picior',
}

# Path configurations
PRODUCTS_FILE = Path('/tmp/trotibike-products.jsonl')
SSH_CONFIG_FILE = Path('/Users/cipriantepes/Studio/trotibike/.ssh-config')
REPORT_FILE = Path.home() / 'Downloads' / 'kukirin-scrape-report.txt'
TEMP_DIR = Path('/tmp/kukirin-images')

# Rate limiting
SHOPIFY_API_DELAY = 1.0  # seconds between API requests
IMAGE_DOWNLOAD_DELAY = 0.5  # seconds between image downloads

# Matching threshold
MATCH_THRESHOLD = 0.35


def normalize_text(text: str) -> str:
    """Normalize text for matching: remove diacritics, lowercase, strip whitespace."""
    if not text:
        return ""
    # Remove diacritics
    nfkd = unicodedata.normalize('NFKD', text)
    text = ''.join([c for c in nfkd if not unicodedata.combining(c)])
    # Lowercase and normalize whitespace
    text = ' '.join(text.lower().split())
    return text


def strip_version_suffix(text: str) -> str:
    """Remove version suffixes like -V3 version, -V4 version, (A), (B), etc."""
    patterns = [
        r'\s*-\s*V\d+\s+version\s*$',
        r'\s*-\s*VMP\s+version\s*$',
        r'\s*\(pc\)\s*$',
        r'\s*-\s*\d+\s+pcs?\s*$',
        r'\s*-\s*[A-Z]\s+version\s*$',       # -A version, -B version
        r'\s*\([A-C]\)\s*$',                  # (A), (B), (C) at end
        r'\s*\([A-C]\)\s*(?=\s)',             # (A), (B), (C) mid-string
        r'\s*-\s*\d+Ah\s+version\s*$',       # -26Ah version
    ]
    for pattern in patterns:
        text = re.sub(pattern, '', text, flags=re.IGNORECASE)
    return text.strip()


# Known model prefix patterns in product names (no spaces, abbreviated, compound)
MODEL_PREFIX_PATTERNS = {
    'Kukirin G2 Master':   [r'G2\s*Master', r'G2master'],
    'Kukirin G2 Max':      [r'G2\s*Max', r'G2max', r'EUG2pro[-\s]G2max', r'EU24G2pro[-\s]G2max'],
    'Kukirin G2 Pro 2023': [r'G2\s*Pro\s*(?:2023)?', r'G2pro', r'EUG2pro'],
    'Kukirin G2 Pro 2024': [r'G2\s*Pro\s*(?:2024)?', r'G2pro', r'EUG2pro'],
    'Kukirin G2':          [r'G2'],
    'Kukirin G3 Pro':      [r'G3\s*Pro', r'G3pro'],
    'Kukirin G3':          [r'G3'],
    'Kukirin G4 Max':      [r'G4\s*Max', r'G4max'],
    'Kukirin G4':          [r'G4'],
    'Kukirin A1':          [r'A1'],
    'Kukirin C1 Pro':      [r'C1\s*Pro', r'C1pro'],
    'Kukirin M4 Max':      [r'M4\s*Max', r'M4max'],
    'Kukirin S1 Max':      [r'S1\s*Max', r'S1max'],
    'Kukirin T3':          [r'T3'],
    'Kukirin G2 Ultra':    [r'G2\s*Ultra', r'G2ultra'],
}


def strip_model_prefix(text: str, model: str) -> str:
    """Remove model prefix from product name (handles G2Master, C1pro, EUG2pro-G2max, etc.)."""
    # Try known patterns for this model (longer patterns first)
    if model in MODEL_PREFIX_PATTERNS:
        for pattern in MODEL_PREFIX_PATTERNS[model]:
            text = re.sub(f'^{pattern}\\s*', '', text, flags=re.IGNORECASE)

    # Also strip generic "Kukirin" + model
    model_short = model.replace('Kukirin ', '')
    text = re.sub(f'^{re.escape(model_short)}\\s*', '', text, flags=re.IGNORECASE)

    # Strip "Kukirin" itself if still present
    text = re.sub(r'^Kukirin\s+', '', text, flags=re.IGNORECASE)

    return text.strip()


def translate_to_romanian(english_text: str) -> str:
    """Translate English part name to Romanian using dictionary."""
    normalized = normalize_text(english_text)
    words = normalized.split()
    translated_words = []

    # Try to translate multi-word phrases first
    i = 0
    while i < len(words):
        matched = False
        # Try 3-word phrases, then 2-word, then 1-word
        for phrase_len in [3, 2, 1]:
            if i + phrase_len <= len(words):
                phrase = ' '.join(words[i:i+phrase_len])
                if phrase in EN_TO_RO:
                    translated_words.append(EN_TO_RO[phrase])
                    i += phrase_len
                    matched = True
                    break
        if not matched:
            translated_words.append(words[i])
            i += 1

    return ' '.join(translated_words)


def word_overlap_score(text1: str, text2: str) -> float:
    """Calculate word overlap score between two texts."""
    words1 = set(normalize_text(text1).split())
    words2 = set(normalize_text(text2).split())

    if not words1 or not words2:
        return 0.0

    intersection = words1 & words2
    union = words1 | words2

    return len(intersection) / len(union) if union else 0.0


def fuzzy_match_score(text1: str, text2: str) -> float:
    """Calculate combined fuzzy match score."""
    # Word overlap
    overlap = word_overlap_score(text1, text2)

    # Sequence matching
    sequence = SequenceMatcher(None, normalize_text(text1), normalize_text(text2)).ratio()

    # Weighted combination (favor word overlap)
    return 0.6 * overlap + 0.4 * sequence


def match_variant_to_product(variant_name: str, product: Dict, model: str) -> float:
    """Match a Kukirin variant to a WooCommerce product using bilingual fuzzy matching."""
    # Clean variant name
    variant_clean = strip_version_suffix(variant_name)
    variant_clean = strip_model_prefix(variant_clean, model)

    # English matching: compare with short_desc
    short_desc = product.get('short_desc', '')
    if short_desc:
        short_desc_clean = strip_model_prefix(short_desc, model)
        english_score = fuzzy_match_score(variant_clean, short_desc_clean)
    else:
        english_score = 0.0

    # Romanian matching: translate variant name and compare with Romanian product name
    variant_ro = translate_to_romanian(variant_clean)
    product_name = product.get('name', '')
    product_name_clean = strip_model_prefix(product_name, model)
    romanian_score = fuzzy_match_score(variant_ro, product_name_clean)

    # Return best score
    return max(english_score, romanian_score)


def load_products() -> List[Dict]:
    """Load products from JSONL file."""
    products = []
    if not PRODUCTS_FILE.exists():
        print(f"ERROR: Products file not found: {PRODUCTS_FILE}")
        sys.exit(1)

    with open(PRODUCTS_FILE, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if line:
                try:
                    products.append(json.loads(line))
                except json.JSONDecodeError as e:
                    print(f"WARNING: Failed to parse line: {line[:50]}... ({e})")

    return products


def load_ssh_config() -> Dict[str, str]:
    """Load SSH configuration from .ssh-config file."""
    config = {}
    if not SSH_CONFIG_FILE.exists():
        print(f"ERROR: SSH config file not found: {SSH_CONFIG_FILE}")
        sys.exit(1)

    with open(SSH_CONFIG_FILE, 'r') as f:
        for line in f:
            line = line.strip()
            if line and '=' in line:
                key, value = line.split('=', 1)
                config[key.strip()] = value.strip()

    # Expand ~ in SSH_KEY path
    if 'SSH_KEY' in config and config['SSH_KEY'].startswith('~'):
        config['SSH_KEY'] = str(Path(config['SSH_KEY']).expanduser())

    return config


def fetch_shopify_product(url: str) -> Optional[Dict]:
    """Fetch product data from Shopify JSON endpoint."""
    json_url = f"{url}.json"
    try:
        response = requests.get(json_url, timeout=10)
        response.raise_for_status()
        return response.json()
    except Exception as e:
        print(f"ERROR fetching {json_url}: {e}")
        return None


def scrape_kukirin_variants() -> Dict[str, List[Dict]]:
    """Scrape all Kukirin EU product variants grouped by model."""
    all_variants = {}

    print("Scraping Kukirin EU variants...")
    for model, url_path in MODEL_URLS.items():
        print(f"  Fetching {model}...")
        url = f"{BASE_URL}{url_path}"

        data = fetch_shopify_product(url)
        if not data or 'product' not in data:
            print(f"    WARNING: No data returned for {model}")
            continue

        product = data['product']
        variants = product.get('variants', [])
        images = {img['id']: img for img in product.get('images', [])}

        model_variants = []
        for variant in variants:
            # Skip variants without images
            image_id = variant.get('image_id')
            if not image_id or image_id not in images:
                continue

            image_src = images[image_id].get('src')
            if not image_src:
                continue

            variant_name = variant.get('title', '')
            if not variant_name:
                continue

            # For G2 Pro, determine 2023 vs 2024 based on version suffix
            actual_model = model
            if model.startswith('Kukirin G2 Pro'):
                if 'V3' in variant_name:
                    actual_model = 'Kukirin G2 Pro 2023'
                elif 'V4' in variant_name or 'VMP' in variant_name:
                    actual_model = 'Kukirin G2 Pro 2024'

            model_variants.append({
                'name': variant_name,
                'image_url': image_src,
                'model': actual_model,
                'sku': variant.get('sku', ''),
                'variant_id': variant.get('id', ''),
            })

        all_variants[model] = model_variants
        print(f"    Found {len(model_variants)} variants with images")

        # Rate limiting
        time.sleep(SHOPIFY_API_DELAY)

    return all_variants


def find_best_matches(products: List[Dict], variants: Dict[str, List[Dict]]) -> List[Dict]:
    """Find best variant matches for each product without images."""
    matches = []

    # Filter products without images
    products_no_image = [p for p in products if not p.get('has_image', False)]
    print(f"\nMatching {len(products_no_image)} products without images...")

    for product in products_no_image:
        product_model = product.get('model', '')
        if not product_model or product_model not in variants:
            continue

        best_score = 0.0
        best_variant = None

        # Try matching against variants for this model
        for variant in variants[product_model]:
            score = match_variant_to_product(variant['name'], product, product_model)
            if score > best_score:
                best_score = score
                best_variant = variant

        # Accept matches above threshold
        if best_score >= MATCH_THRESHOLD and best_variant:
            matches.append({
                'product': product,
                'variant': best_variant,
                'score': best_score,
            })

    # Sort by score descending
    matches.sort(key=lambda x: x['score'], reverse=True)

    return matches


def download_and_process_image(image_url: str, sku: str) -> Optional[Path]:
    """Download image from Shopify CDN, convert to JPG, save to temp directory."""
    TEMP_DIR.mkdir(exist_ok=True)

    # Download image
    try:
        response = requests.get(image_url, timeout=15, stream=True)
        response.raise_for_status()

        # Save to temp file
        temp_input = TEMP_DIR / f"{sku}-original"
        with open(temp_input, 'wb') as f:
            for chunk in response.iter_content(chunk_size=8192):
                f.write(chunk)

        # Convert to JPG
        output_path = TEMP_DIR / f"{sku}-scraped-1.jpg"
        img = Image.open(temp_input)

        # Convert to RGB if necessary
        if img.mode in ('RGBA', 'P', 'LA'):
            rgb_img = Image.new('RGB', img.size, (255, 255, 255))
            if img.mode == 'P':
                img = img.convert('RGBA')
            rgb_img.paste(img, mask=img.split()[-1] if img.mode in ('RGBA', 'LA') else None)
            img = rgb_img

        # Resize if larger than 1200px
        max_size = 1200
        if max(img.size) > max_size:
            img.thumbnail((max_size, max_size), Image.Resampling.LANCZOS)

        # Save as JPG
        img.save(output_path, 'JPEG', quality=85, optimize=True)

        # Clean up temp input
        temp_input.unlink()

        time.sleep(IMAGE_DOWNLOAD_DELAY)
        return output_path

    except Exception as e:
        print(f"    ERROR downloading/processing {image_url}: {e}")
        return None


def upload_image_via_scp(local_path: Path, ssh_config: Dict) -> Optional[str]:
    """Upload image to Hostinger via SCP."""
    remote_dir = f"{ssh_config['WP_PATH']}/wp-content/uploads/parts-images"
    remote_path = f"{remote_dir}/{local_path.name}"

    # Build SCP command
    scp_cmd = [
        'scp',
        '-P', ssh_config['SSH_PORT'],
        '-i', ssh_config['SSH_KEY'],
        '-o', 'StrictHostKeyChecking=no',
        str(local_path),
        f"{ssh_config['SSH_USER']}@{ssh_config['SSH_HOST']}:{remote_path}"
    ]

    try:
        subprocess.run(scp_cmd, check=True, capture_output=True)
        # Return web-accessible URL
        url = f"https://dev.trotibike.ro/wp-content/uploads/parts-images/{local_path.name}"
        return url
    except subprocess.CalledProcessError as e:
        print(f"    ERROR uploading via SCP: {e.stderr.decode()}")
        return None


def assign_image_via_wpcli(image_url: str, product_id: int, product_name: str, ssh_config: Dict) -> bool:
    """Assign image to WooCommerce product via WP-CLI over SSH."""
    # Import media AND set as featured image (_thumbnail_id)
    # --post_id only sets post_parent, so we also need wp post meta update
    escaped_name = product_name.replace("'", "'\\''")
    wp_cmd = (
        f"cd {ssh_config['WP_PATH']} && "
        f"ATTACH_ID=$(wp media import {image_url} --title='{escaped_name}' --alt='{escaped_name}' --post_id={product_id} --porcelain) && "
        f"wp post meta update {product_id} _thumbnail_id $ATTACH_ID --quiet && "
        f"echo $ATTACH_ID"
    )
    ssh_cmd = [
        'ssh',
        '-p', ssh_config['SSH_PORT'],
        '-i', ssh_config['SSH_KEY'],
        '-o', 'StrictHostKeyChecking=no',
        f"{ssh_config['SSH_USER']}@{ssh_config['SSH_HOST']}",
        wp_cmd
    ]

    try:
        result = subprocess.run(ssh_cmd, check=True, capture_output=True, text=True)
        attachment_id = result.stdout.strip()
        if attachment_id.isdigit():
            return True
        else:
            print(f"    WARNING: Unexpected WP-CLI output: {result.stdout}")
            return False
    except subprocess.CalledProcessError as e:
        print(f"    ERROR running WP-CLI: {e.stderr}")
        return False


def backup_database(ssh_config: Dict) -> bool:
    """Backup database before applying changes."""
    print("Backing up database...")
    ssh_cmd = [
        'ssh',
        '-p', ssh_config['SSH_PORT'],
        '-i', ssh_config['SSH_KEY'],
        '-o', 'StrictHostKeyChecking=no',
        f"{ssh_config['SSH_USER']}@{ssh_config['SSH_HOST']}",
        'bash ~/backups/backup.sh --db-only'
    ]

    try:
        subprocess.run(ssh_cmd, check=True, capture_output=True)
        print("Database backup completed successfully\n")
        return True
    except subprocess.CalledProcessError as e:
        print(f"ERROR backing up database: {e.stderr.decode()}")
        return False


def generate_report(matches: List[Dict], products: List[Dict], variants: Dict[str, List[Dict]]) -> str:
    """Generate detailed report of matches and misses."""
    lines = []
    lines.append("=" * 80)
    lines.append("KUKIRIN EU IMAGE SCRAPER REPORT")
    lines.append("=" * 80)
    lines.append("")

    # Statistics
    total_products = len([p for p in products if not p.get('has_image', False)])
    total_variants = sum(len(v) for v in variants.values())
    matched_products = len(matches)

    lines.append(f"Total products without images: {total_products}")
    lines.append(f"Total Kukirin EU variants scraped: {total_variants}")
    lines.append(f"Successfully matched products: {matched_products}")
    lines.append(f"Match rate: {matched_products/total_products*100:.1f}%")
    lines.append("")

    # Matches by model
    lines.append("MATCHES BY MODEL:")
    lines.append("-" * 80)
    model_counts = {}
    for match in matches:
        model = match['product']['model']
        model_counts[model] = model_counts.get(model, 0) + 1

    for model in sorted(model_counts.keys()):
        lines.append(f"  {model}: {model_counts[model]} matches")
    lines.append("")

    # Detailed matches
    lines.append("DETAILED MATCHES (sorted by score):")
    lines.append("-" * 80)
    for i, match in enumerate(matches, 1):
        product = match['product']
        variant = match['variant']
        score = match['score']

        lines.append(f"\n{i}. Score: {score:.3f}")
        lines.append(f"   Product ID: {product['id']}")
        lines.append(f"   SKU: {product['sku']}")
        lines.append(f"   Model: {product['model']}")
        lines.append(f"   Romanian: {product['name']}")
        lines.append(f"   English: {product.get('short_desc', 'N/A')}")
        lines.append(f"   Matched Variant: {variant['name']}")
        lines.append(f"   Image: {variant['image_url'][:80]}...")

    # Products without matches
    lines.append("\n\n")
    lines.append("PRODUCTS WITHOUT MATCHES:")
    lines.append("-" * 80)
    matched_product_ids = {m['product']['id'] for m in matches}
    unmatched = [p for p in products if not p.get('has_image', False) and p['id'] not in matched_product_ids]

    for product in unmatched[:50]:  # Limit to first 50
        lines.append(f"  ID {product['id']} - {product['model']} - {product['name']}")

    if len(unmatched) > 50:
        lines.append(f"  ... and {len(unmatched) - 50} more")

    lines.append("")
    lines.append("=" * 80)

    return "\n".join(lines)


def main():
    """Main script execution."""
    # Parse arguments
    apply_mode = '--apply' in sys.argv

    print("=" * 80)
    print("KUKIRIN EU IMAGE SCRAPER")
    print("=" * 80)
    print(f"Mode: {'APPLY (will download and assign images)' if apply_mode else 'DRY-RUN (no changes)'}")
    print("")

    # Load products
    print("Loading products...")
    products = load_products()
    products_no_image = [p for p in products if not p.get('has_image', False)]
    print(f"Loaded {len(products)} products ({len(products_no_image)} without images)\n")

    # Load SSH config
    ssh_config = load_ssh_config()
    print(f"SSH config loaded: {ssh_config['SSH_USER']}@{ssh_config['SSH_HOST']}\n")

    # Scrape Kukirin EU variants
    variants = scrape_kukirin_variants()
    total_variants = sum(len(v) for v in variants.values())
    print(f"\nScraped {total_variants} total variants with images\n")

    # Find matches
    matches = find_best_matches(products, variants)
    print(f"\nFound {len(matches)} matches above threshold {MATCH_THRESHOLD}\n")

    # Generate report
    report = generate_report(matches, products, variants)

    # Save report
    REPORT_FILE.parent.mkdir(parents=True, exist_ok=True)
    with open(REPORT_FILE, 'w', encoding='utf-8') as f:
        f.write(report)
    print(f"Report saved to: {REPORT_FILE}\n")

    # Print summary
    print(report.split("DETAILED MATCHES")[0])

    # Apply mode
    if apply_mode:
        print("\n" + "=" * 80)
        print("APPLYING CHANGES")
        print("=" * 80 + "\n")

        # Backup database
        if not backup_database(ssh_config):
            print("ERROR: Database backup failed. Aborting.")
            sys.exit(1)

        # Query live which products already have thumbnails (from previous runs)
        print("Checking which products already have images...")
        products_with_images = set()
        try:
            check_cmd = [
                'ssh', '-p', ssh_config['SSH_PORT'], '-i', ssh_config['SSH_KEY'],
                '-o', 'StrictHostKeyChecking=no',
                f"{ssh_config['SSH_USER']}@{ssh_config['SSH_HOST']}",
                f"cd {ssh_config['WP_PATH']} && wp eval 'global $wpdb; $ids = $wpdb->get_col(\"SELECT pm.post_id FROM \" . $wpdb->postmeta . \" pm WHERE pm.meta_key = \\\"_thumbnail_id\\\" AND pm.meta_value > 0\"); echo implode(\",\", $ids);'"
            ]
            result = subprocess.run(check_cmd, capture_output=True, text=True)
            if result.returncode == 0 and result.stdout.strip():
                products_with_images = {int(x) for x in result.stdout.strip().split(',') if x.isdigit()}
        except Exception as e:
            print(f"  WARNING: Could not check existing images: {e}")

        # Filter out already-processed products
        remaining_matches = [m for m in matches if m['product']['id'] not in products_with_images]
        print(f"  {len(products_with_images)} products already have images")
        print(f"  {len(remaining_matches)} products still need images\n")

        # Process each match
        success_count = 0
        fail_count = 0
        skipped_count = len(matches) - len(remaining_matches)

        for i, match in enumerate(remaining_matches, 1):
            product = match['product']
            variant = match['variant']

            print(f"\n[{i}/{len(remaining_matches)}] Processing product {product['id']} - {product['sku']}")
            print(f"  Variant: {variant['name']}")
            print(f"  Score: {match['score']:.3f}")

            # Download and process image
            print(f"  Downloading image...")
            local_path = download_and_process_image(variant['image_url'], product['sku'])
            if not local_path:
                print(f"  FAILED: Could not download image")
                fail_count += 1
                continue

            # Upload via SCP
            print(f"  Uploading to Hostinger...")
            image_url = upload_image_via_scp(local_path, ssh_config)
            if not image_url:
                print(f"  FAILED: Could not upload image")
                fail_count += 1
                continue

            # Assign via WP-CLI
            print(f"  Assigning to product...")
            if assign_image_via_wpcli(image_url, product['id'], product['name'], ssh_config):
                print(f"  SUCCESS!")
                success_count += 1
                # Clean up local file
                local_path.unlink()
            else:
                print(f"  FAILED: Could not assign image")
                fail_count += 1

        # Final summary
        print("\n" + "=" * 80)
        print("FINAL RESULTS")
        print("=" * 80)
        print(f"Skipped (already have images): {skipped_count}")
        print(f"Successfully processed: {success_count}")
        print(f"Failed: {fail_count}")
        print(f"Total matches: {len(matches)}")
        print("")
    else:
        print("\nDry-run complete. Run with --apply to download and assign images.")

    print(f"\nFull report available at: {REPORT_FILE}")


if __name__ == '__main__':
    main()

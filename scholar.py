import sys
import json
import time
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from webdriver_manager.chrome import ChromeDriverManager

url = sys.argv[1]

options = Options()

# ❌ JANGAN headless (WAJIB untuk Scholar)
options.add_argument("--start-maximized")

# ✅ Gunakan Chrome profile asli
options.add_argument(r"--user-data-dir=C:\selenium-profile")
options.add_argument("--profile-directory=Default")

# Anti-detection
options.add_experimental_option("excludeSwitches", ["enable-automation"])
options.add_experimental_option("useAutomationExtension", False)

service = Service(ChromeDriverManager().install())
driver = webdriver.Chrome(service=service, options=options)

# Hilangkan navigator.webdriver
driver.execute_cdp_cmd(
    "Page.addScriptToEvaluateOnNewDocument",
    {
        "source": """
        Object.defineProperty(navigator, 'webdriver', {
            get: () => undefined
        });
        """
    }
)

# =========================
# BUKA HALAMAN
# =========================
driver.get(url)

# 🕒 SLEEP PERTAMA (penting)
time.sleep(5)

wait = WebDriverWait(driver, 30)

data = {
    "Judul": "-",
    "Pengarang": "-",
    "Tahun": "-",
    "Jurnal": "-",
    "Total Sitasi": "-",
    "Deskripsi": "-"
}

# =========================
# JUDUL
# =========================
try:
    title = wait.until(
        EC.presence_of_element_located((By.ID, "gsc_vcd_title"))
    )
    time.sleep(2)  # 🕒 sleep setelah judul muncul
    data["Judul"] = title.text
except:
    pass

# =========================
# META DATA
# =========================
try:
    rows = driver.find_elements(By.CSS_SELECTOR, "div.gs_scl")
    time.sleep(2)  # 🕒 beri jeda sebelum parsing

    for row in rows:
        label = row.find_element(By.CLASS_NAME, "gsc_vcd_field").text.lower()
        value = row.find_element(By.CLASS_NAME, "gsc_vcd_value").text

        if "author" in label:
            data["Pengarang"] = value
        elif "publication date" in label:
            data["Tahun"] = value
        elif "journal" in label or "conference" in label:
            data["Jurnal"] = value
        elif "total citations" in label:
            data["Total Sitasi"] = value
except:
    pass

# =========================
# DESKRIPSI
# =========================
try:
    time.sleep(2)  # 🕒 sebelum ambil abstrak
    data["Deskripsi"] = driver.find_element(
        By.ID, "gsc_vcd_abstract"
    ).text
except:
    pass

# 🕒 SLEEP AKHIR (opsional, lebih aman)
time.sleep(2)

driver.quit()

print(json.dumps(data, ensure_ascii=False))

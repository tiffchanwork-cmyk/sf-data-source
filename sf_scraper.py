import requests
from bs4 import BeautifulSoup
import json
import re

# --- 設定 ---
URLS = {
    'station': 'https://htm.sf-express.com/hk/tc/dynamic_function/S.F.Network/SF_store_address/',
    'locker': 'https://htm.sf-express.com/hk/tc/dynamic_function/S.F.Network/SF_Locker/'
}
FILES = {'station': 'sf-stores.json', 'locker': 'sf-lockers.json'}

# 地區過濾黑名單
DISTRICT_BLACKLIST = ["地區", "網點", "快遞", "服務", "熱線", "地址", "電話", "時間"]

def clean_text(text):
    if not text: return ""
    # 移除換行、多餘空白、全形空格
    text = text.replace('\u3000', ' ').replace('\xa0', ' ')
    return re.sub(r'\s+', ' ', text).strip()

def fetch_and_parse(url, type_key):
    print(f"[{type_key}] 正在連線抓取...")
    try:
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
        response = requests.get(url, headers=headers, timeout=30)
        response.encoding = 'utf-8'
        soup = BeautifulSoup(response.text, 'html.parser')
        
        results = []
        seen_codes = set() 
        current_district = "其他地區" 
        
        rows = soup.find_all('tr')
        for row in rows:
            cols = row.find_all('td')
            if len(cols) < 2: continue

            # --- 1. 智慧識別地區 ---
            # 檢查第一欄位是否為有效地區名
            raw_dist = clean_text(cols[0].get_text())
            if raw_dist and 2 <= len(raw_dist) <= 5: # 香港地區名長度通常在此區間
                # 排除黑名單關鍵字且不全是英數字 (避免點碼誤入)
                if not any(word in raw_dist for word in DISTRICT_BLACKLIST):
                    if not re.match(r'^[A-Z0-9]+$', raw_dist):
                        current_district = raw_dist

            # --- 2. 智慧偵測點碼 ---
            row_text = row.get_text()
            # 偵測 H852... 或 852... 格式的點碼
            code_match = re.search(r'(H?852[A-Z0-9]{3,10})', row_text)
            
            if code_match:
                code = code_match.group(1)
                
                # 跳過重複抓取與澳門代碼 (853)
                if code in seen_codes or code.startswith(('853', 'H853')): 
                    continue

                # --- 3. 智慧提取地址 ---
                address = ""
                # 優先找包含區域特徵的欄位
                for c in cols:
                    t = clean_text(c.get_text())
                    if any(key in t for key in ["香港", "九龍", "新界", "路", "街", "邨", "中心"]):
                        if t != code and len(t) > 5:
                            address = t
                            break
                
                # 備案：如果沒抓到特徵地址，取最後一欄
                if not address: 
                    address = clean_text(cols[-1].get_text())

                # 二次檢查：過濾澳門關鍵字
                if "澳門" in current_district or "澳門" in address:
                    continue

                results.append({
                    "code": code,
                    "address": address,
                    "district": current_district
                })
                seen_codes.add(code)

        print(f"✅ [{type_key}] 成功提取: {len(results)} 筆")
        return results
    except Exception as e:
        print(f"❌ 錯誤: {e}")
        return []

def main():
    for k, v in URLS.items():
        data = fetch_and_parse(v, k)
        if data:
            with open(FILES[k], 'w', encoding='utf-8') as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
    print("\n=== ✨ 抓取並過濾完成！ ===")

if __name__ == "__main__":
    main()

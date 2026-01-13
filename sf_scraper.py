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

# 垃圾關鍵字
DISTRICT_IGNORE = ["地區", "網點", "快遞", "服務", "熱線", "地址", "電話", "時間", "Code", "Address"]

def clean_text(text):
    if not text: return ""
    text = text.replace('\u3000', ' ').replace('\xa0', ' ').replace('^', '')
    return re.sub(r'\s+', ' ', text).strip()

# --- 專用邏輯 1：抓取順豐站 (處理短代碼 852M) ---
def parse_station(soup):
    results = []
    current_district = "其他地區"
    seen_codes = set()
    
    rows = soup.find_all('tr')
    for row in rows:
        cols = row.find_all('td')
        if len(cols) < 2: continue

        # 提取文字
        texts = [clean_text(c.get_text()) for c in cols]
        
        # 1. 判斷地區 (通常在第1欄)
        if texts[0] and len(texts[0]) < 10 and not any(k in texts[0] for k in DISTRICT_IGNORE):
             # 排除純代碼誤判
            if not re.match(r'^[A-Z0-9]+$', texts[0]):
                current_district = texts[0]

        # 2. 尋找代碼 (允許短代碼 {1,15})
        code = ""
        address = ""
        
        # 掃描整行找代碼
        for i, text in enumerate(texts):
            # 順豐站代碼特徵：852開頭 (非H開頭) 或 H852
            match = re.search(r'((?:H?852)[A-Z0-9]{1,10})', text)
            if match:
                code = match.group(1)
                # 地址通常在代碼的「下一欄」或者「同一欄的其他字」
                # 順豐站表格通常是：地區 | 代碼 | 簡稱 | 地址...
                if i + 2 < len(texts): # 優先抓第 3 或 4 欄
                    address = texts[i+2] 
                    if len(address) < 5: address = texts[i+1] # 回退一欄
                elif i + 1 < len(texts):
                    address = texts[i+1]
                break
        
        if code and address:
            if isValid(code, address, current_district, seen_codes):
                 # 清洗地址
                address = address.replace(code, '').strip()
                results.append({"code": code, "address": address, "district": current_district})
                seen_codes.add(code)
                
    return results

# --- 專用邏輯 2：抓取智能櫃 (處理複雜排版 & 時間誤判) ---
def parse_locker(soup):
    results = []
    current_district = "其他地區"
    seen_codes = set()
    
    rows = soup.find_all('tr')
    for row in rows:
        cols = row.find_all('td')
        # 智能櫃表格通常較寬，若少於3欄通常是標題或分隔
        if len(cols) < 3: 
            # 嘗試抓取單獨一行的地區標題
            if len(cols) == 1:
                text = clean_text(cols[0].get_text())
                if text and len(text) < 10 and "區" in text:
                    current_district = text
            continue

        texts = [clean_text(c.get_text()) for c in cols]

        # 1. 智能櫃的地區有時在第1欄
        if texts[0] and len(texts[0]) < 10 and not re.search(r'[0-9]', texts[0]):
             if not any(k in texts[0] for k in DISTRICT_IGNORE):
                current_district = texts[0]

        # 2. 尋找代碼 (智能櫃通常是 H852 開頭，長度較長)
        code = ""
        address = ""
        
        # 強制鎖定：通常第2欄是代碼，第3欄是地址 (Index 1, 2)
        # 這是為了避免抓到最後一欄的「營業時間」
        potential_code = texts[1]
        
        # 檢查是否為代碼
        if re.search(r'(H?852[A-Z0-9]{3,15})', potential_code):
            code = potential_code
            if len(texts) > 2:
                address = texts[2] # 強制抓取代碼右邊那一欄
        
        # 如果第2欄不是代碼，嘗試全行掃描 (備用)
        if not code:
             for i, text in enumerate(texts):
                match = re.search(r'(H?852[A-Z0-9]{3,15})', text)
                if match:
                    code = match.group(1)
                    if i + 1 < len(texts): address = texts[i+1]
                    break

        if code and address:
            if isValid(code, address, current_district, seen_codes):
                # 清洗地址 (移除開頭標點)
                address = address.replace(code, '').lstrip('-,. ').strip()
                # 排除時間格式誤判
                if "營業時間" in address or "24小時" in address: continue
                
                results.append({"code": code, "address": address, "district": current_district})
                seen_codes.add(code)
                
    return results

# --- 通用檢查邏輯 ---
def isValid(code, address, district, seen_codes):
    # 1. 過濾澳門
    if code.startswith(('853', 'H853')): return False
    if "澳門" in district or "氹仔" in district: return False
    if "澳門" in address: return False
    
    # 2. 過濾重複
    if code in seen_codes: return False
    
    # 3. 過濾無效地址
    if len(address) < 5: return False
    
    return True

def main():
    print("=== 開始執行 (V5 雙軌制：分開處理順豐站與智能櫃) ===")
    
    # 1. 抓取順豐站
    print("[station] 連線中...")
    resp = requests.get(URLS['station'])
    resp.encoding = 'utf-8'
    stations = parse_station(BeautifulSoup(resp.text, 'html.parser'))
    print(f"✅ [station] 成功: {len(stations)} 筆")
    with open(FILES['station'], 'w', encoding='utf-8') as f:
        json.dump(stations, f, ensure_ascii=False, indent=2)

    # 2. 抓取智能櫃
    print("[locker] 連線中...")
    resp = requests.get(URLS['locker'])
    resp.encoding = 'utf-8'
    lockers = parse_locker(BeautifulSoup(resp.text, 'html.parser'))
    print(f"✅ [locker] 成功: {len(lockers)} 筆")
    with open(FILES['locker'], 'w', encoding='utf-8') as f:
        json.dump(lockers, f, ensure_ascii=False, indent=2)

    print("\n=== 完成！請執行 git push 更新資料 ===")

if __name__ == "__main__":
    main()
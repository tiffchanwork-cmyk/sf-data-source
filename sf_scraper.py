import requests
from bs4 import BeautifulSoup
import json
import re
import os

# --- 設定 ---
URLS = {
    'station': 'https://htm.sf-express.com/hk/tc/dynamic_function/S.F.Network/SF_store_address/',
    'locker': 'https://htm.sf-express.com/hk/tc/dynamic_function/S.F.Network/SF_Locker/'
}
FILES = {'station': 'sf-stores.json', 'locker': 'sf-lockers.json'}

# 垃圾關鍵字 (遇到這些就不更新地區)
DISTRICT_IGNORE = ["地區", "網點", "快遞", "服務", "熱線", "地址", "電話", "時間", "Code", "Address"]

def clean_text(text):
    if not text: return ""
    text = text.replace('\u3000', ' ').replace('\xa0', ' ').replace('^', '')
    return re.sub(r'\s+', ' ', text).strip()

def fetch_and_parse(url, type_key):
    print(f"[{type_key}] 正在連線抓取 (V4 智慧欄位版)...")
    try:
        headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'}
        response = requests.get(url, headers=headers, timeout=30)
        response.encoding = 'utf-8'
        soup = BeautifulSoup(response.text, 'html.parser')
        
        results = []
        seen_codes = set() 
        current_district = "其他地區" 
        
        rows = soup.find_all('tr')
        print(f"[{type_key}] 網頁共找到 {len(rows)} 行，開始分析...")

        for row in rows:
            cols = row.find_all('td')
            if not cols: continue
            
            # 取出所有欄位的文字 (預處理)
            texts = [clean_text(c.get_text()) for c in cols]
            
            # --- 邏輯 A: 尋找地區 (District) ---
            # 通常在第1欄。如果這一行只有一個欄位，或者第1欄不是代碼，很有可能是地區標題
            first_text = texts[0]
            # 判斷是否為代碼 (852開頭)
            is_code = re.search(r'H?852[A-Z0-9]{1,10}', first_text)
            
            if first_text and len(first_text) > 1 and not is_code:
                if not any(k in first_text for k in DISTRICT_IGNORE):
                    # 這是地區名稱
                    current_district = first_text

            # --- 邏輯 B: 尋找代碼與地址 ---
            code = ""
            address = ""

            # 掃描這一行的所有欄位，找「像代碼」的格子
            for i, text in enumerate(texts):
                # 順豐代碼特徵：H852 或 852 開頭，後面接英數
                match = re.search(r'(H?852[A-Z0-9]{1,10})', text)
                if match:
                    code = match.group(1)
                    
                    # 地址通常在代碼的「下一欄」
                    if i + 1 < len(texts):
                        address = texts[i+1]
                    break # 找到代碼就停止掃描這一行

            # --- 邏輯 C: 驗證與存檔 ---
            if code and address:
                # 過濾澳門
                if code.startswith(('853', 'H853')) or "澳門" in address or "澳門" in current_district:
                    continue
                # 過濾重複
                if code in seen_codes:
                    continue
                
                # 最終清洗地址 (有些地址會包含代碼本身，把它去掉)
                address = address.replace(code, '').strip()
                address = address.lstrip('-,. ') # 去掉開頭標點

                # 排除誤判 (如果地址太短或是時間格式)
                if len(address) < 5 or "營業時間" in address or "24小時" == address:
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
    print("\n=== ✨ V4 執行完成！請務必執行 git push 更新資料庫 ===")

if __name__ == "__main__":
    main()
import requests
from bs4 import BeautifulSoup
import json
import re
import os

# --- 設定目標網址 ---
URLS = {
    'station': 'https://htm.sf-express.com/hk/tc/dynamic_function/S.F.Network/SF_store_address/',
    'locker': 'https://htm.sf-express.com/hk/tc/dynamic_function/S.F.Network/SF_Locker/'
}

# --- 設定輸出檔名 ---
FILES = {
    'station': 'sf-stores.json',
    'locker': 'sf-lockers.json'
}

# 地區過濾黑名單
DISTRICT_BLACKLIST = ["地區", "網點", "快遞", "服務", "熱線", "地址", "電話", "時間"]

def clean_text(text):
    """清理文字：移除換行、多餘空白、全形空格"""
    if not text: return ""
    text = text.replace('\u3000', ' ').replace('\xa0', ' ')
    # 移除地址中可能出現的 ^ 符號 (順豐網頁特產)
    text = text.replace('^', '')
    return re.sub(r'\s+', ' ', text).strip()

def fetch_and_parse(url, type_key):
    print(f"[{type_key}] 正在連線順豐官網抓取中...")
    
    try:
        headers = {
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        }
        response = requests.get(url, headers=headers, timeout=30)
        response.encoding = 'utf-8'

        if response.status_code != 200:
            print(f"❌ [{type_key}] 連線失敗 (Code: {response.status_code})")
            return []

        soup = BeautifulSoup(response.text, 'html.parser')
        results = []
        seen_codes = set() 
        current_district = "其他地區" 

        rows = soup.find_all('tr')
        print(f"[{type_key}] 網頁共找到 {len(rows)} 行，開始分析...")

        for row in rows:
            cols = row.find_all('td')
            
            # 順豐表格標準結構：[0]地區 [1]點碼 [2]地址 [3]時間...
            # 如果欄位少於 3 個，肯定不是有效資料
            if len(cols) < 3:
                continue

            # --- 1. 提取原始資料 (強制鎖定欄位) ---
            raw_district_text = clean_text(cols[0].get_text())
            code_text = clean_text(cols[1].get_text())
            address_text = clean_text(cols[2].get_text()) # 強制讀取第3欄，絕不讀最後一欄

            # --- 2. 處理地區 (District) ---
            # 如果第一欄有字，更新當前地區
            if raw_district_text:
                # 排除標題列 (例如含有 "地區" 兩字的)
                if not any(word in raw_district_text for word in DISTRICT_BLACKLIST):
                    # 排除純代碼誤植為地區的情況
                    if not re.match(r'^[A-Z0-9]+$', raw_district_text):
                        current_district = raw_district_text

            # --- 3. 處理代碼 (Code) ---
            # 使用正則表達式提取乾淨的代碼 (保留 852M 這種短代碼)
            code_match = re.search(r'(H?852[A-Z0-9]{1,10})', code_text)
            
            if not code_match:
                continue # 找不到有效代碼就跳過

            code = code_match.group(1)

            # --- 4. 過濾邏輯 ---
            # A. 排除澳門 (853開頭)
            if code.startswith(('853', 'H853')):
                continue
            
            # B. 排除重複
            if code in seen_codes:
                continue

            # C. 排除地區或地址中的澳門關鍵字
            if "澳門" in current_district or "氹仔" in current_district or "澳門" in address_text:
                continue

            # --- 5. 地址最終清洗 ---
            # 有些地址欄位會包含代碼本身 (例如 "852M 上環...")，把它去掉
            if address_text.startswith(code):
                address_text = address_text.replace(code, '', 1).strip()
            
            # 去除開頭的標點符號
            address_text = address_text.lstrip('-,. ')

            results.append({
                "code": code,
                "address": address_text,
                "district": current_district
            })
            seen_codes.add(code)

        print(f"✅ [{type_key}] 成功提取: {len(results)} 筆 (已排除時間誤判)")
        return results

    except Exception as e:
        print(f"❌ [{type_key}] 發生錯誤: {e}")
        return []

def main():
    print("=== 開始執行順豐地址抓取腳本 (V3 強制鎖定地址欄位) ===")
    
    # 1. 抓取順豐站
    stations = fetch_and_parse(URLS['station'], 'station')
    if stations:
        with open(FILES['station'], 'w', encoding='utf-8') as f:
            json.dump(stations, f, ensure_ascii=False, indent=2)
        print(f"💾 已儲存: {FILES['station']}")

    # 2. 抓取智能櫃
    lockers = fetch_and_parse(URLS['locker'], 'locker')
    if lockers:
        with open(FILES['locker'], 'w', encoding='utf-8') as f:
            json.dump(lockers, f, ensure_ascii=False, indent=2)
        print(f"💾 已儲存: {FILES['locker']}")

    print("\n=== 完成！檔案已生成 ===")
    print("請執行以下指令將修正後的資料推送到 GitHub：")
    print("1. python sf_scraper.py")
    print("2. git add .")
    print("3. git commit -m \"Fix address showing time issue\"")
    print("4. git push")

if __name__ == "__main__":
    main()
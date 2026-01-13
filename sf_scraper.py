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

# 地區過濾黑名單 (遇到這些關鍵字就不是有效地區)
DISTRICT_BLACKLIST = ["地區", "網點", "快遞", "服務", "熱線", "地址", "電話", "時間"]

def clean_text(text):
    """清理文字：移除換行、多餘空白、全形空格"""
    if not text: return ""
    text = text.replace('\u3000', ' ').replace('\xa0', ' ')
    return re.sub(r'\s+', ' ', text).strip()

def fetch_and_parse(url, type_key):
    print(f"[{type_key}] 正在連線順豐官網抓取中...")
    
    try:
        # 偽裝成瀏覽器，避免被擋
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

        # 抓取所有表格列
        rows = soup.find_all('tr')
        print(f"[{type_key}] 網頁共找到 {len(rows)} 行，開始智慧過濾...")

        for row in rows:
            cols = row.find_all('td')
            
            # 如果欄位太少，通常不是資料行，跳過
            if len(cols) < 2:
                continue

            # 提取文字
            raw_district = clean_text(cols[0].get_text())
            code = clean_text(cols[1].get_text())
            
            # 嘗試提取地址 (地址有時在第3欄，有時在最後一欄)
            address = ""
            if len(cols) >= 3:
                address = clean_text(cols[2].get_text())
            else:
                address = clean_text(cols[-1].get_text()) # 備案

            # --- [過濾邏輯 1]：處理地區 (District) ---
            # 如果第一欄有字，且不是「地區」、「快遞服務」等垃圾字，就更新當前地區
            if raw_district and 2 <= len(raw_district) <= 8: # 放寬地區長度限制
                # 【修正點】這裡原本寫錯成 raw_dist，已更正為 raw_district
                if not any(word in raw_district for word in DISTRICT_BLACKLIST):
                    # 排除純數字或英文的"地區" (通常是誤判)
                    if not re.match(r'^[A-Z0-9]+$', raw_district):
                        current_district = raw_district
            
            # --- [過濾邏輯 2]：嚴格檢查代碼 (Code) ---
            # 順豐代碼格式通常是英數混合 (如 852TAL, 852M)
            # 正規表達式：只允許 A-Z, a-z, 0-9
            # 長度改為 {1,15}，允許像 852M 這種短代碼
            code_match = re.search(r'(H?852[A-Z0-9]{1,10})', row.get_text()) 
            
            if code_match:
                code = code_match.group(1)
            elif not re.match(r'^[A-Za-z0-9]{1,15}$', code):
                continue # 如果既沒有正則匹配到，原始欄位也不符合格式，就跳過

            # --- [過濾邏輯 3]：封殺澳門資料 ---
            # 澳門代碼特徵：以 853 或 H853 開頭
            if code.startswith('853') or code.startswith('H853'):
                continue
            
            # 地區過濾
            if "澳門" in current_district or "氹仔" in current_district or "黑沙環" in current_district:
                continue
                
            # 地址過濾 (雙重保險)
            if "澳門" in address:
                continue
            
            # 避免重複抓取
            if code in seen_codes:
                continue

            # --- 通過所有檢查，加入結果 ---
            item = {
                "code": code,
                "address": address,
                "district": current_district
            }
            results.append(item)
            seen_codes.add(code)

        print(f"✅ [{type_key}] 過濾完畢，成功提取有效資料: {len(results)} 筆")
        return results

    except Exception as e:
        print(f"❌ [{type_key}] 發生錯誤: {e}")
        return []

def main():
    print("=== 開始執行順豐地址抓取腳本 (V2 修正變數名稱) ===")
    
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
    print("1. sf-stores.json (包含短代碼站點如 852M)")
    print("2. sf-lockers.json")
    print("請將這兩個檔案上傳覆蓋到 WordPress 外掛資料夾，並同步到 GitHub。")

if __name__ == "__main__":
    main()
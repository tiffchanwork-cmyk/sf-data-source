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
        current_district = "其他地區" 

        # 抓取所有表格列
        rows = soup.find_all('tr')
        print(f"[{type_key}] 網頁共找到 {len(rows)} 行，開始智慧過濾...")

        for row in rows:
            cols = row.find_all('td')
            
            # 如果欄位太少，通常不是資料行，跳過
            if len(cols) < 3:
                continue

            # 提取文字
            raw_district = clean_text(cols[0].get_text())
            code = clean_text(cols[1].get_text())
            address = clean_text(cols[2].get_text())

            # --- [過濾邏輯 1]：處理地區 (District) ---
            # 如果第一欄有字，且不是「地區」、「快遞服務」等垃圾字，就更新當前地區
            # 有些垃圾資料的地區欄位會很長 (例如 "快遞服務 倉儲...")，我們只接受 10 字以內的地區名
            if raw_district:
                if len(raw_district) < 10 and "地區" not in raw_district and "快遞" not in raw_district:
                    current_district = raw_district
            
            # --- [過濾邏輯 2]：嚴格檢查代碼 (Code) ---
            # 順豐代碼格式通常是英數混合 (如 852TAL, H852...)
            # 正規表達式：只允許 A-Z, a-z, 0-9，且長度在 3 到 15 之間
            # 這樣可以殺掉所有中文垃圾 (如 "星期六", "解決方案...", "網點代碼")
            if not re.match(r'^[A-Za-z0-9]{3,15}$', code):
                continue

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

            # --- 通過所有檢查，加入結果 ---
            item = {
                "code": code,
                "address": address,
                "district": current_district
            }
            results.append(item)

        print(f"✅ [{type_key}] 過濾完畢，成功提取有效資料: {len(results)} 筆")
        return results

    except Exception as e:
        print(f"❌ [{type_key}] 發生錯誤: {e}")
        return []

def main():
    print("=== 開始執行順豐地址抓取腳本 (已啟用澳門及垃圾資料過濾) ===")
    
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
    print("1. sf-stores.json (無澳門、無垃圾資料)")
    print("2. sf-lockers.json (無澳門、無垃圾資料)")
    print("請將這兩個檔案上傳覆蓋到 WordPress 外掛資料夾。")

if __name__ == "__main__":
    main()

            # --- 執行腳本 (一鍵提取) ---
            #以後您想更新地址時，只需要做這一步：
            #在 VS Code 下方的終端機輸入：
            #python sf_scraper.py
            

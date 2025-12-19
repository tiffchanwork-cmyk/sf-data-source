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

def clean_text(text):
    if not text: return ""
    return re.sub(r'\s+', ' ', text.replace('\u3000', ' ').replace('\xa0', ' ')).strip()

def fetch_and_parse(url, type_key):
    print(f"[{type_key}] 正在抓取...")
    try:
        headers = {'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'}
        response = requests.get(url, headers=headers, timeout=30)
        response.encoding = 'utf-8'
        soup = BeautifulSoup(response.text, 'html.parser')
        
        results = []
        seen_codes = set() # 防止重複抓取
        current_district = "其他地區" 
        
        rows = soup.find_all('tr')
        for row in rows:
            cols = row.find_all('td')
            if len(cols) < 2: continue

            # 嘗試從所有欄位中尋找「點碼」特徵 (H852... 或 852...)
            row_text = row.get_text()
            # 尋找符合順豐點碼格式的字串
            code_match = re.search(r'(H?852[A-Z0-9]+)', row_text)
            
            if code_match:
                code = code_match.group(1)
                
                # 如果這組點碼抓過了，就跳過 (解決 HTML 嵌套重複問題)
                if code in seen_codes: continue

                # 提取地區：如果第一欄有字，更新地區
                raw_dist = clean_text(cols[0].get_text())
                if raw_dist and len(raw_dist) < 6 and "地區" not in raw_dist:
                    current_district = raw_dist

                # 提取地址：通常是在點碼後面的那個長字串
                address = ""
                for c in cols:
                    t = clean_text(c.get_text())
                    if "香港" in t or "九龍" in t or "新界" in t or "路" in t or "街" in t:
                        if t != code: # 確保不是點碼本身
                            address = t
                            break
                
                # 如果沒抓到地址，就取最後一欄
                if not address: address = clean_text(cols[-1].get_text())

                # 過濾澳門
                if code.startswith('853') or code.startswith('H853') or "澳門" in current_district:
                    continue

                item = {
                    "code": code,
                    "address": address,
                    "district": current_district
                }
                results.append(item)
                seen_codes.add(code)

        print(f"✅ [{type_key}] 成功提取: {len(results)} 筆")
        return results
    except Exception as e:
        print(f"❌ 錯誤: {e}"); return []

def main():
    for k, v in URLS.items():
        data = fetch_and_parse(v, k)
        if data:
            with open(FILES[k], 'w', encoding='utf-8') as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
    print("\n=== 更新完成 ===")

if __name__ == "__main__":
    main()

            # --- 執行腳本 (一鍵提取) ---
            #以後您想更新地址時，只需要做這一步：
            #在 VS Code 下方的終端機輸入：
            #python sf_scraper.py
            


import requests
import threading
import time

BASE_URL = "http://127.0.0.1:8000/api"
PRODUCT_ID = 1
TOTAL_STOCK = 100
THREADS = 150  # Try to oversell

def create_hold():
    try:
        response = requests.post(f"{BASE_URL}/holds", json={"product_id": PRODUCT_ID, "qty": 1})
        return response.status_code, response.json()
    except Exception as e:
        return 500, str(e)

def worker(results):
    status, data = create_hold()
    results.append((status, data))

def main():
    print("Starting Concurrency Test...")
    
    # Reset stock (optional, assuming fresh DB or just checking remaining)
    # But we can't easily reset via API. We assume stock is 100.
    
    threads = []
    results = []
    
    start_time = time.time()
    
    for _ in range(THREADS):
        t = threading.Thread(target=worker, args=(results,))
        threads.append(t)
        t.start()
        
    for t in threads:
        t.join()
        
    end_time = time.time()
    print(f"Finished in {end_time - start_time:.2f} seconds")
    
    success_count = sum(1 for s, d in results if s == 201)
    fail_count = sum(1 for s, d in results if s != 201)
    
    print(f"Total Requests: {THREADS}")
    print(f"Successful Holds: {success_count}")
    print(f"Failed Requests: {fail_count}")
    
    # Verify stock
    try:
        resp = requests.get(f"{BASE_URL}/products/{PRODUCT_ID}")
        print(f"Product State Status: {resp.status_code}")
        if resp.status_code != 200:
            print("Error response:", resp.text)
        
        product_data = resp.json()
        print("Product State:", product_data)
        
        stock_remaining = product_data['stock_remaining'] # Assuming this field exists in response
        # Actually my controller returns 'available_stock'
        available_stock = product_data.get('available_stock', product_data.get('stock_remaining'))
        
        print(f"Available Stock: {available_stock}")
        
        # Check if oversold
        # Initial 100. Sold/Held = success_count.
        # Remaining should be 100 - success_count.
        # If success_count > 100, we oversold.
        
        if success_count > TOTAL_STOCK:
             print(f"FAIL: Overselling detected! Sold/Held: {success_count}, Max: {TOTAL_STOCK}")
        elif available_stock != (TOTAL_STOCK - success_count):
             print(f"FAIL: Stock mismatch! Expected: {TOTAL_STOCK - success_count}, Got: {available_stock}")
        else:
             print("PASS: No overselling detected and stock is correct.")
             
    except Exception as e:
        print(f"Failed to verify stock: {e}")
        if 'resp' in locals():
            print(resp.text)

if __name__ == "__main__":
    main()

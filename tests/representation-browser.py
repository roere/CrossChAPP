#!/usr/bin/env python3
import json
import time
import urllib.request

BASE = "http://127.0.0.1:9516"

def request(method, path, payload=None):
    data = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(BASE + path, data=data, method=method, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req) as response:
        return json.loads(response.read())["value"]

session = request("POST", "/session", {"capabilities": {"alwaysMatch": {"browserName": "chrome", "goog:chromeOptions": {"args": ["--headless", "--no-sandbox", "--disable-gpu", "--window-size=1440,1000"]}, "goog:loggingPrefs": {"browser": "ALL", "performance": "ALL"}}}})["sessionId"]

def script(source):
    return request("POST", f"/session/{session}/execute/sync", {"script": source, "args": []})

def title_metrics():
    return script("""const e=document.querySelector('.page-intro-title'),s=getComputedStyle(e),r=e.getBoundingClientRect();return {text:e.textContent.trim(),top:r.top,fontFamily:s.fontFamily,fontSize:s.fontSize,fontWeight:s.fontWeight,color:s.color,marginTop:s.marginTop,marginBottom:s.marginBottom};""")

try:
    request("POST", f"/session/{session}/url", {"url": "http://localhost:8082/?view=crosschaptern"})
    time.sleep(.3)
    cross = title_metrics()

    request("POST", f"/session/{session}/url", {"url": "http://localhost:8082/?view=vertretung"})
    time.sleep(.3)
    request("POST", f"/session/{session}/log", {"type": "performance"})
    offer = title_metrics()
    anonymous = script("return {hint:document.querySelector('.representation-access-hint p')?.textContent.trim(),login:document.querySelector('.representation-access-hint a')?.textContent.trim(),fullUi:!!document.querySelector('.representation-filters, .representation-dates, #representation-list, #save-representation-offer, #my-representation-offers')};")
    assert anonymous == {"hint": "Um Vertretungsangebote zu machen musst du angemeldet sein.", "login": "Anmelden", "fullUi": False}, anonymous
    performance = request("POST", f"/session/{session}/log", {"type": "performance"})
    assert not [entry for entry in performance if "/api/representation/" in entry.get("message", "")], performance

    request("POST", f"/session/{session}/url", {"url": "http://localhost:8082/?view=vertretung-finden"})
    time.sleep(.3)
    find = title_metrics()
    assert script("return document.querySelector('.page-intro-title').nextElementSibling.classList.contains('representation-access-hint');")
    assert not [entry for entry in request("POST", f"/session/{session}/log", {"type": "performance"}) if "/api/representation/find.php" in entry.get("message", "")]

    assert cross["text"] == "Finde passende BNI-Chaptertreffen in deiner Nähe."
    assert offer["text"] == "Vertretung anbieten"
    assert find["text"] == "Vertretung für Dein Chapter finden"
    comparable = ("fontFamily", "fontSize", "fontWeight", "color", "marginTop", "marginBottom")
    assert all(cross[key] == offer[key] == find[key] for key in comparable), (cross, offer, find)
    assert max(cross["top"], offer["top"], find["top"]) - min(cross["top"], offer["top"], find["top"]) < 1, (cross, offer, find)

    request("POST", f"/session/{session}/window/rect", {"width": 390, "height": 844})
    for view in ("crosschaptern", "vertretung", "vertretung-finden"):
        request("POST", f"/session/{session}/url", {"url": f"http://localhost:8082/?view={view}"})
        time.sleep(.15)
        assert script("return document.documentElement.scrollWidth<=document.documentElement.clientWidth;")
    logs = request("POST", f"/session/{session}/log", {"type": "browser"})
    assert not [entry for entry in logs if entry.get("level") == "SEVERE"], logs
    print("PASS Chromium: einheitliche Seitentitel und anonyme Vertretung-anbieten-Sperre ohne API-Aufruf")
finally:
    request("DELETE", f"/session/{session}")
